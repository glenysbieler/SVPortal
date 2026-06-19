<?php
/**
 * console.php — Administration: REST Console
 *
 * A dropdown of "Robust" plus every region on the grid; selecting one opens
 * the shared REST Console modal (see includes/helpers.php —
 * render_console_modal()) connected to that target, exactly as the
 * per-region "Console" button on the My Estates region modal does.
 *
 * See RestConsole.md for full design notes and protocol details.
 *
 * ── Access ───────────────────────────────────────────────────────────────
 * Requires meeting the 'Administrator' tier (see USERLEVEL_LABELS /
 * user_level_meets() in config.php). This is full, unrestricted console
 * access (shutdown, kick, region config changes, etc.) to whichever target
 * is selected — the strictest gate in the portal, stricter than the
 * 'Grid Staff' tier used for admin.php.
 *
 * ── Feature gate ─────────────────────────────────────────────────────────
 * If ENABLE_REST_CONSOLE is false, this page (and its drawer item) explain
 * that the feature is not enabled rather than showing a non-functional
 * selector.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/estates.php';

session_start_secure();
require_login();

$session_user = get_session_user();
$userlevel    = (int)($session_user['userlevel'] ?? 0);

$profile   = get_user_profile($session_user['uuid']);
$full_name = htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']);

[
    'status_class'   => $status_class,
    'status_label'   => $status_label,
    'status_tooltip' => $status_tooltip,
] = build_presence_display($session_user);

$prefs      = get_portal_prefs();
$bg_enabled = $prefs['bg'];

if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token           = $_SESSION['_csrf_token'];
$unread_notifications = get_unread_notification_count($session_user['uuid']);
$has_estate_access    = user_has_estate_access(get_db(), $session_user['uuid']);

// ─── Access control ─────────────────────────────────────────────────────────
$access_denied = !user_level_meets($userlevel, 'Administrator');

$regions = [];
if (!$access_denied) {
    $regions = get_all_regions_for_console(get_db());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Console — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
    <script>const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;</script>
    <style>
        .console-page-panel {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-xl, var(--radius-lg));
            box-shadow: var(--shadow-card);
            padding: 20px 24px;
            margin-top: 24px;
        }
        .console-page-panel .panel-heading { margin: 0; }
        .console-page-panel .panel-subhead { margin: 4px 0 0; }

        .console-selector-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }
        .console-selector-row label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--clr-text-secondary);
        }
        .console-target-select {
            flex: 1;
            min-width: 220px;
            max-width: 360px;
            font-size: 0.9rem;
            padding: 9px 12px;
            border-radius: var(--radius-md);
            border: 1px solid var(--clr-border-light);
            background: var(--clr-surface-2);
            color: var(--clr-text-primary);
        }
        .console-feature-disabled {
            font-size: 0.9rem;
            color: var(--clr-text-secondary);
            margin-top: 16px;
        }
    </style>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('console', [], $userlevel, $has_estate_access); ?>

<main class="page-wrap" id="main-content">

    <div class="console-page-panel">
        <h1 class="panel-heading">Console</h1>
        <p class="panel-subhead">Interactive REST console for Robust and any region's simulator.</p>

        <?php if ($access_denied): ?>
        <p class="console-feature-disabled">You don't have permission to view this page.</p>

        <?php elseif (!defined('ENABLE_REST_CONSOLE') || !ENABLE_REST_CONSOLE): ?>
        <p class="console-feature-disabled">
            The REST console is not enabled on this portal. An Administrator can enable it by
            setting <code>ENABLE_REST_CONSOLE</code> (and the related <code>CONSOLE_*</code>
            settings) in <code>config.php</code> — see <code>RestConsole.md</code> for details.
        </p>

        <?php else: ?>
        <div class="console-selector-row">
            <label for="consoleTargetSelect">Console for:</label>
            <select id="consoleTargetSelect" class="console-target-select">
                <option value="">Select a target…</option>
                <option value="robust">Robust</option>
                <?php foreach ($regions as $region): ?>
                <option value="<?= htmlspecialchars($region['uuid'], ENT_QUOTES) ?>"
                        data-name="<?= htmlspecialchars($region['name'], ENT_QUOTES) ?>">
                    <?= htmlspecialchars($region['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn-primary" onclick="openSelectedConsole()">Open console</button>
        </div>
        <?php endif; ?>
    </div>

</main>

<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_confirm_modal(); ?>
<?php if (!$access_denied): ?>
<?php render_console_modal(); ?>
<?php endif; ?>
<?php render_shared_js(); ?>
<script>
function openSelectedConsole() {
    const select = document.getElementById('consoleTargetSelect');
    const value = select.value;
    if (!value) return;

    const label = value === 'robust' ? 'Robust' : (select.selectedOptions[0]?.dataset.name || select.selectedOptions[0]?.textContent.trim() || value);
    openConsoleModal(value, label);
}
</script>
</body>
</html>
