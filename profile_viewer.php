<?php
/**
 * profile_viewer.php — Read-only profile data endpoint
 *
 * Returns profile data for any local grid user as JSON, for use by the
 * friend profile modal in friends.php and (later) by the public profile page.
 *
 * Access rules:
 *   - Always requires an active logged-in session.
 *   - The public profile page (future) will have its own separate access path
 *     that checks PUBLIC_PROFILES and portal_prefs.public_profile.
 *
 * Request:
 *   GET profile_viewer.php?uuid=<UUID>&csrf=<token>
 *
 * Response (JSON):
 *   On success:  { "ok": true, "profile": { ... }, "picks": [ ... ] }
 *   On error:    { "ok": false, "error": "..." }
 *
 * Only local UUIDs (plain UUID format) are accepted. Hypergrid URI
 * identifiers are rejected — those users are not stored locally.
 *
 * NOTE: This file is intentionally NOT used by profile.php (the logged-in
 * user's own profile). That page will later gain edit capabilities, so it
 * has its own separate code path.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/helpers.php';

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
$uuid = trim($_GET['uuid'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing UUID.']);
    exit;
}

// ─── Fetch data ───────────────────────────────────────────────────────────────
try {
    $profile = get_user_profile($uuid);
    $picks   = get_user_picks($uuid);

    // Resolve partner name if a partner UUID is set
    $partner_name = null;
    $partner_uuid = $profile['partner_uuid'] ?? '00000000-0000-0000-0000-000000000000';

    if ($partner_uuid !== '00000000-0000-0000-0000-000000000000') {
        $db   = get_db();
        $stmt = $db->prepare('SELECT FirstName, LastName FROM UserAccounts WHERE PrincipalID = ? LIMIT 1');
        $stmt->execute([$partner_uuid]);
        $row = $stmt->fetch();
        if ($row) {
            $partner_name = trim($row['FirstName'] . ' ' . $row['LastName']);
        }
    }

    // Build safe output — never expose email, userlevel internals, etc.
    $out_profile = [
        'uuid'         => $profile['uuid'],
        'fullname'     => trim($profile['firstname'] . ' ' . $profile['lastname']),
        'about'        => $profile['about_text'],
        'created'      => (int)$profile['created'],
        'image_url'    => get_profile_image_url($profile['profile_image_uuid']),
        'partner_uuid' => $partner_uuid,
        'partner_name' => $partner_name,
        'url'          => $profile['url'],
    ];

    // Sanitise picks for output
    $out_picks = array_map(function(array $p): array {
        return [
            'name'        => $p['name'],
            'description' => $p['description'],
            'image_url'   => get_pick_image_url($p['image_uuid']),
            'sim_name'    => $p['sim_name'] ?? '',
            'pos_x'       => (int)$p['pos_x'],
            'pos_y'       => (int)$p['pos_y'],
            'pos_z'       => (int)$p['pos_z'],
            'is_blank'    => (bool)$p['is_blank'],
        ];
    }, $picks);

    echo json_encode([
        'ok'      => true,
        'profile' => $out_profile,
        'picks'   => $out_picks,
    ]);

} catch (Throwable $e) {
    error_log('profile_viewer.php error for UUID ' . $uuid . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load profile. Please try again.']);
}
