<?php
/**
 * region_broadcast.php — Region broadcast message endpoint
 *
 * Sends an in-world alert message to everyone currently in a region, from
 * the "My Estates" region detail modal (regions.php). Uses RemoteAdmin's
 * `admin_broadcast` XMLRPC method — see includes/remoteadmin.php —
 * remoteadmin_broadcast(). There is no REST equivalent; this is the same
 * per-simulator XMLRPC transport used by region_restart.php and every other
 * RemoteAdmin call in this portal.
 *
 * Unlike restart, broadcast has no delay — the message is shown immediately
 * to every avatar currently in the region.
 *
 * Request:
 *   POST region_broadcast.php
 *     uuid    = <region UUID>
 *     csrf    = <token>
 *     message = <text to broadcast>
 *
 * Response (JSON):
 *   { "ok": true }
 *   { "ok": false, "error": "...", "not_enabled": true|false }
 *
 * ── Per-region availability ─────────────────────────────────────────────────
 * Same graceful-degradation behaviour as region_restart.php:
 *
 *   - Simulator up, RemoteAdmin enabled & supports admin_broadcast
 *     -> message sent, { "ok": true }
 *   - Simulator up, RemoteAdmin not enabled (or method not in
 *     enabled_methods) -> dispatcher returns a -32601 "method not found"
 *     fault, detected by remoteadmin_is_method_not_found() and reported as
 *     { "ok": false, "not_enabled": true, "error": "..." }
 *   - Simulator process not running at all -> connection failure,
 *     { "ok": false, "error": "Could not connect to simulator." }
 *
 * ── Multi-region-per-simulator caveat ───────────────────────────────────────
 * Like restart/stats/console, admin_broadcast is bound to the simulator's
 * HTTP listener — process-scoped, not region-scoped. On a shared-process
 * topology, only the first/primary region in that process actually
 * receives the message. See SINGLE_REGION_PER_SIMULATOR in Things_to_do.md.
 *
 * ── Access ─────────────────────────────────────────────────────────────────
 * Requires an active session AND that the user owns or manages the estate
 * the region belongs to, OR meets the 'Grid Staff' tier — see
 * user_can_manage_region_maturity() in includes/helpers.php (reused here
 * unchanged; the same tier governs maturity changes, status/stats, restart,
 * and broadcast). Deliberately NOT restricted to Administrators: anyone who
 * can already see this region's panel on "My Estates" or "All Estates"
 * (estate owner, manager, Grid Staff, or Administrator) can send a message
 * to it — sending an in-world notice carries no destructive risk at all,
 * same reasoning as region restart.
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

// ─── Validate message ───────────────────────────────────────────────────────
// Trim only — RemoteAdmin/OpenSim handles whatever text is sent, but an
// empty message is rejected here rather than relying on
// remoteadmin_broadcast()'s own check, so the user gets a clear 400 instead
// of a generic failure.
$message = trim($_POST['message'] ?? '');

if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message is required.']);
    exit;
}

// Sanity cap — OpenSim's alert dialog isn't designed for long text, and this
// also guards against accidentally pasting something huge into the box.
if (mb_strlen($message) > 512) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message is too long (512 characters maximum).']);
    exit;
}

try {
    $db           = get_db();
    $session_user = get_session_user();
    $userlevel    = (int)($session_user['userlevel'] ?? 0);

    // ─── Access check ────────────────────────────────────────────────────
    // Same gate as region_restart.php / region_status.php — estate owner,
    // manager, or Grid Staff/Administrator tier.
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

    // ─── Send the broadcast ───────────────────────────────────────────
    $result = remoteadmin_broadcast($port, $region_name, $message);

    if ($result['success']) {
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode([
        'ok'          => false,
        'error'       => $result['error'] ?? 'Could not send the message.',
        'not_enabled' => $result['not_enabled'] ?? false,
    ]);

} catch (Throwable $e) {
    error_log('region_broadcast.php error for region UUID ' . $region_uuid . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not send the message. Please try again.']);
}
