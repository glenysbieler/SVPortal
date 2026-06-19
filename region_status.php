<?php
/**
 * region_status.php — Region online/offline status endpoint
 *
 * Returns whether a region's simulator process is currently up, for the
 * "My Estates" region detail modal (regions.php). Called once when the
 * modal opens — NOT pre-fetched for the whole region list, since each
 * check is a live network round-trip to that region's simulator.
 *
 * Request:
 *   GET region_status.php?uuid=<region UUID>&csrf=<token>
 *
 * Response (JSON):
 *   { "ok": true, "online": true|false }
 *   { "ok": false, "error": "..." }
 *
 * ── Status determination ────────────────────────────────────────────────────
 * Uses the simulator's built-in `/jsonSimStats` endpoint (see
 * includes/region_stats.php — region_stats_fetch(), also used by
 * region_stats_data.php for the "Region Stats" panel) against the region's
 * own externally-reachable address (`regions.serverIP` : `regions.serverPort`):
 *
 *   - A successful response (HTTP 200, valid JSON) -> "online".
 *   - A connection failure (cURL error, refused, timeout) -> "offline".
 *
 * This is a plain, unauthenticated HTTP GET — no RemoteAdmin/XMLRPC
 * dependency, no portal-level config required, and works the same for every
 * region regardless of whether RemoteAdmin is enabled on it.
 *
 * Replaces an earlier RemoteAdmin-based (`admin_region_query`) version of
 * this check, which depended on portal-wide RemoteAdmin configuration
 * (REMOTEADMIN_ENABLED/HOST/PASSWORD) and reported "unknown" rather than a
 * real status if that wasn't set up.
 *
 * ── Access ─────────────────────────────────────────────────────────────────
 * Requires an active session AND that the user owns or manages the estate
 * the region belongs to, OR meets the 'Grid Staff' tier — see
 * user_can_manage_region_maturity() in includes/helpers.php (reused here
 * unchanged; the same tier governs maturity changes and this status check).
 * The Grid Staff branch is what makes this endpoint work from the "All
 * Estates" tool (all_estates.php), where the viewing Grid Staff/
 * Administrator user may not own/manage every estate shown.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/estates.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/region_stats.php';

// ─── Always return JSON ───────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ─── Require login ────────────────────────────────────────────────────────────
session_start_secure();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

// ─── CSRF check ───────────────────────────────────────────────────────────────
$supplied_csrf = trim($_GET['csrf'] ?? '');
$session_csrf  = $_SESSION['_csrf_token'] ?? '';

if ($supplied_csrf === '' || !hash_equals($session_csrf, $supplied_csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
}

// ─── Validate UUID ────────────────────────────────────────────────────────────
$region_uuid = trim($_GET['uuid'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $region_uuid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing region UUID.']);
    exit;
}

try {
    $db           = get_db();
    $session_user = get_session_user();
    $userlevel    = (int)($session_user['userlevel'] ?? 0);

    // ─── Access check ───────────────────────────────────────────────────────
    if (!user_can_manage_region_maturity($db, $session_user['uuid'], $userlevel, $region_uuid)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have access to this region.']);
        exit;
    }

    // ─── Look up the region's external address ─────────────────────────────
    $stmt = $db->prepare('SELECT serverIP, serverPort FROM regions WHERE uuid = ? LIMIT 1');
    $stmt->execute([$region_uuid]);
    $region = $stmt->fetch();

    if ($region === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Region not found.']);
        exit;
    }

    $ip   = (string)($region['serverIP'] ?? '');
    $port = (int)($region['serverPort'] ?? 0);

    // ─── Online/offline via /jsonSimStats ───────────────────────────────────
    // A successful response means the simulator process is up ("online").
    // A connection failure (the only failure mode region_stats_fetch()
    // reports for a reachable host with nothing listening, or an
    // unreachable host) means it's not running ("offline").
    $result = region_stats_fetch($ip, $port);

    echo json_encode(['ok' => true, 'online' => $result['success']]);

} catch (Throwable $e) {
    error_log('region_status.php error for region UUID ' . $region_uuid . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not check region status. Please try again.']);
}
