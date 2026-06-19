<?php
/**
 * console_oneshot.php — REST Console: run one command, return its output
 *
 * The portal's entire REST console UI (both the preset "Quick Commands"
 * buttons and the free-text command box in the console modal) goes through
 * this single endpoint. Each call does the FULL StartSession ->
 * SessionCommand -> ReadResponses -> CloseSession cycle in one request and
 * leaves nothing open afterwards — no stored session, no polling loop. See
 * includes/rest_console.php — restconsole_run_once().
 *
 * ── History ──────────────────────────────────────────────────────────────
 * An earlier version kept a long-lived session open (console_session.php /
 * console_command.php, with $_SESSION['_console_sessions']) and polled
 * ReadResponses in a loop for a live, two-way console. This was found to
 * trigger a recurring "NullReferenceException ... PoolWorkerJob(Object o)"
 * in the simulator's poll-service log for as long as the session stayed
 * open — roughly once per ~25-30s poll cycle, regardless of how the polling
 * was paced. In practice, every command Tim needed (show uptime, show users,
 * show region, etc.) is a single request/response anyway — a one-shot
 * command triggers at most one such cycle per click, with nothing left open,
 * and behaves identically from the user's point of view. The interactive
 * session approach was removed entirely; console_session.php and
 * console_command.php no longer exist.
 *
 * Request:
 *   POST console_oneshot.php
 *     target  = '<region UUID>' | 'robust'
 *     command = '...'   (e.g. 'show uptime')
 *     csrf    = <token>
 *
 * Response (JSON):
 *   { "ok": true,  "html": "<span class=...>...</span>\n..." }
 *   { "ok": false, "error": "..." }
 *
 * ── Access ───────────────────────────────────────────────────────────────
 * Requires meeting the 'Administrator' tier (see USERLEVEL_LABELS /
 * user_level_meets() in config.php) — see RestConsole.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/rest_console.php';

// ─── Always return JSON ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ─── Require login ──────────────────────────────────────────────────────────
session_start_secure();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

// ─── Method check ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ─── CSRF check ──────────────────────────────────────────────────────────────
$supplied_csrf = trim($_POST['csrf'] ?? '');
$session_csrf  = $_SESSION['_csrf_token'] ?? '';

if ($supplied_csrf === '' || !hash_equals($session_csrf, $supplied_csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
}

// ─── Access control: meeting the 'Administrator' tier ──────────────────────
$session_user = get_session_user();
$userlevel    = (int)($session_user['userlevel'] ?? 0);

if (!user_level_meets($userlevel, 'Administrator')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to use the console.']);
    exit;
}

// ─── Feature gate ────────────────────────────────────────────────────────────
if (!restconsole_enabled()) {
    echo json_encode(['ok' => false, 'error' => 'The REST console is not enabled on this portal.']);
    exit;
}

// ─── Parse request ───────────────────────────────────────────────────────────
$target  = trim($_POST['target'] ?? '');
$command = trim($_POST['command'] ?? '');

if ($target === '' || $command === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A target and command are required.']);
    exit;
}

try {
    $db = get_db();

    if ($target === 'robust') {
        $port = (int)ROBUST_PRIVATE_PORT;
        $host = defined('CONSOLE_ROBUST_HOST') && CONSOLE_ROBUST_HOST !== ''
              ? CONSOLE_ROBUST_HOST
              : (defined('ROBUST_HOST') ? ROBUST_HOST : CONSOLE_HOST);
    } else {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $target)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid target.']);
            exit;
        }

        $stmt = $db->prepare('SELECT serverPort FROM regions WHERE uuid = ? LIMIT 1');
        $stmt->execute([$target]);
        $region = $stmt->fetch();

        if ($region === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Region not found.']);
            exit;
        }

        $port = (int)$region['serverPort'];
        $host = CONSOLE_HOST;

        if ($port <= 0) {
            echo json_encode(['ok' => false, 'error' => 'This region has no configured server port.']);
            exit;
        }
    }

    $result = restconsole_run_once($port, $command, $host);

    if (!$result['success']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Could not run command.']);
        exit;
    }

    $html = '';
    foreach ($result['lines'] ?? [] as $line) {
        $html .= restconsole_render_line($line) . "\n";
    }

    echo json_encode(['ok' => true, 'html' => $html]);

} catch (Throwable $e) {
    error_log('console_oneshot.php error for target ' . $target . ' (' . $command . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Console request failed. Please try again.']);
}
