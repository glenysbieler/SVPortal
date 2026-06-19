<?php
/**
 * login.php — Web portal login page
 *
 * Accepts the user's in-world first name, last name, and password.
 * Validates against the OpenSim UserAccounts table (read-only).
 * On success, starts an authenticated session and redirects to profile.php
 * (or the page they originally requested, via ?next=).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/news_data.php';

session_start_secure();

// Already logged in — send them to the portal
if (is_logged_in()) {
    header('Location: profile.php');
    exit;
}

// Whether the public registration page should be linked/advertised
$registrations_open = defined('FEATURE_REGISTRATION') && FEATURE_REGISTRATION;

// ── Determine redirect destination after login ────────────────────────────────
$next = 'profile.php';
if (!empty($_GET['next'])) {
    // Only allow relative paths on our own site — strip anything that looks
    // like an external URL or path traversal
    $candidate = ltrim(urldecode($_GET['next']), '/');
    if (preg_match('/^[a-zA-Z0-9_\-\.\/]+\.php$/', $candidate) && strpos($candidate, '..') === false) {
        $next = $candidate;
    }
}

// ── Handle POST submission ────────────────────────────────────────────────────
$error     = '';
$locked    = false;
$firstname = '';
$lastname  = '';
$notice    = '';

// Show a friendly message if the user was redirected here after an inactivity timeout
if (!empty($_GET['reason']) && $_GET['reason'] === 'timeout') {
    $notice = 'You were logged out due to inactivity. Please log in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF token check
    if (empty($_POST['_token']) || !hash_equals($_SESSION['_csrf_token'] ?? '', $_POST['_token'])) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $firstname = trim($_POST['firstname'] ?? '');
        $lastname  = trim($_POST['lastname']  ?? '');
        $password  = $_POST['password'] ?? '';

        $result = attempt_login($firstname, $lastname, $password);

        if ($result['ok']) {
            header('Location: ' . $next);
            exit;
        }

        $error  = $result['error'];
        $locked = $result['locked'];
    }
}

// ── Generate CSRF token ───────────────────────────────────────────────────────
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

// ── Grid stats (for login panel) ─────────────────────────────────────────────
$grid_stats = null;
if (defined('SHOW_LOGIN_GRID_STATS') && SHOW_LOGIN_GRID_STATS) {
    try {
        $pdo = get_db();

        // Unix timestamp for 30 days ago — GridUser.Login/Logout are stored as
        // char(16) Unix timestamps, so we compare numerically.
        $thirty_days_ago = (string)(time() - 30 * 86400);

        // ── Local members (have a UserAccounts row on this grid) ─────────────
        // Subtract STATS_SYSTEM_ACCOUNT_COUNT to exclude internal/service accounts
        // (NPCs, scripting users, god-mode admin accounts, etc.) from the public count.
        $system_accounts = defined('STATS_SYSTEM_ACCOUNT_COUNT') ? (int)STATS_SYSTEM_ACCOUNT_COUNT : 0;
        $members = max(0, (int)$pdo->query(
            "SELECT COUNT(*) FROM UserAccounts WHERE active = 1 AND UserLevel >= 0"
        )->fetchColumn() - $system_accounts);

        // Local members active in the last 30 days.
        // GridUser.Login is a Unix timestamp stored as char(16); cast for comparison.
        // UserID in GridUser is a plain UUID for local users (matches PrincipalID).
        $active_members_30 = (int)$pdo->query(
            "SELECT COUNT(DISTINCT ua.PrincipalID)
             FROM UserAccounts ua
             JOIN GridUser gu ON gu.UserID = ua.PrincipalID
             WHERE ua.active = 1
               AND CAST(gu.Login AS UNSIGNED) > {$thirty_days_ago}"
        )->fetchColumn();

        // Local members currently in-world — GridUser.Online = 'true'
        $members_online = (int)$pdo->query(
            "SELECT COUNT(DISTINCT gu.UserID)
             FROM GridUser gu
             JOIN UserAccounts ua ON ua.PrincipalID = gu.UserID
             WHERE ua.active = 1
               AND gu.Online = 'true'"
        )->fetchColumn();

        // ── All users including HG visitors (GridUser only) ──────────────────
        // GridUser.UserID is a plain UUID for locals; HG visitors appear as a
        // full URI (e.g. http://foreigngrid:8002;uuid). No join to UserAccounts —
        // this intentionally counts everyone who has ever touched the grid.

        // All distinct users who logged in within the last 30 days
        $active_users_30 = (int)$pdo->query(
            "SELECT COUNT(DISTINCT UserID)
             FROM GridUser
             WHERE CAST(Login AS UNSIGNED) > {$thirty_days_ago}"
        )->fetchColumn();

        // All users currently online — require both GridUser.Online = 'true' AND
        // an active Presence row. The join filters out stale GridUser rows where
        // a foreign grid failed to send a logout signal back to our ROBUST.
        $users_online = (int)$pdo->query(
            "SELECT COUNT(DISTINCT gu.UserID)
             FROM GridUser gu
             JOIN Presence p ON p.UserID = gu.UserID
             WHERE gu.Online = 'true'"
        )->fetchColumn();

        // ── Regions ──────────────────────────────────────────────────────────
        $region_count = (int)$pdo->query(
            "SELECT COUNT(*) FROM regions"
        )->fetchColumn();

        $area_m2  = (float)$pdo->query(
            "SELECT COALESCE(SUM(CAST(sizeX AS UNSIGNED) * CAST(sizeY AS UNSIGNED)), 0) FROM regions"
        )->fetchColumn();
        $area_km2 = round($area_m2 / 1_000_000, 2);

        $grid_stats = compact(
            'members', 'active_members_30', 'members_online',
            'active_users_30', 'users_online',
            'region_count', 'area_km2'
        );

    } catch (\Throwable $e) {
        // Stats are non-critical; silently swallow DB errors
        error_log('login.php grid stats error: ' . $e->getMessage());
    }
}

// ── Random splash background ──────────────────────────────────────────────────
// Sourced from the default theme's /bgimages/ folder (falls back to
// themes/default/bgimages/ automatically if a custom DEFAULT_THEME has none).
$splash_bg_url = null;
if (defined('SHOW_LOGIN_BACKGROUND') && SHOW_LOGIN_BACKGROUND) {
    $splash_bg_url = theme_bg_image_url(defined('DEFAULT_THEME') ? DEFAULT_THEME : 'default');
}
// ── News feed ────────────────────────────────────────────────────────────────
$news_feed_enabled = defined('SHOW_NEWS_FEED') && SHOW_NEWS_FEED;
$news_posts        = $news_feed_enabled ? get_visible_news_posts() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php
    // Login uses the default theme CSS; no personalised prefs needed on this page.
    $login_css = htmlspecialchars(
        '/themes/' . (defined('DEFAULT_THEME') ? DEFAULT_THEME : 'default')
        . '/' . (defined('DEFAULT_THEME') ? DEFAULT_THEME : 'default') . '.css',
        ENT_QUOTES
    );
    echo '    <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=Inter:wght@300;400;500;600&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">' . "\n";
    echo '    <link rel="stylesheet" href="' . $login_css . '">' . "\n";
    ?>
    <?php if ($splash_bg_url): ?>
    <style>
        body.login-page.has-splash-bg {
            background-image: url('<?= htmlspecialchars($splash_bg_url, ENT_QUOTES) ?>');
        }
    </style>
    <?php endif ?>
</head>
<body class="login-page<?= $splash_bg_url ? ' has-splash-bg' : '' ?>">

<main class="login-main">

    <!-- Login card (expands to two-pane when news feed is enabled) -->
    <div class="login-card<?= $news_feed_enabled ? ' has-news-feed' : '' ?>" role="main">

        <?php if ($news_feed_enabled): ?>
        <!-- News feed pane -->
        <div class="login-news-pane">
            <h2 class="login-news-title">Grid News</h2>
            <div class="login-news-content">
                <?php render_news_feed_html($news_posts); ?>
            </div>
        </div>
        <div class="login-news-divider" aria-hidden="true"></div>
        <!-- Login form pane -->
        <div class="login-form-pane">
        <?php endif ?>

        <!-- Logo -->
        <div class="login-logo">
            <img src="<?= htmlspecialchars(theme_image_url('mainlogo.png')) ?>" alt="<?= htmlspecialchars(GRID_NAME) ?>">
        </div>
        <p class="login-subtitle">Sign in with your in-world name and password</p>

        <!-- Timeout / error / lockout alerts -->
        <?php if ($notice !== ''): ?>
        <div class="alert alert-notice" role="status" aria-live="polite">
            <svg class="alert-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
            </svg>
            <?= htmlspecialchars($notice) ?>
        </div>
        <?php endif ?>
        <?php if ($locked): ?>
        <div class="alert alert-warning" role="alert" aria-live="assertive">
            <svg class="alert-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php elseif ($error !== ''): ?>
        <div class="alert alert-error" role="alert" aria-live="assertive">
            <svg class="alert-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif ?>

        <!-- Login form -->
        <form id="loginForm" method="post" action="login.php<?= $next !== 'profile.php' ? '?next=' . urlencode($next) : '' ?>" novalidate>
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <!-- First name + Last name side by side -->
            <div class="login-form-row">
                <div class="form-group">
                    <label for="firstname">First name</label>
                    <input
                        type="text"
                        id="firstname"
                        name="firstname"
                        value="<?= htmlspecialchars($firstname) ?>"
                        autocomplete="given-name"
                        autocapitalize="words"
                        spellcheck="false"
                        maxlength="64"
                        required
                        <?= $locked ? 'disabled' : '' ?>
                        aria-describedby="<?= $error ? 'login-error' : '' ?>"
                    >
                </div>
                <div class="form-group">
                    <label for="lastname">Last name</label>
                    <input
                        type="text"
                        id="lastname"
                        name="lastname"
                        value="<?= htmlspecialchars($lastname) ?>"
                        autocomplete="family-name"
                        autocapitalize="words"
                        spellcheck="false"
                        maxlength="64"
                        required
                        <?= $locked ? 'disabled' : '' ?>
                    >
                </div>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        maxlength="128"
                        required
                        <?= $locked ? 'disabled' : '' ?>
                    >
                    <button type="button" class="toggle-pw" onclick="togglePassword()" aria-label="Show or hide password" tabindex="-1">
                        <svg id="pw-eye" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button
                type="submit"
                class="btn-primary"
                <?= $locked ? 'disabled' : '' ?>
            >
                Sign in
            </button>
        </form>

        <p class="login-hint">
            Use your in-world name and password.<br>
            Having trouble? Contact your grid administrator.
            <?php if ($registrations_open): ?>
                <br>New here? <a href="register.php">Register an account</a>.
            <?php endif ?>
        </p>

        <?php if ($news_feed_enabled): ?>
        </div><!-- /.login-form-pane -->
        <?php endif ?>

    </div><!-- /.login-card -->
</main>

<!-- ── Login page footer ─────────────────────────────────────────────────── -->
<footer class="login-footer">

    <!-- Grid identity row -->
    <div class="login-footer-identity">
        <span class="footer-grid-name"><?= htmlspecialchars(defined('GRID_DISPLAY_NAME') ? GRID_DISPLAY_NAME : GRID_NAME) ?></span>
        <?php if (defined('GRID_LOGIN_URI') && GRID_LOGIN_URI !== ''): ?>
        <span class="footer-identity-sep" aria-hidden="true">·</span>
        <span class="footer-login-uri-label">Login URI:</span>
        <span class="footer-login-uri"><?= htmlspecialchars(GRID_LOGIN_URI) ?></span>
        <?php endif ?>
    </div>

    <?php if ($grid_stats): ?>
    <!-- Stats row -->
    <dl class="footer-stats">
        <div class="footer-stat">
            <dt>Status</dt>
            <dd class="stats-online">
                <span class="stats-status-dot" aria-hidden="true"></span>
                Online
            </dd>
        </div>
        <div class="footer-stat">
            <dt>Members</dt>
            <dd><?= number_format($grid_stats['members']) ?></dd>
        </div>
        <div class="footer-stat">
            <dt>Active members <span class="stat-period">(30 days)</span></dt>
            <dd><?= number_format($grid_stats['active_members_30']) ?></dd>
        </div>
        <div class="footer-stat">
            <dt>Members in world</dt>
            <dd><?= number_format($grid_stats['members_online']) ?></dd>
        </div>
        <div class="footer-stat">
            <dt>Active users <span class="stat-period">(30 days)</span></dt>
            <dd><?= number_format($grid_stats['active_users_30']) ?></dd>
        </div>
        <div class="footer-stat">
            <dt>Total users in world</dt>
            <dd><?= number_format($grid_stats['users_online']) ?></dd>
        </div>
        <div class="footer-stat">
            <dt>Regions</dt>
            <dd><?= number_format($grid_stats['region_count']) ?></dd>
        </div>
        <div class="footer-stat">
            <dt>Total area</dt>
            <dd><?= number_format($grid_stats['area_km2'], 2) ?>&thinsp;km²</dd>
        </div>
    </dl>
    <?php endif ?>

</footer>

<script>
/* Password show/hide toggle */
function togglePassword() {
    const input = document.getElementById('password');
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';

    // Swap icon between eye and eye-off
    const eye = document.getElementById('pw-eye');
    if (isHidden) {
        eye.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>' +
            '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>' +
            '<line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        eye.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}

/* Auto-focus first name field on load */
document.addEventListener('DOMContentLoaded', () => {
    const first = document.getElementById('firstname');
    if (first && !first.disabled) {
        first.focus();
    }
});
</script>
</body>
</html>
