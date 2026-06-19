<?php
/**
 * helpers.php — Shared UI components
 *
 * Provides reusable functions for elements that appear on every authenticated
 * page: the top navigation bar, the slide-out side drawer, the theme & display
 * modal, and all CSS / JS they depend on.
 *
 * Usage in any page:
 *
 *   require_once __DIR__ . '/includes/helpers.php';
 *   require_once __DIR__ . '/includes/theme_loader.php';
 *
 *   $prefs      = get_portal_prefs();
 *   $bg_enabled = $prefs['bg'];
 *
 *   // Inside <head>:
 *   render_shared_css();   // emits a single <link> to the active theme CSS
 *
 *   // At the very start of <body>:
 *   render_bg_layer();
 *   render_navbar($full_name, $status_class, $status_label, $status_tooltip);
 *   render_drawer($active_page, [], $userlevel, $has_estate_access);
 *
 *   // At the very end of <body>, before </body>:
 *   render_theme_modal($bg_enabled);
 *   render_shared_js();
 *
 * The $active_page string must match one of the keys used inside render_drawer()
 * so the correct menu item receives the "active" highlight.
 *
 * THEME SYSTEM
 * ------------
 * All CSS now lives in /themes/<name>/<name>.css.  render_shared_css() emits a
 * <link> tag pointing at the active theme file (determined by the portal_prefs
 * cookie via theme_loader.php).
 *
 * Images (logo, nav header) are stored inside the theme folder and
 * referenced via CSS custom properties (--theme-logo-nav, --theme-logo-drawer,
 * --theme-logo-login) defined in each theme's CSS, or via theme_image_url().
 * Background images are handled separately: render_bg_layer() picks a random
 * image from the active theme's /bgimages/ folder via theme_bg_image_url()
 * and applies it as an inline style — see theme_loader.php.
 * PHP HTML that used to hard-code /images/ paths now reads these via JS or lets
 * CSS handle the img content property; the <img> src attributes still point to
 * the theme directory for the fallback (see render_navbar / render_drawer).
 *
 * COOKIE
 * ------
 * A single JSON cookie 'portal_prefs' stores { "theme": "default", "bg": true }.
 * The JS setPortalPrefs(patch) helper merges a partial object and re-saves.
 * The old portal_bg cookie is automatically migrated by theme_loader.php.
 */

declare(strict_types=1);

// theme_loader.php must have been required by the calling page, but guard here
// so helpers can be unit-tested in isolation.
if (!function_exists('get_portal_prefs')) {
    require_once __DIR__ . '/theme_loader.php';
}


// ─── Text helpers ─────────────────────────────────────────────────────────────

/**
 * Linkify URLs in already-htmlspecialchars'd text.
 *
 * Must be called AFTER htmlspecialchars() — the input is escaped HTML, not
 * raw text (so & has become &amp; in URLs).
 *
 * Two patterns are handled, in order:
 *
 *  1. Firestorm-style named links:  [https://url.com Label Text]
 *     These are converted to <a href="https://url.com">Label Text</a>.
 *     The URL must be the first token (no spaces); everything after the
 *     first space up to the closing ] is used as the display label.
 *     If there is no space (just a URL in brackets), it falls through to
 *     pattern 2 as a bare URL.
 *
 *  2. Plain URLs: http(s)://... with no bracket wrapping, converted to
 *     <a href="...">https://...</a> as before.
 *
 * Pattern 1 is applied first, replacing the whole bracket construct with an
 * <a> tag so the plain-URL pass does not double-process the href inside it.
 *
 * @param  string $escaped_html   Output of htmlspecialchars()
 * @return string                 HTML with URLs wrapped in <a> tags
 */
function linkify(string $escaped_html): string
{
    // ── Pass 1: Firestorm [https://url Label] syntax ──────────────────────────
    // Pattern: literal [ then a URL (no spaces), then at least one space,
    // then label text (no ] allowed), then literal ]
    // The URL may contain &amp; (escaped &) so we allow that sequence.
    $bracket_pattern = '~\[(https?://(?:(?!&amp;)[^\s\[\]<>"\']|&amp;)+)\s([^\]]+)\]~i';

    $escaped_html = preg_replace_callback($bracket_pattern, function (array $m): string {
        $href    = str_replace('&amp;', '&', $m[1]);
        $label   = trim($m[2]); // trim any accidental leading/trailing spaces in the label
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" '
             . 'target="_blank" rel="noopener noreferrer" '
             . 'class="inline-link">' . $label . '</a>';
    }, $escaped_html) ?? $escaped_html;

    // ── Pass 2: Plain bare URLs ───────────────────────────────────────────────
    // Skip URLs that are already inside an href="..." attribute (from pass 1).
    // The negative lookbehind for href=" handles that without needing to parse HTML.
    $plain_pattern = '~(?<!href=")https?://(?:(?!&amp;)[^\s<>"\']|&amp;)+(?<![.,)!\]?])~i';

    return preg_replace_callback($plain_pattern, function (array $m): string {
        $display = $m[0];
        $href    = str_replace('&amp;', '&', $m[0]);
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" '
             . 'target="_blank" rel="noopener noreferrer" '
             . 'class="inline-link">' . $display . '</a>';
    }, $escaped_html) ?? $escaped_html;
}

/**
 * Map a region's maturity rating to its display label.
 *
 * IMPORTANT — this keys off regionsettings.maturity (a simple 0/1/2 index),
 * NOT regions.access. Both fields exist in the OpenSim DB and BOTH looked
 * plausible at first (regions.access uses OpenSim's SimAccess enum — 13/21/42
 * — and even correlates with maturity most of the time), but experimentation
 * proved regions.access is a derived/mirror value: the simulator recomputes
 * and overwrites it from regionsettings.maturity on every region restart,
 * with no warning and no log trace. regionsettings.maturity is the field the
 * in-world Region/Estate dialog actually writes to, and the only one that
 * survives a restart — confirmed against live data on Sub-Version Suburbs
 * (Castle): setting regionsettings.maturity directly and restarting left it
 * unchanged, while a prior direct write to regions.access alone was silently
 * reverted by the same restart.
 *
 * Do NOT "fix" this by reading regions.access again — that field is fine to
 * leave alone (the simulator manages it), but it is NOT the source of truth
 * for display or for any write path. See change_maturity.php's docblock for
 * the corresponding write-side note.
 *
 *   0 -> General
 *   1 -> Moderate
 *   2 -> Adult
 *
 * @param  int $maturity  regionsettings.maturity value (0, 1, or 2)
 * @return string         Display label
 */
function region_maturity_label(int $maturity): string
{
    return match ($maturity) {
        0       => 'General',
        1       => 'Moderate',
        2       => 'Adult',
        default => 'Unknown',
    };
}

/**
 * Valid regionsettings.maturity values, in display order, paired with their
 * label. Single source of truth for both region_maturity_label()'s reverse
 * mapping and for validating incoming maturity-change requests — see
 * change_maturity.php. Keeping this as one ordered list (rather than
 * duplicating the 0/1/2 literals at each call site) means the General/
 * Moderate/Adult ordering only has to be decided once.
 *
 * NOTE: these are regionsettings.maturity values (0/1/2), NOT regions.access
 * SimAccess values (13/21/42) — see region_maturity_label() docblock for why
 * that distinction matters and is easy to get wrong.
 *
 * @return array<int,string>  e.g. [0 => 'General', 1 => 'Moderate', 2 => 'Adult']
 */
function region_maturity_options(): array
{
    return [
        0 => 'General',
        1 => 'Moderate',
        2 => 'Adult',
    ];
}

/**
 * Is a documented OpenSim-database-write feature enabled?
 *
 * Returns true only if BOTH the master switch (ALLOW_OS_DATABASE_WRITES)
 * AND the named feature constant are defined and true. The master switch
 * always takes precedence — if it's false or undefined, this returns false
 * regardless of the feature flag's own value. See config.php — "OpenSim
 * database write exceptions" for the features this currently gates
 * (ENABLE_PARTNERSHIPS, ENABLE_CHANGE_MATURITY).
 *
 * @param  string $feature_constant  Name of the feature-specific constant
 *                                    (e.g. 'ENABLE_PARTNERSHIPS')
 * @return bool
 */
function os_write_feature_enabled(string $feature_constant): bool
{
    if (!defined('ALLOW_OS_DATABASE_WRITES') || !ALLOW_OS_DATABASE_WRITES) {
        return false;
    }
    return defined($feature_constant) && constant($feature_constant) === true;
}

/**
 * Can this user change the maturity rating of the given region, view its
 * live status/stats, or send it a restart/broadcast?
 *
 * Permission is granted to:
 *   - the estate owner of the estate the region belongs to,
 *   - an estate manager of that estate, OR
 *   - any user with UserLevel meeting the 'Grid Staff' tier (see
 *     user_level_meets() / config.php's USERLEVEL_LABELS), regardless
 *     of estate ownership.
 *
 * Deliberately reuses user_has_estate_access_to_region() (includes/estates.php)
 * for the owner/manager check rather than re-querying estate_settings /
 * estate_managers directly, so the rule stays identical across every
 * per-region action gated the same way: change_maturity.php,
 * region_status.php, region_stats_data.php, region_restart.php, and
 * region_broadcast.php all call this SAME function — one tier, one
 * function, no per-endpoint drift.
 *
 * This is what makes the region detail modal's status/stats/restart/
 * broadcast/maturity actions all work from the Administrator "All Estates"
 * tool (all_estates.php) for a Grid Staff or Administrator user viewing a
 * region they don't personally own/manage — that page's own access gate is
 * 'Grid Staff' (see all_estates.php's docblock), and this function matches
 * it exactly, so "can see this region on All Estates" and "can act on it"
 * stay consistent. The Console button on the region modal is the one
 * exception — it stays gated to 'Administrator' directly (a stricter,
 * separate check; see regions.php/all_estates.php's region modal markup),
 * since full unrestricted simulator console access is a different class of
 * risk than restart/broadcast/maturity.
 *
 * @param  PDO    $db          Database connection
 * @param  string $uuid        Acting user's PrincipalID
 * @param  int    $userlevel   Acting user's UserLevel
 * @param  string $region_uuid regions.uuid of the region being viewed/changed
 * @return bool
 */
function user_can_manage_region_maturity(PDO $db, string $uuid, int $userlevel, string $region_uuid): bool
{
    if (user_level_meets($userlevel, 'Grid Staff')) {
        return true;
    }
    return user_has_estate_access_to_region($db, $uuid, $region_uuid);
}

/**
 * Can this user VIEW the Estate Tools modal (owner/manager roster, estate
 * details) for the given estate?
 *
 * Permission is granted to:
 *   - the estate owner,
 *   - any manager of that estate, OR
 *   - any user with UserLevel meeting the 'Grid Staff' tier (see
 *     user_level_meets() / config.php's USERLEVEL_LABELS), regardless
 *     of estate ownership.
 *
 * Deliberately reuses user_is_estate_owner_or_manager() (includes/estates.php)
 * rather than re-querying estate_settings/estate_managers directly, mirroring
 * user_can_manage_region_maturity()'s reuse of user_has_estate_access_to_region().
 *
 * Grid Staff tier (not Administrator) matches the "All Estates" tool's own
 * page-level access gate (see all_estates.php's docblock) — anyone who can
 * see an estate on that page can also view and manage its Estate Tools
 * modal (roster view + add/remove manager). Changing the estate's OWNER is
 * a stricter, separate action — see user_can_change_estate_owner() below,
 * which stays Administrator-only.
 *
 * @param  PDO    $db        Database connection
 * @param  string $uuid      Acting user's PrincipalID
 * @param  int    $userlevel Acting user's UserLevel
 * @param  int    $estate_id EstateID being viewed
 * @return bool
 */
function user_can_view_estate_tools(PDO $db, string $uuid, int $userlevel, int $estate_id): bool
{
    if (user_level_meets($userlevel, 'Grid Staff')) {
        return true;
    }
    return user_is_estate_owner_or_manager($db, $uuid, $estate_id);
}

/**
 * Can this user ADD or REMOVE estate managers for the given estate?
 *
 * Strictly owner-only on the estate side — see user_is_estate_owner()'s
 * docblock (includes/estates.php) for why managers must never be able to
 * add/remove managers, including themselves. The UserLevel branch is the
 * exception: any user meeting the 'Grid Staff' tier can manage any
 * estate's roster without being its owner — same tier as
 * user_can_view_estate_tools() above, so "can view this estate's Estate
 * Tools" and "can add/remove its managers" stay consistent for Grid Staff.
 * Changing the estate's OWNER itself is a stricter, separate action — see
 * user_can_change_estate_owner() below, which stays Administrator-only.
 *
 * Gated additionally behind os_write_feature_enabled('ENABLE_ESTATE_MANAGER_EDIT')
 * by the calling endpoint (estate_tools.php) — this function only checks
 * WHO may act, not whether the feature itself is turned on.
 *
 * @param  PDO    $db        Database connection
 * @param  string $uuid      Acting user's PrincipalID
 * @param  int    $userlevel Acting user's UserLevel
 * @param  int    $estate_id EstateID being modified
 * @return bool
 */
function user_can_manage_estate_managers(PDO $db, string $uuid, int $userlevel, int $estate_id): bool
{
    if (user_level_meets($userlevel, 'Grid Staff')) {
        return true;
    }
    return user_is_estate_owner($db, $uuid, $estate_id);
}

/**
 * Can this user change the OWNER of the given estate (estate_settings.EstateOwner)?
 *
 * Unlike user_can_manage_estate_managers() above, this is Administrator-tier
 * ONLY — never the estate's own owner or managers, since by definition this
 * action changes who that owner is. An estate owner reassigning ownership to
 * someone else is exactly the kind of "ownership-equivalent power" that
 * should require a step up to Administrator, not be self-service.
 *
 * Gated additionally behind os_write_feature_enabled('ENABLE_ESTATE_OWNER_TRANSFER')
 * by the calling endpoint (estate_tools.php) — this function only checks
 * WHO may act, not whether the feature itself is turned on.
 *
 * @param  int $userlevel Acting user's UserLevel
 * @return bool
 */
function user_can_change_estate_owner(int $userlevel): bool
{
    return user_level_meets($userlevel, 'Administrator');
}


// ─── HTML: news feed (public display) ────────────────────────────────────────

/**
 * Render the inner content of a "Grid News" panel (login.php / splash.php /
 * admin.php) given a list of posts.
 *
 * Posts are simple wrapped plain text — htmlspecialchars'd and linkified.
 * Line breaks are preserved via CSS `white-space: pre-wrap` (see
 * .news-post-body in default.css), so no nl2br() is needed.
 *
 * Emits either the empty-state message or one .news-post-entry per post.
 * Does NOT emit the surrounding .login-news-content wrapper or the panel
 * heading — callers already provide those.
 *
 * @param array  $posts   Result of get_visible_news_posts() / get_all_news_posts()
 * @param string $layout  'stacked' (default — meta above body, used on the
 *                         login page's narrower pane) or 'split' (meta in a
 *                         1/3-width left column, body in a 2/3-width right
 *                         column — used on splash.php; admin.php builds its
 *                         own markup directly for the admin list).
 */
function render_news_feed_html(array $posts, string $layout = 'stacked'): void
{
    if (empty($posts)) {
        echo '<p class="login-news-empty">No announcements at this time.</p>' . "\n";
        return;
    }

    $entry_class = $layout === 'split' ? 'news-post-entry split' : 'news-post-entry';

    foreach ($posts as $post) {
        $body   = linkify(htmlspecialchars($post['body']));
        $author = htmlspecialchars($post['author_name']);
        $when   = htmlspecialchars(date('j M Y, H:i', strtotime($post['posted_at'])));
        ?>
        <div class="<?= $entry_class ?>">
            <?php if ($layout === 'split'): ?>
            <div class="news-post-meta">
                <span><?= $author ?></span>
                <span><?= $when ?></span>
            </div>
            <?php else: ?>
            <div class="news-post-meta"><?= $author ?> &middot; <?= $when ?></div>
            <?php endif; ?>
            <div class="news-post-body"><?= $body ?></div>
        </div>
        <?php
    }
}


// ─── CSS ─────────────────────────────────────────────────────────────────────

/**
 * Emit the Google Fonts preconnect + the <link> tag for the active theme CSS.
 *
 * This replaces the old inline <style> block.  All design tokens, component
 * styles, and page-specific styles are now in the theme CSS file.
 */
function render_shared_css(): void
{
    $css_url = htmlspecialchars(get_active_theme_css_url(), ENT_QUOTES);
    echo '    <link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;1,400&family=Inter:wght@300;400;500;600&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">' . "\n";
    echo '    <link rel="stylesheet" href="' . $css_url . '">' . "\n";
}


// ─── HTML: background layer ───────────────────────────────────────────────────

/**
 * Emit the fixed background-image div.
 * Must be the first element inside <body>.
 *
 * Picks a random image from the active theme's /bgimages/ folder (falling
 * back to the default theme's bgimages if the active theme has none) and
 * applies it inline. A different image may appear on each page load.
 *
 * @param string|null $theme  Theme to source the image from, defaults to
 *                             the active theme. Logged-out pages (which have
 *                             no portal_prefs-driven "active theme" in the
 *                             usual sense) should pass DEFAULT_THEME explicitly.
 */
function render_bg_layer(?string $theme = null): void
{
    $bg_url = theme_bg_image_url($theme);
    if ($bg_url !== null) {
        $style = ' style="background-image: url(\'' . htmlspecialchars($bg_url, ENT_QUOTES) . '\')"';
    } else {
        $style = '';
    }
    echo '<div class="bg-image" aria-hidden="true"' . $style . '></div>' . "\n";
}


// ─── HTML: navbar ─────────────────────────────────────────────────────────────

/**
 * Emit the sticky top navigation bar.
 *
 * The logo image src is resolved via theme_image_url(), which checks the
 * active theme folder first and falls back to themes/default/ automatically.
 * Child themes only need to include headerimageblurred.png if they want a
 * different logo; otherwise the default one is used.
 *
 * @param string $full_name      Escaped display name shown in the nav right.
 * @param string $status_class   One of: status-online | status-away | status-offline
 * @param string $status_label   Human-readable label: Online / Away? / Offline
 * @param string $status_tooltip Full tooltip text (will be htmlspecialchars'd here).
 */
function render_navbar(
    string $full_name,
    string $status_class,
    string $status_label,
    string $status_tooltip,
    int    $unread_notifications = 0
): void {
    $tooltip_attr = htmlspecialchars($status_tooltip, ENT_QUOTES);
    $grid_name    = htmlspecialchars(GRID_NAME, ENT_QUOTES);
    $logo_src     = htmlspecialchars(theme_image_url('headerimageblurred.png'), ENT_QUOTES);
    ?>
<nav class="topnav" role="navigation" aria-label="Main navigation">

    <!-- Hamburger -->
    <button class="nav-hamburger" id="hamburgerBtn"
            aria-label="Open menu" aria-expanded="false" aria-controls="mainDrawer">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <!-- Grid logo — absolutely centred, links to profile (home) -->
    <a href="profile.php" class="nav-title" aria-label="<?= $grid_name ?> — home">
        <img src="<?= htmlspecialchars($logo_src) ?>" alt="<?= $grid_name ?>">
    </a>

    <!-- Flex spacer pushes nav-right to the far right -->
    <span style="flex:1" aria-hidden="true"></span>

    <!-- Right: notifications · status · username · logout -->
    <div class="nav-right">

        <!-- Notifications bell -->
        <div class="nav-notif-wrap" id="notifWrap">
            <button class="nav-notif-btn" id="notifBtn" type="button"
                    aria-label="Notifications<?php if ($unread_notifications > 0) echo ' (' . $unread_notifications . ' unread)'; ?>"
                    aria-expanded="false" aria-controls="notifPanel"
                    onclick="toggleNotifPanel()">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <?php if ($unread_notifications > 0): ?>
                <span class="nav-notif-badge" aria-hidden="true"><?php
                    echo $unread_notifications > 9 ? '9+' : $unread_notifications;
                ?></span>
                <?php endif; ?>
            </button>

            <!-- Notification drop-down panel -->
            <div class="notif-panel" id="notifPanel" aria-hidden="true" role="region" aria-label="Notifications">
                <div class="notif-panel-header">
                    <span class="notif-panel-title">Notifications</span>
                    <button class="notif-panel-close" onclick="closeNotifPanel()" aria-label="Close notifications">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
                <div class="notif-panel-body" id="notifPanelBody">
                    <p class="notif-loading">Loading…</p>
                </div>
            </div>
        </div>

        <span class="status-badge-wrap" data-tooltip="<?= $tooltip_attr ?>">
            <span class="status-badge <?= htmlspecialchars($status_class) ?>"
                  tabindex="0" aria-label="<?= $tooltip_attr ?>">
                <span class="status-dot" aria-hidden="true"></span>
                <?= htmlspecialchars($status_label) ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5"
                     aria-hidden="true" style="opacity:0.5;flex-shrink:0;margin-left:1px">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
            </span>
        </span>

        <span class="nav-username"><?= $full_name ?></span>

        <button class="btn-logout" type="button" onclick="confirmLogout()">Sign out</button>
    </div>
</nav>
    <?php
}


// ─── HTML: side drawer ────────────────────────────────────────────────────────

/**
 * Emit the slide-out side drawer and its backdrop overlay.
 *
 * @param string $active_page  Key of the currently active page. Supported values:
 *                             'profile' | 'account' | 'change_password' |
 *                             'friends' | 'messages' | 'theme' | 'regions' |
 *                             'admin_approvals' | 'admin_news' | 'admin_online' | 'console'
 *                             ('admin_approvals' / 'admin_news' / 'admin_online'
 *                             also highlight the parent "Administration" item.)
 *                             Any unknown value leaves all items inactive.
 * @param array  $user_roles   (Future use) role/permission flags for the logged-in
 *                             user.  Pass an empty array [] for now.
 * @param int    $userlevel    The user's UserLevel for role-gated menu items.
 * @param bool   $has_estate_access  Whether the user is an estate owner or
 *                             manager for at least one estate (see
 *                             includes/estates.php — user_has_estate_access()).
 *                             Controls visibility of the "My Estates" item,
 *                             independently of UserLevel.
 */
function render_drawer(string $active_page, array $user_roles = [], int $userlevel = 0, bool $has_estate_access = false): void
{
    $grid_name = htmlspecialchars(GRID_NAME, ENT_QUOTES);
    $logo_src  = htmlspecialchars(theme_image_url('headerimageblurred.png'), ENT_QUOTES);

    // Returns active class + aria attribute when the item matches current page
    $active = fn(string $page): string =>
        $active_page === $page ? ' class="active" aria-current="page"' : '';
    ?>
<!-- Backdrop overlay -->
<div class="drawer-overlay" id="drawerOverlay"
     aria-hidden="true" role="presentation"></div>

<!-- Side drawer -->
<aside class="drawer" id="mainDrawer"
       aria-label="Site navigation" aria-hidden="true">

    <div class="drawer-header">
        <img src="<?= htmlspecialchars($logo_src) ?>" alt="<?= $grid_name ?>">
    </div>

    <ul class="drawer-nav" role="list">

        <li>
            <a href="profile.php"<?= $active('profile') ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                </svg>
                Profile
            </a>
        </li>

        <li>
            <a href="account.php"<?= $active('account') ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                Account
            </a>
        </li>

        <li>
            <a href="change_password.php"<?= $active('change_password') ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                Change Password
            </a>
        </li>

        <li>
            <a href="friends.php"<?= $active('friends') ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                Friends
            </a>
        </li>

        <li>
            <a href="messages.php"<?= $active('messages') ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                Offline Messages
            </a>
        </li>

        <li>
            <a href="#"<?= $active('theme') ?>
               onclick="openThemeModal(); closeDrawer(); return false;">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                </svg>
                Theme &amp; Display
            </a>
        </li>

        <?php if ($has_estate_access): ?>
        <!-- My Estates — visible to estate owners/managers (any UserLevel) -->
        <li>
            <a href="regions.php"<?= $active('regions') ?>>
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="2" y="3" width="20" height="14" rx="2"/>
                    <path d="M8 21h8M12 17v4"/>
                </svg>
                My Estates
            </a>
        </li>
        <?php endif; ?>

        <?php if (user_level_meets($userlevel, 'Grid Staff')): ?>
        <!-- Administration — visible to grid staff (meeting the 'Grid Staff' tier) and above -->
        <!-- Collapsible submenu; auto-expanded when a child page is active. -->
        <?php $admin_active = ($active_page === 'admin_approvals' || $active_page === 'admin_news' || $active_page === 'admin_online' || $active_page === 'console' || $active_page === 'all_estates'); ?>
        <li class="drawer-has-submenu<?= $admin_active ? ' open' : '' ?>">
            <button type="button" class="drawer-submenu-toggle<?= $admin_active ? ' active' : '' ?>"
                    aria-expanded="<?= $admin_active ? 'true' : 'false' ?>"
                    onclick="toggleDrawerSubmenu(this)">
                <svg class="nav-icon" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                <span class="drawer-submenu-label">Administration</span>
                <svg class="drawer-chevron" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <polyline points="9 6 15 12 9 18"/>
                </svg>
            </button>
            <ul class="drawer-submenu" role="list">
                <li>
                    <a href="admin.php?section=approvals"<?= $active('admin_approvals') ?>>
                        Account Approvals
                    </a>
                </li>
                <li>
                    <a href="admin.php?section=news"<?= $active('admin_news') ?>>
                        Grid News
                    </a>
                </li>
                <li>
                    <a href="admin.php?section=online"<?= $active('admin_online') ?>>
                        Who's Online
                    </a>
                </li>
                <!-- All Estates — visible to users meeting the 'Grid Staff' tier
                     (same as the rest of this Administration submenu) — see
                     all_estates.php's docblock for the full per-action tier
                     breakdown (page access + most region actions are Grid
                     Staff; Change Owner within Estate Tools stays
                     Administrator-only). -->
                <li>
                    <a href="all_estates.php"<?= $active('all_estates') ?>>
                        All Estates
                    </a>
                </li>
                <?php if (user_level_meets($userlevel, 'Administrator') && defined('ENABLE_REST_CONSOLE') && ENABLE_REST_CONSOLE): ?>
                <!-- Console — visible to users meeting the 'Administrator' tier only -->
                <li>
                    <a href="console.php"<?= $active('console') ?>>
                        Console
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </li>
        <?php endif; ?>

    </ul>
</aside>
<style>
/* ── Drawer submenu toggle (e.g. Administration) ───────────────────────── */
.drawer-submenu-toggle {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    padding: 10px 14px;
    background: none;
    border: none;
    border-radius: var(--radius-md);
    font-family: var(--font-body);
    font-size: 0.95rem;
    color: var(--clr-text-primary);
    cursor: pointer;
    text-align: left;
}
.drawer-submenu-toggle:hover {
    background: var(--clr-surface-2);
}
.drawer-submenu-toggle.active {
    color: var(--clr-lilac-text);
    background: var(--clr-surface-2);
    font-weight: 500;
}
.drawer-submenu-label {
    flex: 1;
}
.drawer-chevron {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    transition: transform 0.2s ease;
}
.drawer-has-submenu.open .drawer-chevron {
    transform: rotate(90deg);
}

/* ── Drawer submenu (e.g. Administration > Account Approvals / News & Updates) ── */
.drawer-submenu {
    list-style: none;
    margin: 0;
    padding: 0;
    border-left: 2px solid var(--clr-border-light);
    margin-left: 32px;
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.25s ease;
}
.drawer-has-submenu.open .drawer-submenu {
    max-height: 200px;
    margin: 2px 0 4px;
    margin-left: 32px;
}
.drawer-submenu li a {
    display: block;
    padding: 8px 12px;
    font-size: 0.88rem;
    color: var(--clr-text-secondary);
    text-decoration: none;
    border-radius: var(--radius-md);
}
.drawer-submenu li a:hover {
    color: var(--clr-text-primary);
    background: var(--clr-surface-2);
}
.drawer-submenu li a.active {
    color: var(--clr-lilac-text);
    font-weight: 500;
    background: var(--clr-surface-2);
}
</style>
    <?php
}


// ─── HTML: theme & display modal ─────────────────────────────────────────────

/**
 * Emit the Theme & Display modal overlay.
 *
 * Now includes:
 *  1. Theme picker — one swatch per discovered theme, with thumbnail or
 *     a placeholder gradient.  Clicking a swatch calls setTheme(name) in JS.
 *  2. Background image toggle — same as before.
 *
 * Should appear at the end of <body>, before render_shared_js().
 *
 * @param bool   $bg_enabled    Whether the background image toggle is on.
 */
function render_theme_modal(bool $bg_enabled): void
{
    $themes       = discover_themes();
    $active_theme = get_active_theme();

    // Only show the theme picker section if there is more than one theme
    $show_picker = count($themes) > 1;
    ?>
<!-- ══════════════════════════════════════════════════════════════════════
     THEME & DISPLAY MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div class="theme-modal-overlay" id="themeModalOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="themeModalTitle" aria-hidden="true">
    <div class="theme-modal">
        <div class="theme-modal-header">
            <span class="theme-modal-title" id="themeModalTitle">Theme &amp; Display</span>
            <button class="btn-close-theme"
                    onclick="closeThemeModal()"
                    aria-label="Close theme settings">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/>
                    <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>

        <div class="theme-modal-body">

            <?php if ($show_picker): ?>
            <!-- ── Theme chooser ──────────────────────────────────────── -->
            <div>
                <p class="theme-section-label">Theme</p>
                <div class="theme-picker" role="radiogroup" aria-label="Select theme">
                    <?php foreach ($themes as $t): ?>
                    <?php
                        $is_active  = $t['name'] === $active_theme;
                        $label      = ucfirst(str_replace(['-', '_'], ' ', $t['name']));
                        $swatch     = $t['swatch'];
                        $main_clr   = htmlspecialchars($swatch['main'], ENT_QUOTES);
                        $accent_clr = htmlspecialchars($swatch['accent'], ENT_QUOTES);
                    ?>
                    <button class="theme-swatch<?= $is_active ? ' active' : '' ?>"
                            onclick="setTheme(<?= htmlspecialchars(json_encode($t['name']), ENT_QUOTES) ?>)"
                            role="radio"
                            aria-checked="<?= $is_active ? 'true' : 'false' ?>"
                            aria-label="<?= htmlspecialchars($label, ENT_QUOTES) ?> theme"
                            title="<?= htmlspecialchars($label, ENT_QUOTES) ?>">
                        <span class="theme-swatch-img"
                              style="background: linear-gradient(135deg, <?= $main_clr ?> 0%, <?= $main_clr ?> 55%, <?= $accent_clr ?> 55%, <?= $accent_clr ?> 100%);"></span>
                        <span class="theme-swatch-name"><?= htmlspecialchars($label) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Background toggle ─────────────────────────────────── -->
            <div class="theme-row">
                <div class="theme-row-label">
                    <span class="theme-row-title">Background image</span>
                    <span class="theme-row-desc">
                        Show the decorative background on portal pages
                    </span>
                </div>
                <label class="toggle-wrap" aria-label="Toggle background image">
                    <input type="checkbox" id="bgToggle"
                           onchange="setBgEnabled(this.checked)"
                           <?= $bg_enabled ? 'checked' : '' ?>>
                    <span class="toggle-track"></span>
                </label>
            </div>

        </div>

        <div class="theme-modal-footer">
            Your display preferences are saved in a cookie on this device.
        </div>
    </div>
</div>
    <?php
}


// ─── HTML: logout confirmation modal ──────────────────────────────────────────

/**
 * Emit the shared "Sign out?" confirmation modal.
 *
 * Replaces the browser-native confirm() previously used by confirmLogout()
 * (and the per-page custom modals formerly duplicated on friends.php and
 * messages.php). The open/close/doLogout JS lives in render_shared_js().
 *
 * Should appear alongside render_theme_modal(), before render_shared_js().
 */
function render_logout_modal(): void
{
    ?>
<!-- ══════════════════════════════════════════════════════════════════════
     LOGOUT CONFIRMATION MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div class="logout-overlay" id="logoutOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="logoutTitle" aria-hidden="true">
    <div class="logout-card">
        <h2 id="logoutTitle">Sign out?</h2>
        <p>You'll need to sign in again to access your portal.</p>
        <div class="logout-actions">
            <button class="btn-cancel" onclick="cancelLogout()">Cancel</button>
            <button class="btn-primary-pill" onclick="doLogout()">Sign out</button>
        </div>
    </div>
</div>
    <?php
}


// ─── HTML: generic action confirmation modal ──────────────────────────────────

/**
 * Emit a shared, generic "are you sure?" confirmation modal.
 *
 * Reuses the same visual style as the logout modal (.logout-overlay /
 * .logout-card / .logout-actions / .btn-cancel / .btn-primary-pill) so that
 * confirmations look consistent across the portal instead of relying on the
 * browser-native confirm() dialog.
 *
 * Title and message are filled in dynamically via JS (openConfirmModal()),
 * and the confirm button submits whichever <form> was passed to it.
 *
 * Should appear alongside render_theme_modal() / render_logout_modal(),
 * before render_shared_js().
 */
function render_confirm_modal(): void
{
    ?>
<!-- ══════════════════════════════════════════════════════════════════════
     GENERIC CONFIRMATION MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div class="logout-overlay" id="confirmOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="confirmTitle" aria-hidden="true">
    <div class="logout-card">
        <h2 id="confirmTitle">Are you sure?</h2>
        <p id="confirmMessage"></p>
        <div class="logout-actions">
            <button class="btn-cancel" onclick="cancelConfirmModal()">Cancel</button>
            <button class="btn-primary-pill" id="confirmActionBtn" onclick="doConfirmAction()">Confirm</button>
        </div>
    </div>
</div>
    <?php
}


// ─── HTML: region restart confirmation modal ──────────────────────────────────

/**
 * Emit the shared "Restart region?" confirmation modal.
 *
 * Reuses the same visual style as the logout/confirm modals (.logout-overlay
 * / .logout-card / .logout-actions / .btn-cancel / .btn-primary-pill).
 * Unlike render_confirm_modal(), this offers TWO confirm actions rather than
 * one — a 10-second restart and a 60-second restart — since
 * region_restart.php accepts a delay of "10" or "60". Both options always
 * show an in-world alert (OpenSim has no true silent/instant option — see
 * region_restart.php's docblock for why), so button labels just state the
 * delay rather than mentioning the alert.
 *
 * Region name is filled in dynamically via JS (openRestartModal()), and
 * both confirm buttons trigger restartRegion(delay) directly (regions.php)
 * rather than submitting a form.
 *
 * Should appear alongside render_theme_modal() / render_logout_modal() /
 * render_confirm_modal(), before render_shared_js().
 */
function render_restart_modal(): void
{
    ?>
<!-- ══════════════════════════════════════════════════════════════════════
     REGION RESTART CONFIRMATION MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div class="logout-overlay" id="restartOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="restartTitle" aria-hidden="true">
    <div class="logout-card">
        <h2 id="restartTitle">Restart region?</h2>
        <p id="restartMessage"></p>
        <div class="logout-actions">
            <button class="btn-cancel" onclick="cancelRestartModal()">Cancel</button>
            <button class="btn-primary-pill" onclick="restartRegion(60)">Restart in 60 seconds</button>
            <button class="btn-primary-pill" onclick="restartRegion(10)">Restart in 10 seconds</button>
        </div>
    </div>
</div>
    <?php
}


// ─── HTML: region broadcast modal ──────────────────────────────────────────────

/**
 * Emit the "Send message to region" modal — a small popup with a
 * description of what the action does, a textarea, a Send button, and a
 * Close button.
 *
 * Unlike render_restart_modal() (which is a confirm-only step before
 * restartRegion() runs) and render_maturity_modal() (which fires
 * immediately on click), this modal collects free-text input before
 * submitting — closer in shape to a small form than a confirmation dialog,
 * so it reuses .logout-card for the chrome but adds its own textarea +
 * status line.
 *
 * Region name is filled in dynamically via JS (openBroadcastModal()), and
 * the Send button calls sendRegionBroadcast() (regions.php), which POSTs to
 * region_broadcast.php — a fetch() call, not a form submission, matching
 * the restart/maturity pattern.
 *
 * No feature flag gate — admin_broadcast carries no destructive risk (it
 * only displays an in-world alert), so unlike render_maturity_modal()
 * (gated on ENABLE_CHANGE_MATURITY) this always renders. Callers gate the
 * *button* that opens it on the same per-region access check already used
 * for "Restart region" (user_has_estate_access_to_region(), enforced
 * server-side in region_broadcast.php) — there is no separate Administrator
 * requirement, since anyone who can see a region's panel on "My Estates"
 * already qualifies.
 *
 * Should appear alongside render_theme_modal() / render_logout_modal() /
 * render_confirm_modal() / render_restart_modal() / render_maturity_modal(),
 * before render_shared_js().
 */
function render_broadcast_modal(): void
{
    ?>
<!-- ══════════════════════════════════════════════════════════════════════
     SEND MESSAGE TO REGION MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div class="logout-overlay" id="broadcastModalOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="broadcastModalTitle" aria-hidden="true">
    <div class="logout-card broadcast-modal-card">
        <h2 id="broadcastModalTitle">Send message to region</h2>
        <p class="broadcast-modal-description">
            Shows an in-world alert message to everyone currently in this
            region. The message appears immediately — there's no delay or
            warning countdown, and nobody is disconnected.
        </p>
        <div class="form-group">
            <textarea class="form-input broadcast-modal-textarea" id="broadcastModalText"
                      maxlength="512" rows="3"
                      placeholder="Type the message to broadcast…"></textarea>
        </div>
        <p class="broadcast-modal-status" id="broadcastModalStatus"></p>
        <div class="logout-actions">
            <button class="btn-cancel" onclick="closeBroadcastModal()">Close</button>
            <button class="btn-primary-pill" id="broadcastModalSendBtn" onclick="sendRegionBroadcast()">Send</button>
        </div>
    </div>
</div>
    <?php
}


// ─── HTML: region maturity modal ──────────────────────────────────────────────

/**
 * Emit the "Change region maturity" modal — a radiogroup of the three
 * SimAccess options (General/Moderate/Adult), each as its own button.
 *
 * Unlike render_restart_modal() / render_confirm_modal(), there is no
 * separate confirm step: clicking an option immediately POSTs the change
 * (changeMaturity(value) in regions.php) and closes the modal on success.
 * This is a deliberate UX decision — see Things_to_do.md / CLAUDE.md — the
 * picker itself carries a static warning that the change requires a region
 * restart to take effect, but does NOT chain into the restart modal or
 * trigger a restart automatically. Restarting stays a fully separate,
 * deliberate action via the existing "Restart region" button.
 *
 * Only emits anything if the feature is enabled — see
 * os_write_feature_enabled('ENABLE_CHANGE_MATURITY') in includes/helpers.php.
 * Callers should still gate the *pill* that opens this modal on
 * user_can_manage_region_maturity() for the region being viewed, since this
 * modal itself has no per-region access check (that happens server-side in
 * change_maturity.php).
 *
 * Should appear alongside render_theme_modal() / render_logout_modal() /
 * render_confirm_modal() / render_restart_modal(), before render_shared_js().
 */
function render_maturity_modal(): void
{
    if (!os_write_feature_enabled('ENABLE_CHANGE_MATURITY')) {
        return;
    }
    ?>
<!-- ══════════════════════════════════════════════════════════════════════
     REGION MATURITY MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div class="logout-overlay" id="maturityModalOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="maturityModalTitle" aria-hidden="true">
    <div class="logout-card maturity-modal-card">
        <h2 id="maturityModalTitle">Change region maturity</h2>
        <p class="maturity-modal-warning">
            Changing maturity here updates the grid database, but the running
            region won't reflect it until it's next restarted. Use the
            "Restart region" button afterwards if you want the change to take
            effect now.
        </p>
        <div class="maturity-picker" role="radiogroup" aria-label="Select region maturity" id="maturityPicker">
            <?php foreach (region_maturity_options() as $value => $label): ?>
            <button type="button" class="maturity-option" data-maturity="<?= $value ?>"
                    onclick="changeMaturity(<?= $value ?>)"
                    role="radio" aria-checked="false">
                <?= htmlspecialchars($label) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <p class="maturity-modal-status" id="maturityModalStatus"></p>
        <div class="logout-actions">
            <button class="btn-cancel" onclick="closeMaturityModal()">Cancel</button>
        </div>
    </div>
</div>
    <?php
}


// ─── HTML: REST console modal ────────────────────────────────────────────────

/**
 * Emit the shared REST Console modal — an interactive console window
 * connected to a region's simulator (or ROBUST), per RestConsole.md.
 *
 * Usage: call once per page (alongside render_theme_modal() /
 * render_logout_modal() / render_confirm_modal(), before render_shared_js()),
 * then call openConsoleModal(target, label) from a button's onclick, where:
 *
 *   target — 'robust' or a region UUID (regions.uuid)
 *   label  — display name shown in the modal title (e.g. region name)
 *
 * Only emits anything if the REST console feature is enabled
 * (ENABLE_REST_CONSOLE) — callers should still gate the *button* that opens
 * it on user_level_meets($userlevel, 'Administrator'), since this modal
 * grants full, unrestricted console access to whatever target it's pointed
 * at.
 */
function render_console_modal(): void
{
    if (!defined('ENABLE_REST_CONSOLE') || !ENABLE_REST_CONSOLE) {
        return;
    }
    ?>
<!-- ══════════════════════════════════════════════════════════════════════
     REST CONSOLE MODAL
═══════════════════════════════════════════════════════════════════════ -->
<div class="console-overlay" id="consoleOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="consoleModalTitle" aria-hidden="true">
    <div class="console-modal">
        <div class="console-modal-header">
            <h2 class="console-modal-title" id="consoleModalTitle">Console</h2>
            <button type="button" class="region-modal-close" onclick="closeConsoleModal()" aria-label="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="console-modal-body">
            <p class="console-status" id="consoleStatus">Ready.</p>

            <div class="console-quick-commands" id="consoleQuickCommands">
                <span class="console-quick-label">Quick commands:</span>
                <button type="button" class="console-quick-btn" onclick="consoleRunOnce('show uptime')">Uptime</button>
                <button type="button" class="console-quick-btn" onclick="consoleRunOnce('show users')">Users</button>
                <button type="button" class="console-quick-btn" onclick="consoleRunOnce('show connections')">Connections</button>
                <button type="button" class="console-quick-btn" onclick="consoleRunOnce('show region')">Region info</button>
                <button type="button" class="console-quick-btn" onclick="consoleRunOnce('show regions')">All regions</button>
            </div>

            <div class="console-output" id="consoleOutput" role="log" aria-live="polite"></div>

            <form class="console-input-row" id="consoleInputForm" onsubmit="return consoleSubmitCommand(event)">
                <span class="console-prompt">&gt;</span>
                <input type="text" class="console-input" id="consoleInput"
                       autocomplete="off" autocapitalize="off" spellcheck="false"
                       placeholder="Type a command and press Enter…">
                <button type="submit" class="btn-primary" id="consoleSendBtn">Run</button>
            </form>
        </div>
        <div class="console-modal-footer">
            <button type="button" class="pick-modal-close" onclick="closeConsoleModal()">Close</button>
        </div>
    </div>
</div>
<style>
/* ── REST Console modal ─────────────────────────────────────────────── */
.console-overlay {
    position: fixed; inset: 0;
    background: rgba(20, 10, 40, 0.7);
    backdrop-filter: blur(4px);
    z-index: 600;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0; pointer-events: none; transition: opacity 0.2s;
}
.console-overlay.open { opacity: 1; pointer-events: all; }
.console-modal {
    background: var(--clr-surface);
    border-radius: var(--radius-xl, var(--radius-lg));
    box-shadow: 0 20px 60px rgba(20, 10, 40, 0.4);
    width: 100%; max-width: 860px;
    transform: translateY(12px) scale(0.98); transition: transform 0.2s;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 85vh;
}
.console-overlay.open .console-modal { transform: translateY(0) scale(1); }
.console-modal-header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--clr-border-light);
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px;
    flex-shrink: 0;
}
.console-modal-title {
    font-family: var(--font-display);
    font-size: 1.3rem;
    font-weight: 400;
    color: var(--clr-text-primary);
    margin: 0;
    word-break: break-word;
}
.region-modal-close {
    background: none; border: none; cursor: pointer;
    color: var(--clr-text-secondary);
    padding: 4px; border-radius: var(--radius-md);
    flex-shrink: 0;
    display: flex; align-items: center;
}
.region-modal-close:hover { color: var(--clr-text-primary); background: var(--clr-surface-2); }
.console-modal-body {
    padding: 16px 22px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 0;
    flex: 1;
}
.console-status {
    margin: 0;
    font-size: 0.8rem;
    color: var(--clr-text-secondary);
}
.console-status.is-error {
    color: var(--clr-rose-deep, #b3486b);
}
.console-quick-commands {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.console-quick-label {
    font-size: 0.78rem;
    font-weight: 500;
    color: var(--clr-text-secondary);
    margin-right: 2px;
}
.console-quick-btn {
    font-size: 0.78rem;
    font-weight: 500;
    padding: 6px 12px;
    border-radius: var(--radius-pill, 999px);
    border: 1px solid var(--clr-border-light);
    background: var(--clr-surface-2);
    color: var(--clr-text-primary);
    cursor: pointer;
    transition: background 0.15s;
}
.console-quick-btn:hover { background: var(--clr-lilac-soft); }
.console-quick-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.console-output {
    flex: 1;
    min-height: 320px;
    max-height: 50vh;
    overflow-y: auto;
    background: #1b1622;
    color: #e6e1ee;
    border-radius: var(--radius-md);
    padding: 12px 14px;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
    font-size: 0.8rem;
    line-height: 1.5;
    white-space: pre-wrap;
    word-break: break-word;
}
.console-output .console-line { display: block; }
.console-output .console-line-input { color: #9d9ad8; }
.console-output .console-line-prompt { color: #e6e1ee; font-weight: 600; }
.console-output .console-line-debug { color: #8a8a9a; }
.console-output .console-line-info  { color: #8fd0e8; }
.console-output .console-line-warn  { color: #f0c674; }
.console-output .console-line-error { color: #f08a8a; }
.console-output .console-line-fatal { color: #ff6b6b; font-weight: 700; }
.console-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
}
.console-prompt {
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
    font-size: 0.85rem;
    color: var(--clr-text-secondary);
    flex-shrink: 0;
}
.console-input {
    flex: 1;
    min-width: 0;
    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
    font-size: 0.85rem;
    padding: 9px 12px;
    border-radius: var(--radius-md);
    border: 1px solid var(--clr-border-light);
    background: var(--clr-surface-2);
    color: var(--clr-text-primary);
}
.console-input:disabled { opacity: 0.6; }
.console-modal-footer {
    padding: 14px 22px;
    border-top: 1px solid var(--clr-border-light);
    display: flex;
    justify-content: flex-end;
    flex-shrink: 0;
}
</style>
    <?php
}


// ─── JS ──────────────────────────────────────────────────────────────────────

/**
 * Emit the <script> block for drawer, theme modal, logout confirmation,
 * background toggle, and theme switcher.
 *
 * The unified portal_prefs JSON cookie replaces the old portal_bg cookie.
 * setPortalPrefs(patch) merges a partial object into the stored prefs and
 * saves it with a 1-year expiry.
 *
 * Pages that have additional modals should add their own <script> after this
 * call and extend the Escape handler if needed.
 */
function render_shared_js(): void
{
    $active_theme   = addslashes(get_active_theme());
    $prefs          = get_portal_prefs();
    $prefs_json     = json_encode($prefs, JSON_UNESCAPED_UNICODE);
    ?>
<script>
/* ── Shared utility ──────────────────────────────────────────────── */
function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* ── Portal prefs cookie (unified) ──────────────────────────────── */
// Current prefs as read by PHP on page load — used as the base for merges.
let _portalPrefs = <?= $prefs_json ?>;

function setPortalPrefs(patch) {
    Object.assign(_portalPrefs, patch);
    const expires = new Date();
    expires.setFullYear(expires.getFullYear() + 1);
    document.cookie = '<?= PORTAL_PREFS_COOKIE ?>=' + encodeURIComponent(JSON.stringify(_portalPrefs))
        + '; expires=' + expires.toUTCString()
        + '; path=/; SameSite=Lax';
}

/* ── Drawer / hamburger ──────────────────────────────────────────── */
const hamburgerBtn  = document.getElementById('hamburgerBtn');
const mainDrawer    = document.getElementById('mainDrawer');
const drawerOverlay = document.getElementById('drawerOverlay');

function openDrawer() {
    mainDrawer.classList.add('open');
    drawerOverlay.classList.add('open');
    mainDrawer.setAttribute('aria-hidden', 'false');
    drawerOverlay.setAttribute('aria-hidden', 'false');
    hamburgerBtn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    const spans = hamburgerBtn.querySelectorAll('span');
    spans[0].style.transform = 'translateY(7px) rotate(45deg)';
    spans[1].style.opacity   = '0';
    spans[2].style.transform = 'translateY(-7px) rotate(-45deg)';
}

function closeDrawer() {
    mainDrawer.classList.remove('open');
    drawerOverlay.classList.remove('open');
    mainDrawer.setAttribute('aria-hidden', 'true');
    drawerOverlay.setAttribute('aria-hidden', 'true');
    hamburgerBtn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    const spans = hamburgerBtn.querySelectorAll('span');
    spans[0].style.transform = '';
    spans[1].style.opacity   = '';
    spans[2].style.transform = '';
}

hamburgerBtn.addEventListener('click', () => {
    mainDrawer.classList.contains('open') ? closeDrawer() : openDrawer();
});
drawerOverlay.addEventListener('click', closeDrawer);

/* ── Drawer collapsible submenus (e.g. Administration) ─────────────── */
function toggleDrawerSubmenu(btn) {
    const li = btn.closest('.drawer-has-submenu');
    const isOpen = li.classList.toggle('open');
    btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
}


/* ── Theme modal ─────────────────────────────────────────────────── */
function openThemeModal() {
    const o = document.getElementById('themeModalOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
}
function closeThemeModal() {
    const o = document.getElementById('themeModalOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
}
document.getElementById('themeModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeThemeModal();
});

/* ── Theme switcher ──────────────────────────────────────────────── */
// Saves the new theme to the cookie and reloads so the new CSS is applied.
function setTheme(name) {
    if (name === _portalPrefs.theme) return; // already active
    setPortalPrefs({ theme: name });
    window.location.reload();
}

/* ── Background toggle ───────────────────────────────────────────── */
function setBgEnabled(enabled) {
    setPortalPrefs({ bg: enabled });
    document.body.classList.toggle('no-bg', !enabled);
}

/* ── Logout confirmation (modal) ──────────────────────────────────── */
function confirmLogout() {
    const o = document.getElementById('logoutOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
}
function cancelLogout() {
    const o = document.getElementById('logoutOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
}
function doLogout() { window.location.href = 'logout.php'; }
document.getElementById('logoutOverlay').addEventListener('click', function(e) {
    if (e.target === this) cancelLogout();
});

/* ── Generic confirmation modal ─────────────────────────────────── */
// Opens a "Sign out?"-style modal for confirming an arbitrary form
// submission. formId is the id of the <form> to submit when the user
// confirms; confirmClass lets callers opt into the destructive (rose)
// confirm button style via .btn-primary-pill-destructive.
let _confirmFormId = null;
function openConfirmModal(title, message, formId, confirmLabel, confirmClass) {
    document.getElementById('confirmTitle').textContent   = title;
    document.getElementById('confirmMessage').textContent = message;
    const btn = document.getElementById('confirmActionBtn');
    btn.textContent = confirmLabel || 'Confirm';
    btn.className = confirmClass || 'btn-primary-pill';
    _confirmFormId = formId;
    const o = document.getElementById('confirmOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
}
function cancelConfirmModal() {
    const o = document.getElementById('confirmOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
    _confirmFormId = null;
}
function doConfirmAction() {
    if (_confirmFormId) {
        const form = document.getElementById(_confirmFormId);
        if (form) form.submit();
    }
    cancelConfirmModal();
}
document.getElementById('confirmOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) cancelConfirmModal();
});

/* ── Region restart confirmation modal ──────────────────────────── */
// Opens the "Restart region?" modal for a given region name. The two
// confirm buttons call restartRegion(delay) directly (defined in
// regions.php) rather than submitting a form, since the action is a
// fetch() POST to region_restart.php with delay=10 or delay=60.
function openRestartModal(regionName) {
    document.getElementById('restartTitle').textContent = 'Restart "' + regionName + '"?';
    document.getElementById('restartMessage').textContent =
        'Everyone currently in the region will see a restart warning, and ' +
        'will be disconnected when the region comes back up a short time later.';
    const o = document.getElementById('restartOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
}
function cancelRestartModal() {
    const o = document.getElementById('restartOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
}
document.getElementById('restartOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) cancelRestartModal();
});

/* ── Region broadcast modal ────────────────────────────────────── */
// Opens the "Send message to region" modal for a given region. Unlike the
// restart modal, this has no confirm step beyond the Send button itself —
// sendRegionBroadcast() (regions.php) reads the textarea and POSTs to
// region_broadcast.php directly. openBroadcastModal() resets the textarea
// and status line each time it's opened, so leftover text/status from a
// previous region or a previous send doesn't carry over.
function openBroadcastModal(regionName) {
    document.getElementById('broadcastModalTitle').textContent = 'Send message to "' + regionName + '"';
    const textEl = document.getElementById('broadcastModalText');
    textEl.value = '';
    document.getElementById('broadcastModalStatus').textContent = '';
    const sendBtn = document.getElementById('broadcastModalSendBtn');
    sendBtn.disabled = false;
    sendBtn.textContent = 'Send';
    const o = document.getElementById('broadcastModalOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
    textEl.focus();
}
function closeBroadcastModal() {
    const o = document.getElementById('broadcastModalOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
}
document.getElementById('broadcastModalOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) closeBroadcastModal();
});

/* ── Region maturity modal ─────────────────────────────────────── */
// Opens the "Change region maturity" modal. Unlike the restart modal,
// clicking an option calls changeMaturity(value) (defined in regions.php)
// directly — there is no separate confirm step, see render_maturity_modal()
// docblock. openMaturityModal() just handles showing the overlay and
// marking the current value's radio button as checked/active;
// closeMaturityModal() resets that state and clears any status message.
function openMaturityModal(currentMaturity) {
    const picker = document.getElementById('maturityPicker');
    if (!picker) return;
    picker.querySelectorAll('.maturity-option').forEach(function(btn) {
        const isCurrent = Number(btn.dataset.maturity) === Number(currentMaturity);
        btn.classList.toggle('active', isCurrent);
        btn.setAttribute('aria-checked', isCurrent ? 'true' : 'false');
        btn.disabled = false;
    });
    document.getElementById('maturityModalStatus').textContent = '';
    const o = document.getElementById('maturityModalOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
}
function closeMaturityModal() {
    const o = document.getElementById('maturityModalOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
}
document.getElementById('maturityModalOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) closeMaturityModal();
});

/* ── Shared Escape key handler ───────────────────────────────────── */
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;
    if (document.getElementById('confirmOverlay')?.classList.contains('open')) {
        cancelConfirmModal();
    } else if (document.getElementById('restartOverlay')?.classList.contains('open')) {
        cancelRestartModal();
    } else if (document.getElementById('broadcastModalOverlay')?.classList.contains('open')) {
        closeBroadcastModal();
    } else if (document.getElementById('maturityModalOverlay')?.classList.contains('open')) {
        closeMaturityModal();
    } else if (document.getElementById('logoutOverlay').classList.contains('open')) {
        cancelLogout();
    } else if (document.getElementById('themeModalOverlay').classList.contains('open')) {
        closeThemeModal();
    } else if (document.getElementById('consoleOverlay')?.classList.contains('open')) {
        closeConsoleModal();
    } else if (document.getElementById('notifPanel')?.getAttribute('aria-hidden') === 'false') {
        closeNotifPanel();
    } else if (mainDrawer.classList.contains('open')) {
        closeDrawer();
    }
});

/* ── Notification panel ──────────────────────────────────────────── */
let _notifLoaded = false;

function toggleNotifPanel() {
    const panel = document.getElementById('notifPanel');
    const btn   = document.getElementById('notifBtn');
    const open  = panel.getAttribute('aria-hidden') === 'false';
    if (open) {
        closeNotifPanel();
    } else {
        panel.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
        if (!_notifLoaded) loadNotifications();
    }
}

function closeNotifPanel() {
    const panel = document.getElementById('notifPanel');
    const btn   = document.getElementById('notifBtn');
    if (panel) {
        panel.setAttribute('aria-hidden', 'true');
        btn.setAttribute('aria-expanded', 'false');
    }
}

// Close panel when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifWrap');
    if (wrap && !wrap.contains(e.target)) closeNotifPanel();
});

function loadNotifications() {
    _notifLoaded = true;
    const body = document.getElementById('notifPanelBody');
    body.innerHTML = '<p class="notif-loading">Loading…</p>';

    fetch('notifications_data.php?csrf=' + encodeURIComponent(PORTAL_CSRF))
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error(data.error || 'Failed to load');
            renderNotifications(data.notifications);
        })
        .catch(err => {
            body.innerHTML = '<p class="notif-empty">Could not load notifications.</p>';
        });
}

function renderNotifications(notifications) {
    const body = document.getElementById('notifPanelBody');
    const badge = document.querySelector('.nav-notif-badge');

    if (!notifications || notifications.length === 0) {
        body.innerHTML = '<p class="notif-empty">No new notifications.</p>';
        if (badge) badge.remove();
        return;
    }

    let html = '';
    notifications.forEach(n => {
        const timeStr = formatNotifTime(n.created_at);
        if (n.type === 'partner_offer') {
            html += `<div class="notif-item" data-id="${n.id}">
                <div class="notif-item-icon notif-icon-heart">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none">
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                    </svg>
                </div>
                <div class="notif-item-body">
                    <p class="notif-item-msg">${escapeHtml(n.message)}</p>
                    <span class="notif-item-time">${timeStr}</span>
                    <div class="notif-item-actions">
                        <button class="notif-btn-accept" onclick="handlePartnerOffer(${n.id}, '${escapeHtml(n.from_uuid)}', true)">Accept</button>
                        <button class="notif-btn-decline" onclick="handlePartnerOffer(${n.id}, '${escapeHtml(n.from_uuid)}', false)">Decline</button>
                    </div>
                </div>
            </div>`;
        } else {
            // Generic / system notification
            html += `<div class="notif-item" data-id="${n.id}">
                <div class="notif-item-icon notif-icon-system">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="notif-item-body">
                    <p class="notif-item-msg">${escapeHtml(n.message)}</p>
                    <span class="notif-item-time">${timeStr}</span>
                    <div class="notif-item-actions">
                        <button class="notif-btn-dismiss" onclick="dismissNotification(${n.id})">Dismiss</button>
                    </div>
                </div>
            </div>`;
        }
    });
    body.innerHTML = html;
}

function handlePartnerOffer(notifId, fromUuid, accept) {
    const action = accept ? 'accept' : 'decline';
    const item   = document.querySelector(`.notif-item[data-id="${notifId}"]`);
    if (item) {
        item.querySelector('.notif-item-actions').innerHTML =
            '<span class="notif-item-processing">Processing…</span>';
    }

    const fd = new FormData();
    fd.append('csrf', PORTAL_CSRF);
    fd.append('action', action);
    fd.append('notification_id', notifId);

    fetch('partner_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error(data.error || 'Action failed');
            if (item) {
                item.innerHTML = `<div class="notif-item-done">
                    ${accept ? '💕 Partnership accepted!' : 'Offer declined.'}
                </div>`;
                setTimeout(() => {
                    item.remove();
                    updateBadgeAfterDismiss();
                    if (accept) window.location.reload(); // refresh to show partner
                }, 1800);
            }
        })
        .catch(err => {
            if (item) {
                item.querySelector('.notif-item-actions').innerHTML =
                    `<span class="notif-item-error">${escapeHtml(err.message)}</span>`;
            }
        });
}

function dismissNotification(notifId) {
    const item = document.querySelector(`.notif-item[data-id="${notifId}"]`);

    fetch('notifications_data.php?csrf=' + encodeURIComponent(PORTAL_CSRF)
          + '&action=mark_read&id=' + notifId)
        .then(r => r.json())
        .then(data => {
            if (item) {
                item.remove();
                updateBadgeAfterDismiss();
            }
        });
}

function updateBadgeAfterDismiss() {
    const remaining = document.querySelectorAll('.notif-item').length;
    const badge = document.querySelector('.nav-notif-badge');
    const body  = document.getElementById('notifPanelBody');
    if (remaining === 0) {
        if (badge) badge.remove();
        if (body)  body.innerHTML = '<p class="notif-empty">No new notifications.</p>';
    } else if (badge) {
        badge.textContent = remaining > 9 ? '9+' : remaining;
    }
}

function formatNotifTime(ts) {
    const age = Math.floor(Date.now() / 1000) - ts;
    if (age < 60)          return 'just now';
    if (age < 3600)        return Math.round(age / 60) + 'm ago';
    if (age < 86400)       return Math.round(age / 3600) + 'h ago';
    return Math.round(age / 86400) + 'd ago';
}

/* ── REST Console modal ───────────────────────────────────────────── */
let _consoleTarget  = null;
let _consoleRunning = false;

function openConsoleModal(target, label) {
    _consoleTarget = target;
    _consoleRunning = false;

    document.getElementById('consoleModalTitle').textContent = 'Console — ' + label;
    document.getElementById('consoleOutput').innerHTML = '';
    consoleSetStatus('Ready.', false);

    const input = document.getElementById('consoleInput');
    const sendBtn = document.getElementById('consoleSendBtn');
    input.value = '';
    input.disabled = false;
    sendBtn.disabled = false;

    document.querySelectorAll('#consoleQuickCommands .console-quick-btn').forEach(btn => btn.disabled = false);

    const o = document.getElementById('consoleOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
    input.focus();
}

/**
 * Run a single console command to completion and append its output —
 * used by both the preset "Quick Commands" buttons and the free-text
 * input below. Each call is a single request/response via
 * console_oneshot.php (StartSession -> SessionCommand -> ReadResponses ->
 * CloseSession server-side); nothing is left open between calls.
 */
function consoleRunOnce(command) {
    if (!_consoleTarget || _consoleRunning) return;
    const target = _consoleTarget;

    const out = document.getElementById('consoleOutput');
    out.insertAdjacentHTML('beforeend',
        '<span class="console-line console-line-input">&gt; ' + escapeHtml(command) + '</span>\n');
    out.scrollTop = out.scrollHeight;

    const quickButtons = document.querySelectorAll('#consoleQuickCommands .console-quick-btn');
    const sendBtn = document.getElementById('consoleSendBtn');
    _consoleRunning = true;
    quickButtons.forEach(btn => btn.disabled = true);
    sendBtn.disabled = true;
    consoleSetStatus('Running "' + command + '"…', false);

    const fd = new FormData();
    fd.append('csrf', PORTAL_CSRF);
    fd.append('target', target);
    fd.append('command', command);

    fetch('console_oneshot.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            _consoleRunning = false;
            if (_consoleTarget !== target) return;
            quickButtons.forEach(btn => btn.disabled = false);
            sendBtn.disabled = false;
            if (!data.ok) {
                consoleSetStatus(data.error || 'Could not run command.', true);
                return;
            }
            if (data.html) {
                out.insertAdjacentHTML('beforeend', data.html);
                out.scrollTop = out.scrollHeight;
            }
            consoleSetStatus('Ready.', false);
        })
        .catch(() => {
            _consoleRunning = false;
            if (_consoleTarget !== target) return;
            quickButtons.forEach(btn => btn.disabled = false);
            sendBtn.disabled = false;
            consoleSetStatus('Could not run command.', true);
        });
}

function closeConsoleModal() {
    const o = document.getElementById('consoleOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
    _consoleTarget = null;
}

document.getElementById('consoleOverlay')?.addEventListener('click', function(e) {
    if (e.target === this) closeConsoleModal();
});

function consoleSetStatus(text, isError) {
    const el = document.getElementById('consoleStatus');
    el.textContent = text;
    el.classList.toggle('is-error', !!isError);
}

function consoleSubmitCommand(e) {
    e.preventDefault();
    const input = document.getElementById('consoleInput');
    const command = input.value.trim();
    if (command === '') return false;
    input.value = '';
    consoleRunOnce(command);
    input.focus();
    return false;
}
</script>
    <?php
}


// ─── Notifications ────────────────────────────────────────────────────────────

/**
 * Return the number of unread portal notifications for the given UUID.
 * Returns 0 silently if the table does not exist or any error occurs.
 *
 * @param  string $uuid  The user's PrincipalID
 * @return int
 */
function get_unread_notification_count(string $uuid): int
{
    if (!function_exists('get_db')) {
        return 0;
    }
    try {
        $db   = get_db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM portal_notifications WHERE to_uuid = ? AND is_read = 0"
        );
        $stmt->execute([$uuid]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}


// ─── Shared: presence state helpers ──────────────────────────────────────────

/**
 * Build the status_class, status_label, and status_tooltip strings from the
 * presence data returned by get_session_user().
 *
 * @param  array $session_user  Return value of get_session_user()
 * @return array{status_class: string, status_label: string, status_tooltip: string}
 */
function build_presence_display(array $session_user): array
{
    $presence    = $session_user['presence'];
    $p_status    = $presence['status'];     // 'online' | 'away' | 'offline'
    $p_last_seen = $presence['last_seen'];  // Unix timestamp or null

    $status_label = match($p_status) {
        'online' => 'Online',
        'away'   => 'Away?',
        default  => 'Offline',
    };
    $status_class = match($p_status) {
        'online' => 'status-online',
        'away'   => 'status-away',
        default  => 'status-offline',
    };

    $disclaimer = 'Presence data may be inaccurate — hypergrid visitors can appear '
                . 'online after logging out if the logout signal did not reach this grid.';

    if ($p_last_seen !== null) {
        $age_secs = time() - $p_last_seen;
        if ($age_secs < 60) {
            $age_str = 'just now';
        } elseif ($age_secs < 3600) {
            $m = (int)round($age_secs / 60);
            $age_str = $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        } elseif ($age_secs < 86400) {
            $h = (int)round($age_secs / 3600);
            $age_str = $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        } else {
            $d = (int)round($age_secs / 86400);
            $age_str = $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }
        $status_tooltip = ucfirst($p_status === 'away' ? 'Possibly online' : 'Online')
                        . ' · last activity ' . $age_str . '. ' . $disclaimer;
    } else {
        $status_tooltip = 'Offline. ' . $disclaimer;
    }

    return compact('status_class', 'status_label', 'status_tooltip');
}


// ─── Shared: in-world IM delivery ────────────────────────────────────────────

/**
 * Send a system/portal-generated instant message to a resident.
 *
 * This is the single entry point used by partner_action.php (and any future
 * feature) for portal -> resident IMs.
 *
 * Delivery is always written to im_offline (see send_offline_im_database()) —
 * this is the guaranteed, zero-dependency path. The simulator delivers it on
 * the recipient's next login regardless of their current online status.
 *
 * Additionally, a best-effort "heads up" is sent via the in-world relay
 * object if one has ever checked in (i.e. a row exists in
 * portal_inworld_relay — see send_offline_im_relay()). No separate config
 * flag is needed: the presence of a checked-in relay object is itself the
 * signal that this feature is in use. llInstantMessage() means users who are
 * currently online see the heads-up immediately, in Nearby Chat / IM history
 * rather than a named conversation tab (normal behaviour for
 * object-originated IMs). Any failure (no relay checked in, network error,
 * timeout) is logged and silently ignored — the database write above has
 * already guaranteed delivery via the normal offline-message queue.
 *
 * @param PDO    $db       Database connection
 * @param string $to_uuid  Recipient PrincipalID
 * @param string $message  Message text
 */
function send_offline_im(PDO $db, string $to_uuid, string $message): void
{
    send_offline_im_database($db, $to_uuid, $message);

    if (!send_offline_im_relay($db, $to_uuid, $message)) {
        // No relay object has checked in, or the heads-up failed to send.
        // Not an error condition — database delivery above is sufficient.
        error_log('send_offline_im: in-world relay heads-up not sent for ' . $to_uuid . ' (database delivery already completed)');
    }
}

/**
 * Insert an offline IM into the im_offline table.
 *
 * Messages are sent FROM the grid robot account (GRID_ROBOT_UUID) using a
 * fixed imSessionID (GRID_ROBOT_SESSION_UUID) so all portal-generated IMs
 * always land in the same conversation tab in the viewer, regardless of how
 * many messages are sent or when. Since no real user sends IMs from the grid
 * robot account, there is no risk of session ID collision with genuine messages.
 *
 * The simulator delivers the message live if the recipient is online, or
 * stores it for next login if offline — exactly like any other IM. In practice,
 * a direct im_offline INSERT is only picked up on next login regardless of
 * online status, because it bypasses the simulator's live messaging entirely.
 *
 * @param PDO    $db       Database connection
 * @param string $to_uuid  Recipient PrincipalID
 * @param string $message  Message text
 */
function send_offline_im_database(PDO $db, string $to_uuid, string $message): void
{
    $from_uuid    = defined('GRID_ROBOT_UUID')         ? GRID_ROBOT_UUID         : '00000000-0000-0000-0000-000000000000';
    $from_name    = defined('GRID_ROBOT_NAME')         ? GRID_ROBOT_NAME         : 'Grid Services';
    $session_uuid = defined('GRID_ROBOT_SESSION_UUID') ? GRID_ROBOT_SESSION_UUID : '99999999-9999-9999-9999-999999999999';

    $xml = '<?xml version="1.0" encoding="utf-8"?>'
         . '<GridInstantMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" '
         . 'xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
         . '<fromAgentID>'   . htmlspecialchars($from_uuid,    ENT_XML1) . '</fromAgentID>'
         . '<fromAgentName>' . htmlspecialchars($from_name,    ENT_XML1) . '</fromAgentName>'
         . '<toAgentID>'     . htmlspecialchars($to_uuid,      ENT_XML1) . '</toAgentID>'
         . '<dialog>0</dialog>'
         . '<fromGroup>false</fromGroup>'
         . '<message>'       . htmlspecialchars($message,      ENT_XML1) . '</message>'
         . '<imSessionID>'   . htmlspecialchars($session_uuid, ENT_XML1) . '</imSessionID>'
         . '<offline>0</offline>'
         . '<Position><X>0</X><Y>0</Y><Z>0</Z></Position>'
         . '<binaryBucket></binaryBucket>'
         . '<ParentEstateID>0</ParentEstateID>'
         . '<RegionID>00000000-0000-0000-0000-000000000000</RegionID>'
         . '<timestamp>'     . time() . '</timestamp>'
         . '</GridInstantMessage>';

    $stmt = $db->prepare(
        "INSERT INTO im_offline (PrincipalID, FromID, Message, TMStamp)
         VALUES (?, ?, ?, NOW())"
    );
    $stmt->execute([$to_uuid, $from_uuid, $xml]);
}

/**
 * Send a best-effort "heads up" IM through the in-world relay object
 * (see inworld_relay.lsl).
 *
 * Looks up the object's last known HTTPIN URL from portal_inworld_relay and
 * POSTs a JSON payload of the form:
 *
 *   {"access_code": "...", "to_uuid": "<recipient PrincipalID>", "message": "..."}
 *
 * The object validates access_code, then calls
 * llInstantMessage(to_uuid, message). If the recipient is online they see
 * this immediately (in Nearby Chat / IM history, not a named conversation
 * tab — normal for object-originated IMs); if offline, OpenSim queues it
 * normally too, but the guaranteed offline delivery is already handled by
 * send_offline_im_database(), so this is purely supplementary.
 *
 * Returns false (never throws) on any failure — no row in
 * portal_inworld_relay (i.e. no relay object has ever checked in), cURL
 * error, non-2xx response, or timeout. The caller treats false as "no
 * heads-up sent" and does not need to take any further action.
 *
 * A short timeout is used so a relay object that has gone offline (e.g. the
 * region restarted and llRequestURL() issued a new URL without the object
 * checking in again) cannot stall the portal request.
 *
 * @param  PDO    $db       Database connection
 * @param  string $to_uuid  Recipient PrincipalID
 * @param  string $message  Message text
 * @return bool             True if the relay accepted the heads-up, false otherwise
 */
function send_offline_im_relay(PDO $db, string $to_uuid, string $message): bool
{
    try {
        $stmt = $db->prepare('SELECT httpin_url FROM portal_inworld_relay WHERE id = 1 LIMIT 1');
        $stmt->execute();
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('send_offline_im_relay: portal_inworld_relay lookup failed: ' . $e->getMessage());
        return false;
    }

    if (!$row || empty($row['httpin_url'])) {
        return false;
    }

    $access_code = defined('INWORLD_RELAY_ACCESS_CODE') ? INWORLD_RELAY_ACCESS_CODE : '';

    $payload = json_encode([
        'access_code' => $access_code,
        'to_uuid'     => $to_uuid,
        'message'     => $message,
    ]);

    $ch = curl_init($row['httpin_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_errno = curl_errno($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_errno !== 0) {
        error_log('send_offline_im_relay: cURL error: ' . $curl_error);
        return false;
    }

    if ($http_code < 200 || $http_code >= 300) {
        error_log('send_offline_im_relay: relay returned HTTP ' . $http_code . ': ' . substr((string)$response, 0, 200));
        return false;
    }

    return true;
}
