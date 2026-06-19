<?php
/**
 * notifications_data.php — Notifications data endpoint
 *
 * Returns unread notification data for the logged-in user as JSON.
 * Used by the navbar badge and the notifications panel.
 *
 * Request:
 *   GET notifications_data.php?csrf=<token>
 *   GET notifications_data.php?csrf=<token>&action=mark_read&id=<N>
 *
 * Responses:
 *
 *   Default (list):
 *   {
 *     "ok": true,
 *     "unread_count": 2,
 *     "notifications": [
 *       {
 *         "id":          42,
 *         "type":        "partner_offer",
 *         "from_uuid":   "xxxxxxxx-...",
 *         "from_name":   "Alice Avatar",
 *         "message":     "Alice Avatar has offered you a partnership.",
 *         "created_at":  1718000000
 *       },
 *       ...
 *     ]
 *   }
 *
 *   mark_read (marks a single notification as read, returns updated count):
 *   { "ok": true, "unread_count": 1 }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

session_start_secure();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$supplied_csrf = trim($_GET['csrf'] ?? '');
$session_csrf  = $_SESSION['_csrf_token'] ?? '';

if ($supplied_csrf === '' || !hash_equals($session_csrf, $supplied_csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
}

$session_user = get_session_user();
$my_uuid      = $session_user['uuid'];
$action       = trim($_GET['action'] ?? '');

try {
    $db = get_db();

    // ── mark_read ────────────────────────────────────────────────────────────
    if ($action === 'mark_read') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare(
                "UPDATE portal_notifications SET is_read = 1
                 WHERE id = ? AND to_uuid = ?"
            );
            $stmt->execute([$id, $my_uuid]);
        }

        // Return updated unread count
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM portal_notifications
             WHERE to_uuid = ? AND is_read = 0"
        );
        $stmt->execute([$my_uuid]);
        $count = (int)$stmt->fetchColumn();

        echo json_encode(['ok' => true, 'unread_count' => $count]);
        exit;
    }

    // ── Default: return notification list ────────────────────────────────────
    $stmt = $db->prepare(
        "SELECT id, from_uuid, type, payload, created_at
         FROM portal_notifications
         WHERE to_uuid = ? AND is_read = 0
         ORDER BY created_at DESC
         LIMIT 20"
    );
    $stmt->execute([$my_uuid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = [];
    foreach ($rows as $row) {
        $payload_data = json_decode($row['payload'] ?? '{}', true) ?? [];

        // Build a human-readable message per type
        $message = match ($row['type']) {
            'partner_offer' => ($payload_data['from_name'] ?? 'Someone')
                             . ' has offered you a partnership.',
            'system'        => $payload_data['message'] ?? 'You have a new notification.',
            default         => 'You have a new notification.',
        };

        $notifications[] = [
            'id'         => (int)$row['id'],
            'type'       => $row['type'],
            'from_uuid'  => $row['from_uuid'],
            'from_name'  => $payload_data['from_name'] ?? null,
            'message'    => $message,
            'created_at' => strtotime($row['created_at']),
        ];
    }

    echo json_encode([
        'ok'           => true,
        'unread_count' => count($notifications),
        'notifications'=> $notifications,
    ]);

} catch (Throwable $e) {
    error_log('notifications_data.php error for ' . $my_uuid . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load notifications.']);
}
