<?php
/**
 * console_session.php — REST Console: open a session
 *
 * Opens (or re-opens) a REST console session for a specific target —
 * either a region's simulator (identified by its `regions.uuid`) or the
 * grid's ROBUST service (target = 'robust') — and stores the resulting
 * SessionID server-side, keyed by PHP session + target.
 *
 * The SessionID and console credentials (CONSOLE_USER/CONSOLE_PASS) are
 * NEVER sent to the browser — only an opaque success/failure result.
 * Subsequent commands (console_command.php) re-use the stored SessionID.
 *
 * Request:
 *   POST console_session.php
 *     target = '<region UUID>' | 'robust'
 *     csrf   = <token>
 *
 * Response (JSON):
 *   { "ok": true,  "name": "Castle", "prompt": "Region (Castle) " }
 *   { "ok": false, "error": "..." }
 *
 * ── Access ───────────────────────────────────────────────────────────────
 * Requires meeting the 'Administrator' tier (see USERLEVEL_LABELS /
 * user_level_meets() in config.php) — see RestConsole.md. This grants
 * full, unrestricted console access (shutdown, kick, region config changes,
 * etc.) to the targeted simulator/ROBUST process.
 *
 * ── Session storage ─────────────────────────────────────────────────────────
 * Stored in $_SESSION['_console_sessions'][$target] = [
 *     'session_id' => '...',
 *     'port'       => 1234,
 *     'host'       => '...',   // resolved console host for this target
 *     'name'       => 'Castle',
 *     'prompt'     => '...',
 *     'opened_at'  => <unix time>,
 * ]
 *
 * One stored session per target per PHP session — opening a session for a
 * target that already has one closes the old one first (best-effort) and
 * replaces it. This keeps things simple: only one open console tab per
 * target per browser session.
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

// ─── Resolve target ──────────────────────────────────────────────────────────
$target = trim($_POST['target'] ?? '');

if ($target === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'A target is required.']);
    exit;
}

try {
    $db = get_db();

    if ($target === 'robust') {
        $port = (int)ROBUST_PRIVATE_PORT;
        $host = defined('CONSOLE_ROBUST_HOST') && CONSOLE_ROBUST_HOST !== ''
              ? CONSOLE_ROBUST_HOST
              : (defined('ROBUST_HOST') ? ROBUST_HOST : CONSOLE_HOST);
        $name = 'Robust';
    } else {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $target)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid target.']);
            exit;
        }

        $stmt = $db->prepare('SELECT regionName, serverPort FROM regions WHERE uuid = ? LIMIT 1');
        $stmt->execute([$target]);
        $region = $stmt->fetch();

        if ($region === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Region not found.']);
            exit;
        }

        $port = (int)$region['serverPort'];
        $host = CONSOLE_HOST;
        $name = (string)$region['regionName'];

        if ($port <= 0) {
            echo json_encode(['ok' => false, 'error' => 'This region has no configured server port.']);
            exit;
        }
    }

    // ─── Close any existing session for this target first (best-effort) ───
    if (!empty($_SESSION['_console_sessions'][$target]['session_id'])) {
        $old = $_SESSION['_console_sessions'][$target];
        restconsole_close_session((int)$old['port'], (string)$old['session_id'], (string)($old['host'] ?? ''));
        unset($_SESSION['_console_sessions'][$target]);
    }

    // ─── Open a new session ─────────────────────────────────────────────
    $result = restconsole_start_session($port, $host);

    if (!$result['success']) {
        echo json_encode(['ok' => false, 'error' => $result['error'] ?? 'Could not open console session.']);
        exit;
    }

    $_SESSION['_console_sessions'][$target] = [
        'session_id' => $result['session_id'],
        'port'       => $port,
        'host'       => $host,
        'name'       => $name,
        'prompt'     => $result['prompt'] ?? '',
        'opened_at'  => time(),
    ];

    echo json_encode([
        'ok'     => true,
        'name'   => $name,
        'prompt' => $result['prompt'] ?? '',
    ]);

} catch (Throwable $e) {
    error_log('console_session.php error for target ' . $target . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not open console session. Please try again.']);
}
