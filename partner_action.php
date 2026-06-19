<?php
/**
 * partner_action.php — Partnership action endpoint
 *
 * Handles all partnership state changes via POST requests.
 * All actions are CSRF-protected and require an active session.
 *
 * Actions (POST parameter: action):
 *
 *   offer    — Send a partnership offer to another local user.
 *   accept   — Accept a pending partnership offer.
 *   decline  — Decline a pending partnership offer.
 *   dissolve — Dissolve the logged-in user's current partnership.
 *
 * Response (JSON):
 *   { "ok": true }
 *   { "ok": false, "error": "Human-readable message" }
 *
 * ─── Write boundary exceptions (documented) ─────────────────────────────────
 *
 *   1. UPDATE on userprofile.profilePartner — no ROBUST XMLRPC endpoint exists
 *      for setting a partner.
 *
 *   2. INSERT on im_offline (when PARTNER_INWORLD_NOTIFY = true) — used to
 *      deliver in-world messages. Messages are sent FROM the grid robot account
 *      (GRID_ROBOT_UUID in config.php) using a fixed imSessionID
 *      (GRID_ROBOT_SESSION_UUID) so all portal-generated IMs always appear in
 *      the same "GRID SERVICES" conversation tab in the viewer, with no risk
 *      of collision with real user IMs.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

session_start_secure();

// ─── Auth ─────────────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

// ─── Feature flag ─────────────────────────────────────────────────────────────
if (!defined('ENABLE_PARTNERSHIPS') || !ENABLE_PARTNERSHIPS) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Partnerships are not enabled on this grid.']);
    exit;
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────
$supplied_csrf = trim($_POST['csrf'] ?? '');
$session_csrf  = $_SESSION['_csrf_token'] ?? '';

if ($supplied_csrf === '' || !hash_equals($session_csrf, $supplied_csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
}

// ─── Route ────────────────────────────────────────────────────────────────────
$action      = trim($_POST['action'] ?? '');
$session_user = get_session_user();
$my_uuid      = $session_user['uuid'];

try {
    $db = get_db();

    match ($action) {
        'offer'   => action_offer($db, $my_uuid),
        'accept'  => action_accept($db, $my_uuid),
        'decline' => action_decline($db, $my_uuid),
        'dissolve'=> action_dissolve($db, $my_uuid, $session_user),
        default   => json_fail('Unknown action.'),
    };

} catch (Throwable $e) {
    error_log('partner_action.php error (action=' . $action . ', user=' . $my_uuid . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'An unexpected error occurred. Please try again.']);
    exit;
}


// ─── Action: offer ────────────────────────────────────────────────────────────

function action_offer(PDO $db, string $my_uuid): void
{
    $target_uuid = trim($_POST['target_uuid'] ?? '');

    if (!is_valid_uuid($target_uuid)) {
        json_fail('Invalid target UUID.');
    }
    if ($target_uuid === $my_uuid) {
        json_fail('You cannot offer a partnership to yourself.');
    }

    // Verify target is a local user (not hypergrid)
    $stmt = $db->prepare('SELECT PrincipalID FROM UserAccounts WHERE PrincipalID = ? LIMIT 1');
    $stmt->execute([$target_uuid]);
    if (!$stmt->fetch()) {
        json_fail('That user was not found on this grid.');
    }

    // Check neither party already has a partner
    $stmt = $db->prepare(
        "SELECT profilePartner FROM userprofile
         WHERE useruuid IN (?, ?)
         AND profilePartner != '00000000-0000-0000-0000-000000000000'"
    );
    $stmt->execute([$my_uuid, $target_uuid]);
    if ($stmt->fetch()) {
        json_fail('One or both users already have a partner.');
    }

    // Duplicate-offer suppression: silently succeed if offer already exists
    // (the button should not have been rendered, but guard against race conditions)
    $stmt = $db->prepare(
        "SELECT id FROM portal_notifications
         WHERE from_uuid = ? AND to_uuid = ? AND type = 'partner_offer' AND is_read = 0
         LIMIT 1"
    );
    $stmt->execute([$my_uuid, $target_uuid]);
    if ($stmt->fetch()) {
        // Already pending — return success silently
        echo json_encode(['ok' => true]);
        exit;
    }

    // Resolve our own name for the notification payload
    $stmt = $db->prepare('SELECT FirstName, LastName FROM UserAccounts WHERE PrincipalID = ? LIMIT 1');
    $stmt->execute([$my_uuid]);
    $row = $stmt->fetch();
    $from_name = $row ? trim($row['FirstName'] . ' ' . $row['LastName']) : 'Someone';

    $payload = json_encode(['from_name' => $from_name]);

    $stmt = $db->prepare(
        "INSERT INTO portal_notifications (to_uuid, from_uuid, type, payload, is_read)
         VALUES (?, ?, 'partner_offer', ?, 0)"
    );
    $stmt->execute([$target_uuid, $my_uuid, $payload]);

    echo json_encode(['ok' => true]);
    exit;
}


// ─── Action: accept ───────────────────────────────────────────────────────────

function action_accept(PDO $db, string $my_uuid): void
{
    $notification_id = (int)($_POST['notification_id'] ?? 0);

    if ($notification_id <= 0) {
        json_fail('Invalid notification ID.');
    }

    // Load the notification — must be addressed to THIS user
    $stmt = $db->prepare(
        "SELECT id, from_uuid, payload FROM portal_notifications
         WHERE id = ? AND to_uuid = ? AND type = 'partner_offer' AND is_read = 0
         LIMIT 1"
    );
    $stmt->execute([$notification_id, $my_uuid]);
    $notif = $stmt->fetch();

    if (!$notif) {
        json_fail('Partnership offer not found or already actioned.');
    }

    $offerer_uuid = $notif['from_uuid'];
    $payload_data = json_decode($notif['payload'] ?? '{}', true);
    $offerer_name = $payload_data['from_name'] ?? 'Unknown';

    // Resolve recipient's own name for in-world messages
    $stmt = $db->prepare('SELECT FirstName, LastName FROM UserAccounts WHERE PrincipalID = ? LIMIT 1');
    $stmt->execute([$my_uuid]);
    $myrow = $stmt->fetch();
    $my_name = $myrow ? trim($myrow['FirstName'] . ' ' . $myrow['LastName']) : 'Unknown';

    // Final safety check: neither party should currently have a partner
    $stmt = $db->prepare(
        "SELECT useruuid, profilePartner FROM userprofile
         WHERE useruuid IN (?, ?)"
    );
    $stmt->execute([$my_uuid, $offerer_uuid]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        if ($r['profilePartner'] !== '00000000-0000-0000-0000-000000000000') {
            json_fail('One or both users already have a partner. The offer may be stale.');
        }
    }

    // Write partnership in a transaction
    $db->beginTransaction();
    try {
        // Set partnership on both profiles
        $stmt = $db->prepare(
            "UPDATE userprofile SET profilePartner = ? WHERE useruuid = ?"
        );
        $stmt->execute([$offerer_uuid, $my_uuid]);
        $stmt->execute([$my_uuid, $offerer_uuid]);

        // Mark notification as read
        $stmt = $db->prepare("UPDATE portal_notifications SET is_read = 1 WHERE id = ?");
        $stmt->execute([$notification_id]);

        // Also mark any reverse offer (target→offerer) as read if one exists
        $stmt = $db->prepare(
            "UPDATE portal_notifications SET is_read = 1
             WHERE from_uuid = ? AND to_uuid = ? AND type = 'partner_offer'"
        );
        $stmt->execute([$my_uuid, $offerer_uuid]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Optional in-world IM via im_offline
    if (defined('PARTNER_INWORLD_NOTIFY') && PARTNER_INWORLD_NOTIFY) {
        try {
            send_offline_im($db, $my_uuid,      "We are now partners. Congratulations!");
            send_offline_im($db, $offerer_uuid, "We are now partners. Congratulations!");
        } catch (Throwable $e) {
            error_log('partner_action accept: im_offline failed: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}


// ─── Action: decline ──────────────────────────────────────────────────────────

function action_decline(PDO $db, string $my_uuid): void
{
    $notification_id = (int)($_POST['notification_id'] ?? 0);

    if ($notification_id <= 0) {
        json_fail('Invalid notification ID.');
    }

    // Verify this notification belongs to the current user
    $stmt = $db->prepare(
        "DELETE FROM portal_notifications
         WHERE id = ? AND to_uuid = ? AND type = 'partner_offer'"
    );
    $stmt->execute([$notification_id, $my_uuid]);

    if ($stmt->rowCount() === 0) {
        json_fail('Partnership offer not found.');
    }

    echo json_encode(['ok' => true]);
    exit;
}


// ─── Action: dissolve ─────────────────────────────────────────────────────────

function action_dissolve(PDO $db, string $my_uuid, array $session_user): void
{
    // Load current partner UUID from our own profile
    $stmt = $db->prepare(
        "SELECT profilePartner FROM userprofile WHERE useruuid = ? LIMIT 1"
    );
    $stmt->execute([$my_uuid]);
    $row = $stmt->fetch();

    if (!$row || $row['profilePartner'] === '00000000-0000-0000-0000-000000000000') {
        json_fail('You do not currently have a partner to dissolve.');
    }

    $partner_uuid = $row['profilePartner'];

    // Resolve names for in-world messages and log
    $stmt = $db->prepare(
        'SELECT PrincipalID, FirstName, LastName
         FROM UserAccounts WHERE PrincipalID IN (?, ?)'
    );
    $stmt->execute([$my_uuid, $partner_uuid]);
    $name_rows = $stmt->fetchAll();
    $names = [];
    foreach ($name_rows as $nr) {
        $names[$nr['PrincipalID']] = trim($nr['FirstName'] . ' ' . $nr['LastName']);
    }
    $my_name      = $names[$my_uuid]      ?? 'Unknown';
    $partner_name = $names[$partner_uuid] ?? 'Unknown';

    // Clear both partnership fields in a transaction
    $db->beginTransaction();
    try {
        $null_uuid = '00000000-0000-0000-0000-000000000000';
        $stmt = $db->prepare(
            "UPDATE userprofile SET profilePartner = ? WHERE useruuid = ?"
        );
        $stmt->execute([$null_uuid, $my_uuid]);
        $stmt->execute([$null_uuid, $partner_uuid]);

        // Clean up any stale partner_offer notifications between these two users
        $stmt = $db->prepare(
            "DELETE FROM portal_notifications
             WHERE type = 'partner_offer'
             AND ((from_uuid = ? AND to_uuid = ?)
                OR (from_uuid = ? AND to_uuid = ?))"
        );
        $stmt->execute([$my_uuid, $partner_uuid, $partner_uuid, $my_uuid]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Log to portal_log if the table exists
    try {
        $stmt = $db->prepare(
            "INSERT INTO portal_log (actor_uuid, action, detail, created_at)
             VALUES (?, 'partner_dissolve', ?, NOW())"
        );
        $detail = json_encode([
            'dissolved_by' => $my_uuid,
            'partner'      => $partner_uuid,
        ]);
        $stmt->execute([$my_uuid, $detail]);
    } catch (Throwable) {
        // portal_log table not yet created — skip silently
    }

    // Portal notifications: inform both parties via the badge system
    try {
        $portal_name = defined('GRID_NAME') ? GRID_NAME . ' Portal' : 'Portal';

        // Notify the partner that the partnership was dissolved
        $stmt = $db->prepare(
            "INSERT INTO portal_notifications (to_uuid, from_uuid, type, payload, is_read)
             VALUES (?, ?, 'system', ?, 0)"
        );
        $payload = json_encode([
            'message' => "{$my_name} has dissolved your partnership.",
        ]);
        $stmt->execute([$partner_uuid, $my_uuid, $payload]);

    } catch (Throwable $e) {
        error_log('partner_action dissolve: portal notification write failed: ' . $e->getMessage());
    }

    // Optional in-world IM via im_offline
    if (defined('PARTNER_INWORLD_NOTIFY') && PARTNER_INWORLD_NOTIFY) {
        try {
            send_offline_im($db, $my_uuid,      "Your partnership with {$partner_name} has been dissolved.");
            send_offline_im($db, $partner_uuid, "{$my_name} has dissolved your partnership.");
        } catch (Throwable $e) {
            error_log('partner_action dissolve: im_offline failed: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true, 'partner_name' => $partner_name]);
    exit;
}


// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Validate a UUID string.
 */
function is_valid_uuid(string $uuid): bool
{
    return (bool)preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        $uuid
    );
}

/**
 * Emit a JSON error response and exit.
 */
function json_fail(string $message, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

// send_offline_im() now lives in includes/helpers.php — it dispatches to
// either the im_offline database write or the in-world relay object,
// depending on the INWORLD_MESSAGING config constant.
