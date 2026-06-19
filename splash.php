<?php
/**
 * splash.php — Grid splash / welcome page
 *
 * ROBUST points to this as the grid's login URI splash page.
 * Visitors see the logo, news feed (if enabled), and a registration CTA —
 * but NO portal login form. Layout is always a vertical single column.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/news_data.php';

// ── Grid stats ────────────────────────────────────────────────────────────────
$grid_stats = null;
if (defined('SHOW_LOGIN_GRID_STATS') && SHOW_LOGIN_GRID_STATS) {
    try {
        $pdo = get_db();

        $thirty_days_ago = (string)(time() - 30 * 86400);
        $system_accounts = defined('STATS_SYSTEM_ACCOUNT_COUNT') ? (int)STATS_SYSTEM_ACCOUNT_COUNT : 0;

        $members = max(0, (int)$pdo->query(
            "SELECT COUNT(*) FROM UserAccounts WHERE active = 1 AND UserLevel >= 0"
        )->fetchColumn() - $system_accounts);

        $active_members_30 = (int)$pdo->query(
            "SELECT COUNT(DISTINCT ua.PrincipalID)
             FROM UserAccounts ua
             JOIN GridUser gu ON gu.UserID = ua.PrincipalID
             WHERE ua.active = 1
               AND CAST(gu.Login AS UNSIGNED) > {$thirty_days_ago}"
        )->fetchColumn();

        $members_online = (int)$pdo->query(
            "SELECT COUNT(DISTINCT gu.UserID)
             FROM GridUser gu
             JOIN UserAccounts ua ON ua.PrincipalID = gu.UserID
             WHERE ua.active = 1
               AND gu.Online = 'true'"
        )->fetchColumn();

        $active_users_30 = (int)$pdo->query(
            "SELECT COUNT(DISTINCT UserID)
             FROM GridUser
             WHERE CAST(Login AS UNSIGNED) > {$thirty_days_ago}"
        )->fetchColumn();

        $users_online = (int)$pdo->query(
            "SELECT COUNT(DISTINCT gu.UserID)
             FROM GridUser gu
             JOIN Presence p ON p.UserID = gu.UserID
             WHERE gu.Online = 'true'"
        )->fetchColumn();

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
        error_log('splash.php grid stats error: ' . $e->getMessage());
    }
}

// ── Random splash background ──────────────────────────────────────────────────
// Sourced from the default theme's /bgimages/ folder (falls back to
// themes/default/bgimages/ automatically if a custom DEFAULT_THEME has none).
$splash_bg_url = null;
if (defined('SHOW_LOGIN_BACKGROUND') && SHOW_LOGIN_BACKGROUND) {
    $splash_bg_url = theme_bg_image_url(defined('DEFAULT_THEME') ? DEFAULT_THEME : 'default');
}

$news_feed_enabled  = defined('SHOW_NEWS_FEED')      && SHOW_NEWS_FEED;
$news_posts         = $news_feed_enabled ? get_visible_news_posts() : [];
$registrations_open = defined('FEATURE_REGISTRATION') && FEATURE_REGISTRATION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php
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
    <style>
        /* ── Splash page: lock to the viewport ─────────────────────────────────
           Splash is only ever seen embedded in the Firestorm login screen, so
           it never needs to support small/mobile viewports. The page itself
           must never scroll — the footer stats stay pinned at the bottom, and
           the card never scrolls out of view. If the news block is too tall
           for the available space, only its content scrolls internally. */
        html, body.splash-page {
            height: 100%;
            overflow: hidden;
        }
        body.splash-page {
            display: flex;
            flex-direction: column;
        }
        body.splash-page .login-main {
            flex: 1 1 auto;
            min-height: 0;        /* allow children to shrink instead of overflowing */
            overflow: hidden;
        }
        body.splash-page .splash-card {
            max-height: 100%;
            display: flex !important;
            flex-direction: column;
            overflow: hidden;
        }
        body.splash-page .login-footer {
            flex: 0 0 auto;
        }

        /* ── Splash card: always a vertical stack, never two-pane ─────────────
           Override every flexbox rule the login-card / has-news-feed classes
           might apply so layout is purely top-to-bottom regardless of config. */

        .splash-card {
            width: min(960px, 96vw);     /* wider to give the news date/time column room */
            max-width: min(960px, 96vw) !important; /* override base login-card max-width: 400px */
            padding: 0;
            overflow: hidden;            /* keep border-radius crisp */
        }

        /* ── Logo block ─────────────────────────────────────────────────────── */
        .splash-logo-block {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem 2.5rem 1rem;
            text-align: center;
            flex: 0 0 auto;
        }

        .splash-logo-block img {
            /* 75% of the previous 320px cap */
            max-width: 240px;
            width: 100%;
            height: auto;
        }

        .splash-subtitle {
            margin: 0.6rem 0 0;
            font-size: 0.93rem;
            color: var(--text-muted, #999);
            font-style: italic;
            letter-spacing: 0.01em;
        }

        /* ── Shared separator ───────────────────────────────────────────────── */
        .splash-sep {
            border: none;
            border-top: 1px solid var(--card-border, rgba(200,180,220,0.3));
            margin: 0;
            flex: 0 0 auto;
        }

        /* ── News feed block ────────────────────────────────────────────────── */
        .splash-news-block {
            padding: 0 2rem 1rem;
            flex: 1 1 auto;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .splash-news-block .login-news-title {
            margin: 0 0 0.5rem;
            text-align: center;
            flex: 0 0 auto;
        }

        .splash-news-block .login-news-content {
            max-height: none;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
        }

        /* ── Registration block ─────────────────────────────────────────────── */
        .splash-reg-block {
            padding: 0.85rem 2rem;
            text-align: center;
            flex: 0 0 auto;
        }

        /* "Inactive" badge */
        .splash-reg-inactive {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 1.4rem;
            border-radius: 8px;
            background: var(--glass-bg, rgba(255,255,255,0.05));
            border: 1px solid var(--card-border, rgba(200,180,220,0.25));
            color: var(--text-muted, #999);
            font-size: 0.88rem;
            font-style: italic;
        }

        .splash-reg-inactive svg {
            flex-shrink: 0;
            opacity: 0.55;
        }
    </style>
</head>
<body class="login-page splash-page<?= $splash_bg_url ? ' has-splash-bg' : '' ?>">

<main class="login-main">

    <!--
        We use login-card for the shared glass/shadow styling but override
        its flex layout entirely via .splash-card so content stacks vertically.
    -->
    <div class="login-card splash-card" role="main">

        <!-- ① Logo, centred ─────────────────────────────────────────────── -->
        <div class="splash-logo-block">
            <img src="<?= htmlspecialchars(theme_image_url('mainlogo.png')) ?>"
                 alt="<?= htmlspecialchars(GRID_NAME) ?>">
            <?php if (defined('GRID_SUBTITLE') && GRID_SUBTITLE !== ''): ?>
            <p class="splash-subtitle"><?= htmlspecialchars(GRID_SUBTITLE) ?></p>
            <?php endif ?>
        </div>

        <?php if ($news_feed_enabled): ?>
        <div class="splash-news-block">
            <h2 class="login-news-title">Grid News</h2>
            <div class="login-news-content">
                <?php render_news_feed_html($news_posts, 'split'); ?>
            </div>
        </div>
        <?php endif ?>

        <!-- ③ Separator → Registration CTA ─────────────────────────────── -->
        <hr class="splash-sep" aria-hidden="true">

        <div class="splash-reg-block">
            <?php if ($registrations_open): ?>
                <a href="register.php" class="btn-primary">
                    Register a new account
                </a>
            <?php else: ?>
                <span class="splash-reg-inactive"
                      aria-label="Registrations are currently closed">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    Registrations currently inactive
                </span>
            <?php endif ?>
        </div>

    </div><!-- /.login-card.splash-card -->

</main>

<!-- ── Footer (identical to login.php) ──────────────────────────────────── -->
<footer class="login-footer">

    <div class="login-footer-identity">
        <span class="footer-grid-name"><?= htmlspecialchars(defined('GRID_DISPLAY_NAME') ? GRID_DISPLAY_NAME : GRID_NAME) ?></span>
        <?php if (defined('GRID_LOGIN_URI') && GRID_LOGIN_URI !== ''): ?>
        <span class="footer-identity-sep" aria-hidden="true">·</span>
        <span class="footer-login-uri-label">Login URI:</span>
        <span class="footer-login-uri"><?= htmlspecialchars(GRID_LOGIN_URI) ?></span>
        <?php endif ?>
    </div>

    <?php if ($grid_stats): ?>
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

</body>
</html>
