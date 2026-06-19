<?php
/**
 * publicprofile.php — Public profile viewer
 *
 * Fully unauthenticated. No session or login required.
 *
 * Two modes:
 *
 *   No ?view= parameter:
 *     Shows a centred search box. Live AJAX search queries
 *     includes/public_profile_data.php?action=search. Only users who have
 *     opted in (portal_prefs.public_profile = 1) appear in results.
 *     Clicking a result navigates to ?view=<UUID>.
 *
 *   ?view=<UUID>:
 *     Shows the named user's profile in the same two-column layout
 *     used by the friend profile modal (fp- classes). Search box
 *     appears above the panel so visitors can search for others.
 *     Returns a "profile not public" message if the user has not
 *     opted in, even if PUBLIC_PROFILES is enabled globally.
 *
 * If PUBLIC_PROFILES = false in config.php, a friendly "not available"
 * message is shown regardless of any parameters.
 *
 * Design: no navbar, no drawer, no session indicators. Grid logo shown
 * at the top of the panel so visitors know which grid they are viewing.
 * Uses the same fp- CSS classes as the friend profile modal so no new
 * layout CSS is needed — only page-chrome styles are added.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';

// ─── Theme (cookie only — no session) ────────────────────────────────────────
$prefs      = get_portal_prefs();
$bg_enabled = $prefs['bg'];
$logo_src   = htmlspecialchars(theme_image_url('mainlogo.png'), ENT_QUOTES);
$grid_name  = htmlspecialchars(defined('GRID_DISPLAY_NAME') ? GRID_DISPLAY_NAME : GRID_NAME);

// ─── Grid stats (search mode only, reuses splash.php logic) ─────────────────
$grid_stats = null;
if (defined('SHOW_LOGIN_GRID_STATS') && SHOW_LOGIN_GRID_STATS) {
    try {
        $pdo             = get_db();
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
        error_log('publicprofile.php grid stats error: ' . $e->getMessage());
    }
}

// ─── Mode detection ───────────────────────────────────────────────────────────
$view_uuid   = trim($_GET['view'] ?? '');
$valid_uuid  = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $view_uuid);
$view_uuid   = $valid_uuid ? $view_uuid : '';
$mode        = $view_uuid !== '' ? 'profile' : 'search';

// ─── Pre-load profile data for ?view= mode ────────────────────────────────────
// We fetch server-side so the page renders with real content for
// search engines and users with JS disabled, and to validate access.
$preload_profile = null;   // ['ok' => bool, 'profile' => [], 'picks' => [], 'error' => '']

if ($mode === 'profile' && defined('PUBLIC_PROFILES') && PUBLIC_PROFILES) {
    try {
        $db   = get_db();
        $stmt = $db->prepare('SELECT public_profile FROM portal_prefs WHERE uuid = ? LIMIT 1');
        $stmt->execute([$view_uuid]);
        $row  = $stmt->fetch();

        if (!$row || !(bool)(int)$row['public_profile']) {
            $preload_profile = ['ok' => false, 'error' => 'No public profile was found at this address.'];
        } else {
            $profile      = get_user_profile($view_uuid);
            $picks        = get_user_picks($view_uuid);
            $partner_name = null;
            $partner_link = null;
            $partner_uuid = $profile['partner_uuid'] ?? '00000000-0000-0000-0000-000000000000';

            if ($partner_uuid !== '00000000-0000-0000-0000-000000000000') {
                $stmt2 = $db->prepare('SELECT FirstName, LastName FROM UserAccounts WHERE PrincipalID = ? LIMIT 1');
                $stmt2->execute([$partner_uuid]);
                $prow = $stmt2->fetch();
                if ($prow) {
                    $partner_name = trim($prow['FirstName'] . ' ' . $prow['LastName']);
                }

                // Link to partner's public profile only if they've also opted in
                $stmt2 = $db->prepare('SELECT 1 FROM portal_prefs WHERE uuid = ? AND public_profile = 1 LIMIT 1');
                $stmt2->execute([$partner_uuid]);
                if ($stmt2->fetch()) {
                    $partner_link = 'publicprofile.php?view=' . $partner_uuid;
                }
            }

            $preload_profile = [
                'ok'      => true,
                'profile' => [
                    'uuid'         => $profile['uuid'],
                    'fullname'     => trim($profile['firstname'] . ' ' . $profile['lastname']),
                    'about'        => $profile['about_text'],
                    'created'      => (int)$profile['created'],
                    'image_url'    => get_profile_image_url($profile['profile_image_uuid']),
                    'partner_uuid' => $partner_uuid,
                    'partner_name' => $partner_name,
                    'partner_link' => $partner_link,
                ],
                'picks' => array_values(array_filter(
                    array_map(fn(array $p): array => [
                        'name'        => $p['name'],
                        'description' => $p['description'],
                        'image_url'   => get_pick_image_url($p['image_uuid']),
                        'sim_name'    => $p['sim_name'] ?? '',
                        'pos_x'       => (int)$p['pos_x'],
                        'pos_y'       => (int)$p['pos_y'],
                        'pos_z'       => (int)$p['pos_z'],
                        'is_blank'    => (bool)$p['is_blank'],
                    ], $picks),
                    fn($p) => !$p['is_blank']
                )),
            ];
        }
    } catch (\Throwable $e) {
        error_log('publicprofile.php preload error: ' . $e->getMessage());
        $preload_profile = ['ok' => false, 'error' => 'No public profile was found at this address.'];
    }
}

$page_title = $grid_name . ' — Resident Profiles';
if ($mode === 'profile' && ($preload_profile['ok'] ?? false)) {
    $page_title = htmlspecialchars($preload_profile['profile']['fullname']) . ' — ' . $grid_name;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <?php render_shared_css(); ?>
    <style>
    /* ── Public profile page chrome ────────────────────────────────────────── */

    /* Full-page centred layout */
    .pp-page {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 40px 16px 60px;
        position: relative;
        z-index: 1;
    }

    /* Search-only mode: vertically centred, bottom padding so fixed footer
       never overlaps the card */
    .pp-page.pp-search-mode {
        justify-content: center;
        padding-bottom: 120px;
    }

    /* Sign-in link anchored to top-right of the search card */
    .pp-card-signin {
        position: absolute;
        top: 16px;
        right: 20px;
        font-size: 0.8rem;
        color: var(--clr-lilac-deep);
        text-decoration: none;
        font-weight: 500;
        padding: 5px 14px;
        border: 1px solid var(--clr-lilac-mid);
        border-radius: var(--radius-pill);
        transition: background 0.15s, color 0.15s;
        white-space: nowrap;
    }
    .pp-card-signin:hover {
        background: var(--clr-lilac-soft);
        color: var(--clr-lilac-text);
    }

    /* Stats footer — fixed to bottom, reuses login-footer + footer-stats
       classes from default.css for identical dark-glass look */
    .pp-stats-footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 10;
    }

    /* Outer wrapper constrains width */
    .pp-wrap {
        width: 100%;
        max-width: 980px;
    }

    /* Search mode panel card */
    .pp-search-card {
        position: relative;
        background: var(--clr-surface);
        border: 1px solid var(--clr-border-light);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-card);
        overflow: visible;
        padding: 36px 40px 32px;
    }

    /* Grid logo — centred above search box */
    .pp-logo {
        display: flex;
        justify-content: center;
        margin-bottom: 24px;
    }
    .pp-logo img {
        height: 140px;
        width: auto;
        display: block;
    }

    /* Search bar */
    .pp-search-wrap {
        position: relative;
        margin-bottom: 24px;
    }
    .pp-search-input {
        width: 100%;
        padding: 13px 48px 13px 18px;
        font-size: 0.95rem;
        font-family: var(--font-body);
        color: var(--clr-text-primary);
        background: var(--clr-surface);
        border: 1px solid var(--clr-border);
        border-radius: var(--radius-pill);
        box-shadow: var(--shadow-card);
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .pp-search-input::placeholder { color: var(--clr-text-muted); }
    .pp-search-input:focus {
        border-color: var(--clr-lilac-deep);
        box-shadow: 0 0 0 3px rgba(139,104,196,0.15), var(--shadow-card);
    }
    .pp-search-icon {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--clr-text-muted);
        pointer-events: none;
    }

    /* Results dropdown */
    .pp-results {
        position: absolute;
        top: calc(100% + 6px);
        left: 0;
        right: 0;
        background: var(--clr-surface);
        border: 1px solid var(--clr-border);
        border-radius: var(--radius-md);
        box-shadow: 0 8px 32px rgba(100,70,160,0.14);
        z-index: 50;
        overflow: hidden;
        display: none;
    }
    .pp-results.open { display: block; }
    .pp-result-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: 11px 16px;
        cursor: pointer;
        text-decoration: none;
        border-bottom: 1px solid var(--clr-border-light);
        transition: background 0.12s;
    }
    .pp-result-item:last-child { border-bottom: none; }
    .pp-result-item:hover,
    .pp-result-item:focus {
        background: var(--clr-surface-2);
        outline: none;
    }
    .pp-result-name {
        font-size: 0.88rem;
        font-weight: 600;
        color: var(--clr-text-primary);
    }
    .pp-result-uuid {
        font-size: 0.68rem;
        color: var(--clr-text-muted);
        font-family: 'Courier New', monospace;
    }
    .pp-results-empty,
    .pp-results-searching {
        padding: 14px 16px;
        font-size: 0.85rem;
        color: var(--clr-text-muted);
        text-align: center;
    }

    /* Search hint text */
    .pp-search-hint {
        text-align: center;
        font-size: 0.84rem;
        color: var(--clr-text-muted);
        margin-top: 8px;
    }

    /* "Not available" / "Private" notice card */
    .pp-notice-card {
        background: var(--clr-surface);
        border: 1px solid var(--clr-border-light);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-card);
        padding: 48px 36px;
        text-align: center;
        max-width: 480px;
        margin: 0 auto;
    }
    .pp-notice-icon {
        color: var(--clr-lilac-mid);
        margin-bottom: 18px;
    }
    .pp-notice-title {
        font-family: var(--font-display);
        font-style: italic;
        font-size: 1.4rem;
        color: var(--clr-text-primary);
        margin-bottom: 10px;
    }
    .pp-notice-body {
        font-size: 0.88rem;
        color: var(--clr-text-secondary);
        line-height: 1.65;
    }

    /* Profile panel wrapper */
    .pp-profile-panel {
        background: var(--clr-surface);
        border: 1px solid var(--clr-border-light);
        border-radius: var(--radius-xl);
        box-shadow: var(--shadow-card);
        overflow: hidden;
    }

    /* Panel header: centred logo + sign-in button */
    .pp-panel-header {
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        padding: 16px 24px;
        border-bottom: 1px solid var(--clr-border-light);
        background: linear-gradient(135deg, var(--clr-surface) 0%, var(--clr-surface-2) 100%);
    }
    .pp-panel-header img {
        height: 120px;
        width: auto;
        display: block;
    }
    .pp-signin-link {
        position: absolute;
        right: 20px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 0.8rem;
        color: var(--clr-lilac-deep);
        text-decoration: none;
        font-weight: 500;
        padding: 5px 14px;
        border: 1px solid var(--clr-lilac-mid);
        border-radius: var(--radius-pill);
        transition: background 0.15s, color 0.15s;
        white-space: nowrap;
    }
    .pp-signin-link:hover {
        background: var(--clr-lilac-soft);
        color: var(--clr-lilac-text);
    }

    /* Mobile */
    @media (max-width: 680px) {
        .pp-page { padding: 24px 12px 40px; }
        .pp-search-card { padding: 24px 18px 20px; }
        .pp-notice-card { padding: 32px 20px; }
        .pp-panel-header { padding: 12px 16px; flex-wrap: wrap; justify-content: center; gap: 10px; }
        .pp-signin-link { position: static; transform: none; }
        .pp-logo img { height: 100px; }
    }
    </style>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>

<div class="pp-page <?= $mode === 'search' ? 'pp-search-mode' : '' ?>">
<div class="pp-wrap">

<?php if (!defined('PUBLIC_PROFILES') || !PUBLIC_PROFILES): ?>
    <!-- ── PUBLIC_PROFILES disabled ─────────────────────────────────────── -->
    <div class="pp-notice-card">
        <div class="pp-logo" style="margin-bottom:20px;">
            <img src="<?= $logo_src ?>" alt="<?= $grid_name ?>">
        </div>
        <div class="pp-notice-icon">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
        </div>
        <p class="pp-notice-title">Profiles not available</p>
        <p class="pp-notice-body">
            Public resident profiles are not currently available on
            <?= $grid_name ?>. Please check back later.
        </p>
    </div>

<?php elseif ($mode === 'search'): ?>
    <!-- ── Search mode ──────────────────────────────────────────────────── -->
    <div class="pp-search-card">
        <a href="login.php" class="pp-card-signin">Sign in</a>
        <div class="pp-logo">
            <img src="<?= $logo_src ?>" alt="<?= $grid_name ?>">
        </div>

        <div class="pp-search-wrap" id="ppSearchWrap">
            <input type="text"
                   id="ppSearchInput"
                   class="pp-search-input"
                   placeholder="Search for a resident…"
                   autocomplete="off"
                   spellcheck="false"
                   aria-label="Search residents"
                   aria-autocomplete="list"
                   aria-controls="ppResults">
            <svg class="pp-search-icon" width="18" height="18" viewBox="0 0 24 24"
                 fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <div class="pp-results" id="ppResults" role="listbox" aria-label="Search results"></div>
        </div>
        <p class="pp-search-hint">Type a name to find residents with public profiles</p>
    </div>

<?php else: ?>
    <!-- ── Profile view mode ────────────────────────────────────────────── -->

    <!-- Search box above the panel -->
    <div class="pp-search-wrap" id="ppSearchWrap" style="margin-bottom:20px;">
        <input type="text"
               id="ppSearchInput"
               class="pp-search-input"
               placeholder="Search for another resident…"
               autocomplete="off"
               spellcheck="false"
               aria-label="Search residents"
               aria-autocomplete="list"
               aria-controls="ppResults">
        <svg class="pp-search-icon" width="18" height="18" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <div class="pp-results" id="ppResults" role="listbox" aria-label="Search results"></div>
    </div>

    <?php if (!($preload_profile['ok'] ?? false)): ?>
    <!-- Profile private or not found -->
    <div class="pp-notice-card">
        <div class="pp-notice-icon">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <p class="pp-notice-title">Profile not found</p>
        <p class="pp-notice-body">
            <?= htmlspecialchars($preload_profile['error'] ?? 'No public profile was found at this address.') ?>
        </p>
    </div>

    <?php else:
        $p     = $preload_profile['profile'];
        $picks = $preload_profile['picks'];
    ?>
    <!-- Profile panel -->
    <div class="pp-profile-panel">

        <!-- Panel header: centred logo + sign-in button -->
        <div class="pp-panel-header">
            <img src="<?= $logo_src ?>" alt="<?= $grid_name ?>">
            <a href="login.php" class="pp-signin-link">Sign in</a>
        </div>

        <!-- Two-column content — reuse fp- classes -->
        <div class="fp-content" style="display:grid;">

            <!-- Left column -->
            <div class="fp-left">
                <div class="fp-avatar-wrap">
                    <img src="<?= htmlspecialchars($p['image_url']) ?>"
                         alt="Profile picture of <?= htmlspecialchars($p['fullname']) ?>">
                </div>

                <div class="fp-identity">
                    <div class="fp-fullname"><?= htmlspecialchars($p['fullname']) ?></div>

                    <dl class="fp-meta">
                        <div class="fp-meta-row fp-meta-row--stacked">
                            <dt class="fp-meta-label">UUID</dt>
                            <dd class="fp-meta-value fp-uuid"><?= htmlspecialchars($p['uuid']) ?></dd>
                        </div>
                        <?php if ($p['partner_name']): ?>
                        <div class="fp-meta-row">
                            <dt class="fp-meta-label">Partner</dt>
                            <dd class="fp-meta-value fp-partner">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"
                                     stroke="none" aria-hidden="true" style="color:var(--clr-accent-rose);flex-shrink:0;">
                                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                </svg>
                                <?php if (!empty($p['partner_link'])): ?>
                                <span><a href="<?= htmlspecialchars($p['partner_link']) ?>" class="partner-link"><?= htmlspecialchars($p['partner_name']) ?></a></span>
                                <?php else: ?>
                                <span><?= htmlspecialchars($p['partner_name']) ?></span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>

                <div class="fp-divider" role="separator"></div>

                <div class="fp-about-section">
                    <p class="fp-section-label">About</p>
                    <p class="fp-about-text" id="ppAboutText"><?php if (!empty($p['about'])): ?><?= ppLinkify(htmlspecialchars(trim($p['about']))) ?><?php else: ?><em>No about text set.</em><?php endif; ?></p>
                </div>

                <div class="fp-member-since">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <path d="M16 2v4M8 2v4M3 10h18"/>
                    </svg>
                    Member since <?= date('F Y', $p['created']) ?>
                </div>
            </div>

            <!-- Right column: picks -->
            <div class="fp-right">
                <div class="picks-header">
                    <h2 class="picks-title">Picks</h2>
                    <?php if (!empty($picks)): ?>
                    <span class="picks-count">
                        <?= count($picks) ?> place<?= count($picks) !== 1 ? 's' : '' ?>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($picks)): ?>
                <div class="fp-picks-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" opacity="0.4" aria-hidden="true">
                        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <p>No picks yet.</p>
                </div>
                <?php else: ?>
                <ul class="picks-list" role="list" id="ppPicksList">
                    <?php foreach ($picks as $pick): ?>
                    <li class="pick-card"
                        role="listitem"
                        tabindex="0"
                        style="cursor:pointer"
                        data-name="<?= htmlspecialchars($pick['name'] ?: 'Unnamed', ENT_QUOTES) ?>"
                        data-img="<?= htmlspecialchars($pick['image_url'], ENT_QUOTES) ?>"
                        data-sim="<?= htmlspecialchars($pick['sim_name'] ?? '', ENT_QUOTES) ?>"
                        data-x="<?= (int)$pick['pos_x'] ?>"
                        data-y="<?= (int)$pick['pos_y'] ?>"
                        data-z="<?= (int)$pick['pos_z'] ?>"
                        data-desc-html="<?= htmlspecialchars(ppLinkify(htmlspecialchars($pick['description'] ?? '')), ENT_QUOTES) ?>"
                        onclick="openPpPickModal(this)"
                        onkeydown="if(event.key==='Enter'||event.key===' ')openPpPickModal(this)"
                    >
                        <div class="pick-image">
                            <img src="<?= htmlspecialchars($pick['image_url']) ?>"
                                 alt="<?= htmlspecialchars($pick['name'] ?: 'Pick') ?>"
                                 loading="lazy">
                        </div>
                        <div class="pick-body">
                            <div class="pick-name">
                                <?= $pick['name'] !== ''
                                    ? htmlspecialchars($pick['name'])
                                    : '<em style="color:var(--clr-text-muted);font-style:italic;font-size:0.8rem;">Unnamed</em>' ?>
                            </div>
                            <?php if (!empty($pick['sim_name'])): ?>
                            <div class="pick-location">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
                                </svg>
                                <?= htmlspecialchars($pick['sim_name']) ?>
                                <?php if ($pick['pos_x'] || $pick['pos_y'] || $pick['pos_z']): ?>
                                (<?= (int)$pick['pos_x'] ?>, <?= (int)$pick['pos_y'] ?>, <?= (int)$pick['pos_z'] ?>)
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($pick['description'])): ?>
                            <p class="pick-desc"><?= ppLinkify(htmlspecialchars($pick['description'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

        </div><!-- /.fp-content -->
    </div><!-- /.pp-profile-panel -->
    <?php endif; ?>

<?php endif; ?>

</div><!-- /.pp-wrap -->
</div><!-- /.pp-page -->

<?php if ($mode === 'search' && $grid_stats): ?>
<!-- ── Fixed grid stats footer (search mode only) ──────────────────────── -->
<footer class="login-footer pp-stats-footer" role="contentinfo">
    <div class="login-footer-identity">
        <span class="footer-grid-name"><?= htmlspecialchars(defined('GRID_DISPLAY_NAME') ? GRID_DISPLAY_NAME : GRID_NAME) ?></span>
        <?php if (defined('GRID_LOGIN_URI') && GRID_LOGIN_URI !== ''): ?>
        <span class="footer-identity-sep" aria-hidden="true">·</span>
        <span class="footer-login-uri-label">Login URI:</span>
        <span class="footer-login-uri"><?= htmlspecialchars(GRID_LOGIN_URI) ?></span>
        <?php endif ?>
    </div>
    <dl class="footer-stats">
        <div class="footer-stat">
            <dt>Status</dt>
            <dd class="stats-online">
                <span class="stats-status-dot" aria-hidden="true"></span>Online
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
</footer>
<?php endif ?>


<!-- Pick detail modal -->
<div class="pick-modal-overlay" id="ppPickModal"
     aria-hidden="true" role="dialog" aria-modal="true"
     aria-labelledby="ppPickModalTitle">
    <div class="pick-modal">
        <div class="pick-modal-img-wrap">
            <img id="ppPickModalImg" src="" alt="">
        </div>
        <div class="pick-modal-body">
            <h2 class="pick-modal-name" id="ppPickModalTitle"></h2>
            <div class="pick-modal-location" id="ppPickModalLocation"></div>
            <p class="pick-modal-desc" id="ppPickModalDesc"></p>
        </div>
        <div class="pick-modal-footer">
            <button class="pick-modal-close" onclick="closePpPickModal()">Close</button>
        </div>
    </div>
</div>


<script>
/* ── Live search ───────────────────────────────────────────────────────────── */
(function () {
    const input   = document.getElementById('ppSearchInput');
    const results = document.getElementById('ppResults');
    if (!input || !results) return;

    let debounceTimer = null;
    let lastQuery     = '';

    input.addEventListener('input', function () {
        const q = this.value.trim();
        clearTimeout(debounceTimer);

        if (q.length < 2) {
            closeResults();
            return;
        }
        if (q === lastQuery) return;

        debounceTimer = setTimeout(() => doSearch(q), 280);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeResults(); return; }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const first = results.querySelector('.pp-result-item');
            if (first) first.focus();
        }
    });

    // Arrow-key navigation within results
    results.addEventListener('keydown', function (e) {
        const items = [...results.querySelectorAll('.pp-result-item')];
        const idx   = items.indexOf(document.activeElement);
        if (e.key === 'ArrowDown' && idx < items.length - 1) {
            e.preventDefault(); items[idx + 1].focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (idx <= 0) input.focus();
            else items[idx - 1].focus();
        } else if (e.key === 'Escape') {
            closeResults(); input.focus();
        }
    });

    // Close results when clicking outside
    document.addEventListener('click', function (e) {
        if (!document.getElementById('ppSearchWrap').contains(e.target)) {
            closeResults();
        }
    });

    function doSearch(q) {
        lastQuery = q;
        results.innerHTML = '<div class="pp-results-searching">Searching…</div>';
        results.classList.add('open');

        fetch('includes/public_profile_data.php?action=search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { showError(data.error || 'Search failed.'); return; }
                renderResults(data.results, q);
            })
            .catch(() => showError('Search failed. Please try again.'));
    }

    function renderResults(items, q) {
        if (items.length === 0) {
            results.innerHTML = '<div class="pp-results-empty">No public profiles found for "' + escHtml(q) + '"</div>';
            return;
        }
        results.innerHTML = items.map(r =>
            `<a class="pp-result-item"
                href="publicprofile.php?view=${encHtml(r.uuid)}"
                role="option"
                tabindex="0">
                <span class="pp-result-name">${escHtml(r.fullname)}</span>
                <span class="pp-result-uuid">${escHtml(r.uuid)}</span>
             </a>`
        ).join('');
    }

    function showError(msg) {
        results.innerHTML = '<div class="pp-results-empty">' + escHtml(msg) + '</div>';
    }

    function closeResults() {
        results.classList.remove('open');
        results.innerHTML = '';
        lastQuery = '';
    }

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function encHtml(s) {
        return encodeURIComponent(s);
    }
})();

/* ── Pick modal ─────────────────────────────────────────────────────────────── */
function openPpPickModal(card) {
    const overlay  = document.getElementById('ppPickModal');
    const name     = card.dataset.name    || 'Unnamed';
    const img      = card.dataset.img     || '';
    const sim      = card.dataset.sim     || '';
    const x        = card.dataset.x;
    const y        = card.dataset.y;
    const z        = card.dataset.z;
    const descHtml = card.dataset.descHtml || '';

    document.getElementById('ppPickModalImg').src            = img;
    document.getElementById('ppPickModalImg').alt            = name;
    document.getElementById('ppPickModalTitle').textContent  = name;
    document.getElementById('ppPickModalDesc').innerHTML     = descHtml;

    const locEl = document.getElementById('ppPickModalLocation');
    if (sim) {
        const coords = (x || y || z) ? ` (${x}, ${y}, ${z})` : '';
        locEl.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/></svg>${sim}${coords}`;
    } else {
        locEl.textContent = '';
    }

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    overlay.querySelector('.pick-modal-close').focus();
}

function closePpPickModal() {
    const overlay = document.getElementById('ppPickModal');
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.getElementById('ppPickModal').addEventListener('click', function(e) {
    if (e.target === this) closePpPickModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('ppPickModal').classList.contains('open')) {
        closePpPickModal();
    }
});
</script>

</body>
</html>
<?php
/**
 * Alias — delegates to the centralised linkify() in helpers.php.
 * Kept so existing call-sites in this file don't need renaming.
 */
function ppLinkify(string $escaped_html): string
{
    return linkify($escaped_html);
}
