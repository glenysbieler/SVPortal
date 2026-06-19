<?php
/**
 * region_restart.php — Region restart endpoint
 *
 * Restarts a region from the "My Estates" region detail modal (regions.php).
 *
 * ── What "restart" actually does ────────────────────────────────────────────
 * RemoteAdmin has no method to START a simulator — only `admin_shutdown_region`
 * to stop one. This endpoint therefore issues a SHUTDOWN with a short delay,
 * on the assumption that monit (or another process supervisor) is watching
 * each simulator process and will relaunch it automatically shortly after it
 * exits. See includes/remoteadmin.php — remoteadmin_restart_region().
 *
 * The delay is also how long agents in the region see an in-world restart
 * warning/notice before the shutdown happens (this comes from RemoteAdmin's
 * `admin_restart` handler, which both schedules the shutdown and broadcasts
 * the notice using the same delay value). The UI offers two choices, both of
 * which always show an in-world alert (there is no truly silent option — a
 * genuine 0-second delay causes OpenSim to tear down the simulator's HTTP
 * listener before the XML-RPC response can be sent back, which curl reports
 * as a connection error even though the restart succeeded; 10 seconds is
 * the shortest delay that reliably avoids this):
 *
 *   - delay = 60 -> agents get a ~1 minute warning before disconnect
 *   - delay = 10 -> agents get a ~10 second warning before disconnect
 *
 * Request:
 *   POST region_restart.php
 *     uuid  = <region UUID>
 *     csrf  = <token>
 *     delay = "60" | "10"   (optional, default "60")
 *
 * Response (JSON):
 *   { "ok": true }
 *   { "ok": false, "error": "...", "not_enabled": true|false }
 *
 * ── Per-region availability ─────────────────────────────────────────────────
 * RemoteAdmin is being rolled out region-by-region — at the time of writing
 * only a handful of this grid's simulators have it enabled. This endpoint
 * works the same for every region (it always targets that region's own
 * `serverPort`, same as region_status.php) and degrades gracefully where
 * RemoteAdmin isn't enabled yet:
 *
 *   - Simulator up, RemoteAdmin enabled & supports admin_shutdown_region
 *     -> shutdown issued, { "ok": true }
 *   - Simulator up, RemoteAdmin not enabled (or method not in
 *     enabled_methods) -> dispatcher returns a -32601 "method not found"
 *     fault, detected by remoteadmin_is_method_not_found() and reported as
 *     { "ok": false, "not_enabled": true, "error": "..." }
 *   - Simulator process not running at all -> connection failure,
 *     { "ok": false, "error": "Could not connect to simulator." }
 *
 * ── Access ─────────────────────────────────────────────────────────────────
 * Requires an active session AND that the user owns or manages the estate
 * the region belongs to, OR meets the 'Grid Staff' tier — see
 * user_can_manage_region_maturity() in includes/helpers.php (reused here
 * unchanged). The Grid Staff branch is what makes this endpoint work from
 * the "All Estates" tool (all_estates.php), where the viewing Grid Staff/
 * Administrator user may not own/manage every estate shown.
 *
 * Per Things_to_do.md: region restart is considered low-risk (the simulator
 * process keeps running / is expected to come straight back via the
 * supervisor) and so is available to estate managers, not just admins.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/estates.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/remoteadmin.php';

// ─── Always return JSON ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ─── Require login ──────────────────────────────────────────────────────────
session_start_secure();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

// ─── Method check ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ─── CSRF check ─────────────────────────────────────────────────────────────
$supplied_csrf = trim($_POST['csrf'] ?? '');
$session_csrf  = $_SESSION['_csrf_token'] ?? '';

if ($supplied_csrf === '' || !hash_equals($session_csrf, $supplied_csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
}

// ─── Validate UUID ──────────────────────────────────────────────────────────
$region_uuid = trim($_POST['uuid'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $region_uuid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing region UUID.']);
    exit;
}

// ─── Validate restart delay ─────────────────────────────────────────────────
// Only two options are offered in the UI: a 60-second warning, or a
// 10-second warning. 10 seconds (not 0/instant) is the shortest delay that
// reliably avoids OpenSim tearing down the connection before the XML-RPC
// response is sent back — see the docblock above. Reject anything else
// rather than passing arbitrary client input through to RemoteAdmin.
$delay = isset($_POST['delay']) ? trim((string)$_POST['delay']) : '60';

if (!in_array($delay, ['10', '60'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid restart delay.']);
    exit;
}

$delay = (int)$delay;

try {
    $db           = get_db();
    $session_user = get_session_user();
    $userlevel    = (int)($session_user['userlevel'] ?? 0);

    // ─── Access check ────────────────────────────────────────────────────
    if (!user_can_manage_region_maturity($db, $session_user['uuid'], $userlevel, $region_uuid)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have access to this region.']);
        exit;
    }

    // ─── Look up the region's name and RemoteAdmin port ─────────────────
    $stmt = $db->prepare('SELECT regionName, serverPort FROM regions WHERE uuid = ? LIMIT 1');
    $stmt->execute([$region_uuid]);
    $region = $stmt->fetch();

    if ($region === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Region not found.']);
        exit;
    }

    $port        = (int)$region['serverPort'];
    $region_name = (string)$region['regionName'];

    if (!remoteadmin_enabled()) {
        echo json_encode([
            'ok'    => false,
            'error' => 'RemoteAdmin is not configured on this portal.',
        ]);
        exit;
    }

    if ($port <= 0) {
        echo json_encode([
            'ok'    => false,
            'error' => 'This region has no configured server port.',
        ]);
        exit;
    }

    // ─── Issue the restart (shutdown — monit relaunches) ────────────────
    $result = remoteadmin_restart_region($port, $region_name, 'The region is restarting.', $delay);

    if ($result['success']) {
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode([
        'ok'          => false,
        'error'       => $result['error'] ?? 'Could not restart the region.',
        'not_enabled' => $result['not_enabled'] ?? false,
    ]);

} catch (Throwable $e) {
    error_log('region_restart.php error for region UUID ' . $region_uuid . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not restart the region. Please try again.']);
}
