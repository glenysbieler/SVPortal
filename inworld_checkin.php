<?php
/**
 * inworld_checkin.php — Check-in receiver for the optional in-world relay object
 *
 * Public, unauthenticated endpoint (no session). The in-world relay object
 * (see inworld_relay.lsl) calls this once, on startup, to register its
 * current HTTPIN URL with the portal.
 *
 * Authentication is via a shared secret (INWORLD_RELAY_ACCESS_CODE in
 * config.php), checked with hash_equals(). The object reads this code from a
 * notecard inside itself.
 *
 * Expected POST fields (application/x-www-form-urlencoded or multipart —
 * either works with $_POST):
 *
 *   access_code  — must match INWORLD_RELAY_ACCESS_CODE
 *   httpin_url   — the object's current llRequestURL() result
 *   object_uuid  — the object's UUID (llGetKey())
 *   region_name  — (optional) current region name, for the admin's reference
 *
 * Response: plain text "OK" or "FAIL: <reason>" — deliberately simple so the
 * LSL script can check the response with a basic string match rather than
 * parsing JSON.
 *
 * This endpoint only writes to the portal-owned table portal_inworld_relay.
 * No OpenSim-owned tables are touched.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'FAIL: POST required';
    exit;
}

$access_code = (string)($_POST['access_code'] ?? '');
$httpin_url  = trim((string)($_POST['httpin_url'] ?? ''));
$object_uuid = trim((string)($_POST['object_uuid'] ?? ''));
$region_name = trim((string)($_POST['region_name'] ?? ''));

$configured_code = defined('INWORLD_RELAY_ACCESS_CODE') ? INWORLD_RELAY_ACCESS_CODE : '';

if ($configured_code === '' || !hash_equals($configured_code, $access_code)) {
    http_response_code(403);
    echo 'FAIL: access code mismatch';
    exit;
}

if ($httpin_url === '' || !filter_var($httpin_url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo 'FAIL: invalid httpin_url';
    exit;
}

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $object_uuid)) {
    http_response_code(400);
    echo 'FAIL: invalid object_uuid';
    exit;
}

try {
    $db = get_db();

    $stmt = $db->prepare(
        'INSERT INTO portal_inworld_relay (id, httpin_url, object_uuid, region_name, last_checkin)
         VALUES (1, :httpin_url, :object_uuid, :region_name, NOW())
         ON DUPLICATE KEY UPDATE
            httpin_url   = :httpin_url2,
            object_uuid  = :object_uuid2,
            region_name  = :region_name2,
            last_checkin = NOW()'
    );
    $stmt->execute([
        ':httpin_url'    => $httpin_url,
        ':object_uuid'   => $object_uuid,
        ':region_name'   => $region_name !== '' ? $region_name : null,
        ':httpin_url2'   => $httpin_url,
        ':object_uuid2'  => $object_uuid,
        ':region_name2'  => $region_name !== '' ? $region_name : null,
    ]);

    // Optional audit trail
    try {
        $stmt = $db->prepare(
            "INSERT INTO portal_log (actor_uuid, action, detail, created_at)
             VALUES (?, 'inworld_relay_checkin', ?, NOW())"
        );
        $detail = json_encode([
            'object_uuid' => $object_uuid,
            'httpin_url'  => $httpin_url,
            'region_name' => $region_name,
        ]);
        $stmt->execute([$object_uuid, $detail]);
    } catch (Throwable) {
        // portal_log table not present — skip silently
    }

    echo 'OK';

} catch (Throwable $e) {
    error_log('inworld_checkin.php error: ' . $e->getMessage());
    http_response_code(500);
    echo 'FAIL: server error';
}
