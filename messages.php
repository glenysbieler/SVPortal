<?php
/**
 * messages.php — Offline Messages
 *
 * Displays offline instant messages waiting for the logged-in user.
 * Read-only view of the im_offline table — messages are NOT deleted here.
 * They remain in the table until the avatar logs in world, at which point
 * the OpenSim viewer delivers them and OpenSim clears the rows itself.
 *
 * Message content is stored as serialised XML (GridInstantMessage format).
 * We extract <message> and <fromAgentName> from the XML rather than looking
 * up senders by UUID, which also handles hypergrid senders correctly.
 *
 * Session: real authentication via includes/auth.php
 * Data:    SELECT only from im_offline table
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/estates.php';

session_start_secure();
require_login();

// ─── Data ────────────────────────────────────────────────────────────────────

$session_user = get_session_user();
$user_uuid    = $session_user['uuid'];

/**
 * Parse a GridInstantMessage XML blob from im_offline.Message.
 * Returns an array with keys: from_name, message, timestamp, dialog.
 * Falls back gracefully if the XML is malformed or missing expected elements.
 *
 * @param  string $raw  Raw value from im_offline.Message column
 * @return array{from_name:string, message:string, timestamp:int, dialog:int}
 */
function parse_im_xml(string $raw): array
{
    $defaults = [
        'from_name' => 'Unknown User',
        'message'   => '(no message)',
        'timestamp' => 0,
        'dialog'    => 0,
    ];

    if (empty(trim($raw))) {
        return $defaults;
    }

    // Suppress XML parse errors — we handle them gracefully
    $prev = libxml_use_internal_errors(true);
    $xml  = @simplexml_load_string($raw);
    libxml_use_internal_errors($prev);

    if ($xml === false) {
        // Not XML at all — treat the raw value as the message text
        return array_merge($defaults, ['message' => trim($raw)]);
    }

    return [
        'from_name' => trim((string)($xml->fromAgentName ?? '')) ?: 'Unknown User',
        'message'   => trim((string)($xml->message ?? '')) ?: '(no message)',
        'timestamp' => (int)($xml->timestamp ?? 0),
        'dialog'    => (int)($xml->dialog ?? 0),
    ];
}

// Fetch offline messages for this user, newest first
$messages  = [];
$msg_error = null;

try {
    $db   = get_db();
    $stmt = $db->prepare(
        'SELECT ID, FromID, Message, TMStamp
         FROM im_offline
         WHERE PrincipalID = ?
         ORDER BY TMStamp DESC'
    );
    $stmt->execute([$user_uuid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $parsed = parse_im_xml((string)$row['Message']);

        // Skip non-standard dialog types (group notices, inventory offers, etc.)
        // Dialog 0 = plain IM. We show dialog 0 only for now.
        if ($parsed['dialog'] !== 0) {
            continue;
        }

        $messages[] = [
            'id'        => (int)$row['ID'],
            'from_id'   => (string)$row['FromID'],
            'from_name' => $parsed['from_name'],
            'message'   => $parsed['message'],
            'timestamp' => strtotime($row['TMStamp']),  // DB timestamp as Unix int
        ];
    }
} catch (Throwable $e) {
    $msg_error = 'Could not load messages. Please try again later.';
}

$message_count = count($messages);

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Format a Unix timestamp as a human-friendly relative + absolute string.
 * e.g. "2 hours ago  ·  Mon 9 Jun 2025, 14:32"
 */
function format_msg_time(int $ts): array
{
    $age = time() - $ts;

    if ($age < 60) {
        $rel = 'Just now';
    } elseif ($age < 3600) {
        $m   = (int)round($age / 60);
        $rel = $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    } elseif ($age < 86400) {
        $h   = (int)round($age / 3600);
        $rel = $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    } elseif ($age < 86400 * 7) {
        $d   = (int)round($age / 86400);
        $rel = $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    } else {
        $rel = date('j M Y', $ts);
    }

    $abs = date('D j M Y, H:i', $ts);

    return ['rel' => $rel, 'abs' => $abs];
}

// ─── Nav / presence state ─────────────────────────────────────────────────────
$full_name = htmlspecialchars(
    ($session_user['firstname'] ?? '') . ' ' . ($session_user['lastname'] ?? '')
);

[
    'status_class'   => $status_class,
    'status_label'   => $status_label,
    'status_tooltip' => $status_tooltip,
] = build_presence_display($session_user);

// ─── Theme preferences ────────────────────────────────────────────────────────
$prefs      = get_portal_prefs();
$bg_enabled = $prefs['bg'];

// ─── CSRF + notification count ────────────────────────────────────────────────
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token           = $_SESSION['_csrf_token'];
$unread_notifications = get_unread_notification_count($user_uuid);

// ─── Estate access (for "My Estates" drawer item) ─────────────────────────────
$has_estate_access = user_has_estate_access(get_db(), $user_uuid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline Messages — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
    <script>const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;</script>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('messages', [], (int)($session_user['userlevel'] ?? 0), $has_estate_access); ?>


<!-- ══════════════════════════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════════════════════════ -->
<main class="page-wrap">

    <div class="page-header">
        <h1 class="page-title">Offline Messages</h1>
        <?php if ($msg_error === null): ?>
            <span class="message-count-badge <?= $message_count === 0 ? 'empty' : '' ?>">
                <?= $message_count === 1 ? '1 message' : $message_count . ' messages' ?>
            </span>
        <?php endif ?>
    </div>

    <?php if ($msg_error !== null): ?>
        <div class="error-banner" role="alert"><?= htmlspecialchars($msg_error) ?></div>
    <?php endif ?>

    <div class="info-banner" role="note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
        </svg>
        <span>These messages are waiting in your inbox. They will be delivered to your viewer
              automatically the next time you log in world, and will then be cleared from this list.</span>
    </div>

    <?php if ($message_count === 0 && $msg_error === null): ?>

        <div class="empty-state">
            <svg class="empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.2" aria-hidden="true">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
            <p class="empty-title">No offline messages</p>
            <p class="empty-sub">Messages sent to you while you're offline will appear here.</p>
        </div>

    <?php elseif ($message_count > 0): ?>

        <div class="messages-list" role="list">
            <?php foreach ($messages as $i => $msg):
                $times       = format_msg_time($msg['timestamp']);
                $safe_sender  = htmlspecialchars($msg['from_name']);
                $safe_message = htmlspecialchars($msg['message']);
                $safe_abs     = htmlspecialchars($times['abs']);
                $safe_rel     = htmlspecialchars($times['rel']);
            ?>
                <article
                    class="msg-card"
                    role="listitem"
                    tabindex="0"
                    aria-label="Message from <?= $safe_sender ?>, <?= $safe_rel ?>"
                    onclick="openMessage(<?= $i ?>)"
                    onkeydown="if(event.key==='Enter'||event.key===' ')openMessage(<?= $i ?>)"
                >
                    <span class="msg-sender"><?= $safe_sender ?></span>
                    <span class="msg-time" title="<?= $safe_abs ?>"><?= $safe_rel ?></span>
                    <span class="msg-preview"><?= $safe_message ?></span>
                </article>
            <?php endforeach ?>
        </div>

    <?php endif ?>

</main>


<!-- Message detail modal -->
<div class="msg-modal-overlay" id="msgModalOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="modalSender" aria-hidden="true">
    <div class="msg-modal">
        <div class="msg-modal-header">
            <div class="msg-modal-from">
                <span class="msg-modal-sender" id="modalSender"></span>
                <span class="msg-modal-meta" id="modalMeta"></span>
            </div>
            <button class="btn-close-modal" onclick="closeMessage()" aria-label="Close message">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="msg-modal-body">
            <p class="msg-modal-text" id="modalText"></p>
        </div>
        <div class="msg-modal-footer">
            This message is still in your in-world inbox and will be delivered when you next log in.
        </div>
    </div>
</div>


<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_shared_js(); ?>

<script>
/* ── Message data (PHP → JS) ─────────────────────────────────────── */
const MESSAGES = <?= json_encode(array_map(function($m) {
    $times = format_msg_time($m['timestamp']);
    return [
        'from_name' => $m['from_name'],
        'message'   => $m['message'],
        'rel'       => $times['rel'],
        'abs'       => $times['abs'],
    ];
}, $messages), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

/* ── Message modal ───────────────────────────────────────────────── */
function openMessage(index) {
    const m = MESSAGES[index];
    if (!m) return;

    document.getElementById('modalSender').textContent = m.from_name;
    document.getElementById('modalMeta').textContent   = m.abs;
    document.getElementById('modalText').textContent   = m.message;

    const overlay = document.getElementById('msgModalOverlay');
    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    overlay.querySelector('.btn-close-modal').focus();
}

function closeMessage() {
    const overlay = document.getElementById('msgModalOverlay');
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
}

document.getElementById('msgModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeMessage();
});

/* ── Extend Escape to cover message modal ────────────────────────── */
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('msgModalOverlay').classList.contains('open')) {
        closeMessage();
    }
});
</script>

</body>
</html>
