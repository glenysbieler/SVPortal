<?php
/**
 * console_command.php — REST Console: send a command / poll for output
 *
 * Two actions, selected by POST `mode`:
 *
 *   mode=send  — send a command on the target's open session
 *   mode=poll  — read any new output lines since the last poll
 *
 * Both require a session previously opened via console_session.php for the
 * same `target` (stored in $_SESSION['_console_sessions'][$target]).
 *
 * ── Session lifecycle ───────────────────────────────────────────────────────
 * If the stored session has expired (the simulator reports a non-"OK"
 * SessionCommand result, or ReadResponses fails outright), this endpoint
 * transparently opens a new session and retries ONCE — per RestConsole.md,
 * "the proxy should handle re-opening a session transparently... rather than
 * surfacing a raw error to the user." If the retry also fails, the error is
 * returned and the frontend is told the session needs to be reopened.
 *
 * Request:
 *   POST console_command.php
 *     target  = '<region UUID>' | 'robust'
 *     mode    = 'send' | 'poll'
 *     command = '...'   (mode=send only)
 *     csrf    = <token>
 *
 * Response (JSON):
 *   mode=send: { "ok": true }
 *   mode=poll: { "ok": true, "html": "<span class=...>...</span>\n..." }
 *   On failure: { "ok": false, "error": "...", "session_lost": true|false }
 *
 * "session_lost": true tells the frontend the stored session is gone even
 * after a retry — it should call console_session.php again before further
 * commands.
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
$target = trim($_POST['target'] ?? '');
$mode   = trim($_POST['mode'] ?? '');

if ($target === '' || !in_array($mode, ['send', 'poll'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

if ($mode === 'send' && trim($_POST['command'] ?? '') === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Command is required.']);
    exit;
}

$command = (string)($_POST['command'] ?? '');

// ─── Must have an open session for this target ─────────────────────────────
$stored = $_SESSION['_console_sessions'][$target] ?? null;

if ($stored === null || empty($stored['session_id'])) {
    echo json_encode(['ok' => false, 'error' => 'No console session is open for this target.', 'session_lost' => true]);
    exit;
}

$port = (int)$stored['port'];
$host = (string)($stored['host'] ?? '');

/**
 * Re-open a session for the current target, replacing the stored one.
 * Returns the new session ID, or null on failure.
 */
$reopen = function () use ($target, $port, $host): ?string {
    $result = restconsole_start_session($port, $host);
    if (!$result['success']) {
        unset($_SESSION['_console_sessions'][$target]);
        return null;
    }
    $_SESSION['_console_sessions'][$target]['session_id'] = $result['session_id'];
    $_SESSION['_console_sessions'][$target]['prompt']     = $result['prompt'] ?? '';
    $_SESSION['_console_sessions'][$target]['opened_at']  = time();
    return $result['session_id'];
};

try {
    $session_id = (string)$stored['session_id'];

    if ($mode === 'send') {
        $result = restconsole_send_command($port, $session_id, $command, $host);

        if (!$result['success'] && !empty($result['session_expired'])) {
            // Transparent re-open + retry once.
            $new_id = $reopen();
            if ($new_id === null) {
                echo json_encode(['ok' => false, 'error' => 'Console session expired and could not be re-opened.', 'session_lost' => true]);
                exit;
            }
            $result = restconsole_send_command($port, $new_id, $command, $host);
        }

        if (!$result['success']) {
            echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Could not send command.', 'session_lost' => !empty($result['session_expired'])]);
            exit;
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // ─── mode === 'poll' ────────────────────────────────────────────────
    $result = restconsole_read_responses($port, $session_id, $host);

    // restconsole_read_responses() only fails on a parse/transport issue we
    // couldn't otherwise smooth over — treat as session loss and retry once.
    if (!$result['success']) {
        $new_id = $reopen();
        if ($new_id === null) {
            echo json_encode(['ok' => false, 'error' => 'Console session lost and could not be re-opened.', 'session_lost' => true]);
            exit;
        }
        $result = restconsole_read_responses($port, $new_id, $host);
    }

    $html = '';
    foreach ($result['lines'] ?? [] as $line) {
        $html .= restconsole_render_line($line) . "\n";
    }

    echo json_encode(['ok' => true, 'html' => $html]);

} catch (Throwable $e) {
    error_log('console_command.php error for target ' . $target . ' (' . $mode . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Console request failed. Please try again.']);
}
