<?php
/**
 * region_stats_data.php — Live region statistics endpoint
 *
 * Returns live FPS / agent counts / memory / uptime etc. for a region, via
 * its simulator's built-in `/jsonSimStats` endpoint (see
 * includes/region_stats.php). Called from the "My Estates" region detail
 * modal (regions.php) — a "Region Stats" section below the main info panel,
 * plus an uptime summary shown alongside the region name.
 *
 * Request:
 *   GET region_stats_data.php?uuid=<region UUID>&csrf=<token>
 *
 * Response (JSON):
 *   { "ok": true, "stats": {...raw jsonSimStats fields...}, "uptime": "8 days, 10 hours, 30 mins" }
 *   { "ok": false, "error": "..." }
 *
 * ── This is NOT the same as region_status.php ───────────────────────────────
 * region_status.php's online/offline check is a separate, established
 * feature and is NOT replaced by this endpoint. /jsonSimStats availability
 * has not been verified across all OpenSim versions/grids — a failed fetch
 * here means "stats unavailable for this region", not "region offline".
 * The two checks are independent and both run when the modal opens.
 *
 * ── Access ─────────────────────────────────────────────────────────────────
 * Same as region_status.php: requires an active session AND that the user
 * owns or manages the estate the region belongs to, OR meets the
 * 'Grid Staff' tier — see user_can_manage_region_maturity() in
 * includes/helpers.php (reused here unchanged). Note /jsonSimStats itself
 * is unauthenticated on the simulator side — anyone who knows a region's
 * serverIP:serverPort can already query it directly — so this gate is
 * about portal access, not protecting the underlying data.
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

    $result = region_stats_fetch($ip, $port);

    if (!$result['success']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Stats unavailable for this region.']);
        exit;
    }

    $stats  = $result['stats'];
    $uptime = isset($stats['Uptime']) ? region_stats_format_uptime((string)$stats['Uptime']) : '';

    echo json_encode(['ok' => true, 'stats' => $stats, 'uptime' => $uptime]);

} catch (Throwable $e) {
    error_log('region_stats_data.php error for region UUID ' . $region_uuid . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not fetch region stats. Please try again.']);
}
