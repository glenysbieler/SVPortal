<?php
/**
 * regions.php — "My Estates"
 *
 * Lists every estate the logged-in user owns or manages, each as its own
 * panel ("frame") with the estate name, a role badge (Owner/Manager), and
 * a stubbed "Estate Tools" button. Beneath each frame is a grid of the
 * regions that belong to that estate, showing a map tile and the region
 * name. Clicking a region opens a modal showing a larger map tile, an
 * online/offline indicator (fetched live — see region_status.php's
 * per-region online/offline check below), the region's estate name and
 * the estate owner's display name, plus a "Request new map image" button
 * that forces a fresh fetch of that region's map tiles (see
 * region_image.php's aggressive per-tile caching — tiles are cached
 * indefinitely until explicitly refreshed this way).
 *
 * The modal also shows static details resolved from the `regions` table
 * (location, size, port) and `regionsettings` table (maturity — see
 * region_maturity_label() in includes/helpers.php, and its docblock for
 * why regionsettings.maturity is used rather than the superficially similar
 * but NOT-authoritative regions.access field): region location (grid
 * coordinates = locX/locY ÷ 256), region size, region maturity
 * (General/Moderate/Adult), and the simulator's port (regions.serverPort —
 * also used as the RemoteAdmin port, since this grid runs one region per
 * simulator with RemoteAdmin on the same port as the simulator's main
 * listener).
 *
 * Online/offline status is fetched via region_status.php — an AJAX call
 * made ONLY when the modal opens (not pre-fetched for the whole region
 * list), since it requires a live RemoteAdmin round-trip to that region's
 * simulator.
 *
 * ── Access ─────────────────────────────────────────────────────────────────
 * Visibility is NOT based on UserLevel. Estate ownership/management is an
 * assignment independent of UserLevel — a UserLevel-0 resident can be an
 * estate owner or manager, and conversely a high-UserLevel "Grid God" may
 * have no estates at all. Access to this page (and the "My Estates" drawer
 * item) is granted purely by appearing in estate_settings.EstateOwner or
 * estate_managers.uuid for at least one estate — see
 * includes/estates.php — user_has_estate_access().
 *
 * Users with NO estate access who reach this page directly are redirected
 * to profile.php (the drawer item is hidden for them, so this should only
 * happen via a stale bookmark/link).
 *
 * ── Ordering ──────────────────────────────────────────────────────────────
 * Estates the user OWNS are listed first, then estates the user only
 * MANAGES — see includes/estates.php — get_estates_for_user(). Within each
 * group, estates are ordered by EstateName. Regions within each estate are
 * ordered by region name.
 *
 * ── Map tiles ─────────────────────────────────────────────────────────────
 * Served via region_image.php, which fetches real map tiles (including
 * objects/buildings) from the grid's HTTP map tile service
 * (MAP_TILE_SERVICE_URL, config.php), keyed by each region's locX/locY/
 * sizeX/sizeY. Zoom defaults to a guess based on sizeX; a future per-region
 * override (portal_region_prefs, set via the region modal) will let this
 * be tuned per region — see Things_to_do.md.
 *
 * ── Reuse for future Administrator "All Estates" tool ──────────────────────
 * The per-estate frame markup below is intentionally a single foreach over
 * estate "blocks" (estate_id, name, owner_uuid, regions[], role). The future
 * admin tool can reuse get_estate_block_data() from includes/estates.php to
 * build the same block shape for every estate on the grid, and reuse this
 * same rendering loop (factored into a render_estate_block() helper if/when
 * that tool is built).
 *
 * Session: real authentication via includes/auth.php
 * Data:    SELECT only — estate_settings, estate_managers, estate_map, regions
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

// ─── Data ───────────────────────────────────────────────────────────────────
$session_user = get_session_user();
$db           = get_db();

$has_estate_access = user_has_estate_access($db, $session_user['uuid']);

// No estate access -> this page isn't for them (drawer item is hidden too;
// this only catches stale links/bookmarks).
if (!$has_estate_access) {
    header('Location: profile.php');
    exit;
}

$profile   = get_user_profile($session_user['uuid']);
$full_name = htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']);
$userlevel = (int)$profile['userlevel'];

$estates = get_estates_for_user($db, $session_user['uuid']);

// Resolve each estate's owner display name once (used in the region detail
// modal). NULL_UUID owners (shouldn't occur for estates a user has access
// to, but guard anyway) are shown as "Unowned".
const REGIONS_NULL_UUID = '00000000-0000-0000-0000-000000000000';
foreach ($estates as &$estate) {
    if ($estate['owner_uuid'] === REGIONS_NULL_UUID) {
        $estate['owner_name'] = 'Unowned';
        continue;
    }
    $owner_profile = get_user_profile($estate['owner_uuid']);
    $estate['owner_name'] = trim($owner_profile['firstname'] . ' ' . $owner_profile['lastname']);
}
unset($estate);

// ─── Presence / nav state ──────────────────────────────────────────────────
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Estates — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
    <script>const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;</script>
    <style>
        /* ── Header panel ─────────────────────────────────────────────── */
        .estates-header-panel {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-xl, var(--radius-lg));
            box-shadow: var(--shadow-card);
            padding: 20px 24px;
            margin-top: 24px;
        }
        .estates-header-panel .panel-heading {
            margin: 0;
        }
        .estates-header-panel .panel-subhead {
            margin: 4px 0 0;
        }

        /* ── Estate frame ─────────────────────────────────────────────── */
        .estate-block {
            background: var(--clr-surface);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-xl, var(--radius-lg));
            box-shadow: var(--shadow-card);
            overflow: hidden;
            margin-top: 24px;
        }
        .estate-block-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--clr-border-light);
            background: linear-gradient(135deg, var(--clr-surface) 0%, var(--clr-surface-2) 100%);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .estate-name {
            font-family: var(--font-display);
            font-size: 1.2rem;
            font-weight: 400;
            color: var(--clr-text-primary);
            margin: 0;
        }
        .estate-role-badge {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 3px 10px;
            border-radius: var(--radius-pill, 999px);
            white-space: nowrap;
        }
        .estate-role-badge.owner {
            background: color-mix(in srgb, var(--clr-lilac-deep) 14%, transparent);
            color: var(--clr-lilac-deep);
        }
        .estate-role-badge.manager {
            background: var(--clr-surface-2);
            color: var(--clr-text-secondary);
            border: 1px solid var(--clr-border-light);
        }
        .estate-block-header-spacer {
            flex: 1;
        }
        .estate-region-count {
            font-size: 0.78rem;
            color: var(--clr-text-muted);
        }
        .estate-block-body {
            padding: 22px 24px;
        }
        .estate-no-regions {
            font-size: 0.85rem;
            color: var(--clr-text-muted);
            margin: 0;
        }

        /* ── Region grid ──────────────────────────────────────────────── */
        .region-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 16px;
        }
        .region-card {
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            cursor: pointer;
            text-align: left;
            font-family: var(--font-body);
            color: var(--clr-text-primary);
            border-radius: var(--radius-md);
            transition: transform 0.15s;
        }
        .region-card:hover {
            transform: translateY(-2px);
        }
        .region-card:focus-visible {
            outline: 2px solid var(--clr-lilac-mid);
            outline-offset: 2px;
        }
        .region-tile-wrap {
            width: 100%;
            aspect-ratio: 1 / 1;
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--clr-border-light);
            box-shadow: 0 2px 8px rgba(80,40,120,0.08);
            background: var(--clr-map-bg);
            /* Padding so the rounded corners of this wrapper don't clip
               the corners of the map tile image itself — map tiles are
               square with content right up to their edges/corners. */
            padding: 6px;
            box-sizing: border-box;
        }
        .region-tile-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .region-card-name {
            margin-top: 8px;
            margin-bottom: 16px;
            font-size: 0.85rem;
            font-weight: 500;
            line-height: 1.3;
            text-align: center;
            word-break: break-word;
        }

        /* ── Region detail modal (stub) ───────────────────────────────── */
        .region-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(20, 10, 40, 0.7);
            backdrop-filter: blur(4px);
            z-index: 500;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
            opacity: 0; pointer-events: none; transition: opacity 0.2s;
        }
        .region-modal-overlay.open { opacity: 1; pointer-events: all; }
        .region-modal {
            background: var(--clr-surface);
            border-radius: var(--radius-xl, var(--radius-lg));
            box-shadow: 0 20px 60px rgba(20, 10, 40, 0.4);
            width: 100%; max-width: 1100px;
            transform: translateY(12px) scale(0.98); transition: transform 0.2s;
            overflow: hidden;
        }
        .region-modal-overlay.open .region-modal { transform: translateY(0) scale(1); }
        .region-modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--clr-border-light);
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px;
        }
        .region-modal-title {
            font-family: var(--font-display);
            font-size: 1.45rem;
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
        .region-modal-body {
            padding: 0 0 20px;
            font-size: 0.85rem;
            color: var(--clr-text-secondary);
        }
        /* ── Estate Tools modal: reuses .region-modal-* for structure,
               plus its own manager-list / typeahead styling below ──────── */
        .estate-tools-modal {
            max-width: 480px;
        }
        .estate-tools-modal-body {
            padding: 0 22px 22px;
        }
        .estate-tools-detail-line {
            font-size: 0.85rem;
            color: var(--clr-text-secondary);
            margin: 0 0 6px;
        }
        .estate-tools-detail-line .label {
            color: var(--clr-text-muted);
            font-weight: 500;
        }
        .estate-tools-section-heading {
            font-family: var(--font-display);
            font-size: 0.95rem;
            font-weight: 400;
            color: var(--clr-text-primary);
            margin: 20px 0 10px;
        }
        .estate-tools-manager-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .estate-tools-manager-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 9px 14px;
            background: var(--clr-surface-2);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-md);
        }
        .estate-tools-manager-name {
            font-size: 0.85rem;
            color: var(--clr-text-primary);
            word-break: break-word;
        }
        .estate-tools-manager-row .btn-primary-pill-destructive {
            padding: 5px 14px;
            font-size: 0.75rem;
        }
        .estate-tools-empty {
            font-size: 0.82rem;
            color: var(--clr-text-muted);
            margin: 0;
        }
        .estate-tools-loading,
        .estate-tools-error {
            font-size: 0.85rem;
            color: var(--clr-text-muted);
            text-align: center;
            padding: 20px 0;
        }
        .estate-tools-error { color: var(--clr-accent-rose, #e87070); }

        /* ── "Add manager" typeahead — adapted from publicprofile.php's
               .pp-search-* pattern, scoped to this modal so it can sit
               inside a fixed-width dialog rather than a full page. ────── */
        .estate-tools-add-wrap {
            position: relative;
            margin-top: 4px;
        }
        .estate-tools-add-input {
            width: 100%;
            padding: 10px 16px;
            font-size: 0.85rem;
            font-family: var(--font-body);
            color: var(--clr-text-primary);
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-pill);
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .estate-tools-add-input::placeholder { color: var(--clr-text-muted); }
        .estate-tools-add-input:focus {
            border-color: var(--clr-lilac-deep);
            box-shadow: 0 0 0 3px rgba(139,104,196,0.15);
        }
        .estate-tools-add-results {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: var(--radius-md);
            box-shadow: 0 8px 32px rgba(100,70,160,0.14);
            z-index: 50;
            display: none;
            max-height: 220px;
            overflow-x: hidden;
            overflow-y: auto;
        }
        .estate-tools-add-results.open { display: block; }
        .estate-tools-add-result-item {
            display: block;
            width: 100%;
            text-align: left;
            padding: 10px 14px;
            font-size: 0.83rem;
            font-family: var(--font-body);
            color: var(--clr-text-primary);
            background: none;
            border: none;
            border-bottom: 1px solid var(--clr-border-light);
            cursor: pointer;
        }
        .estate-tools-add-result-item:last-child { border-bottom: none; }
        .estate-tools-add-result-item:hover,
        .estate-tools-add-result-item:focus {
            background: var(--clr-surface-2);
            outline: none;
        }
        .estate-tools-add-results-empty,
        .estate-tools-add-results-searching {
            padding: 12px 14px;
            font-size: 0.8rem;
            color: var(--clr-text-muted);
            text-align: center;
        }
        .estate-tools-add-status {
            font-size: 0.78rem;
            color: var(--clr-text-muted);
            margin: 8px 0 0;
            min-height: 1.1em;
        }
        /* .dissolve-modal-overlay (default.css) is z-index 500 — same as
           .region-modal-overlay above, which the Estate Tools modal reuses.
           #removeManagerModal needs to stack ABOVE the Estate Tools modal
           it's opened from, so it gets a page-local override here rather
           than changing the shared .dissolve-modal-overlay z-index (which
           would also affect the dissolve-partnership modal on profile.php). */
        #removeManagerModal {
            z-index: 600;
        }
        /* Region modal info panel: full-width tinted "header" section.
               Tile + refresh button stacked in a centred left column;
               names in a separated column to the right. ───────────────── */
        .region-modal-panel {
            background: linear-gradient(135deg, var(--clr-surface-2) 0%, var(--clr-lilac-soft) 100%);
            border-bottom: 1px solid var(--clr-border-light);
            padding: 24px;
        }
        .region-modal-info {
            display: flex;
            gap: 24px;
            align-items: flex-start;
        }
        .region-modal-tile-col {
            flex: 0 0 240px;
            display: flex;
            justify-content: center;
        }
        .region-modal-tile-wrap {
            width: 240px;
            height: 240px;
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--clr-border-light);
            background: var(--clr-map-bg);
            padding: 10px;
            box-sizing: border-box;
        }
        .region-modal-tile-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .region-modal-names {
            flex: 1;
            min-width: 0;
            padding: 4px 0 4px 24px;
            border-left: 1px solid rgba(0, 0, 0, 0.12);
            align-self: stretch;
        }
        .region-modal-region-name {
            font-family: var(--font-display);
            font-size: 1.3rem;
            font-weight: 400;
            color: var(--clr-text-primary);
            margin: 0;
            word-break: break-word;
            display: flex;
            align-items: baseline;
            gap: 8px;
            flex-wrap: wrap;
        }
        .region-status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            flex-shrink: 0;
            background: var(--clr-text-muted);
            position: relative;
            top: -2px;
        }
        .region-status-dot.status-online  { background: var(--clr-online);  box-shadow: 0 0 0 2px rgba(82,183,136,0.25); }
        .region-status-dot.status-offline { background: var(--clr-offline); }
        .region-status-dot.status-unknown { background: var(--clr-text-muted); }
        .region-modal-maturity {
            font-family: var(--font-body);
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--clr-text-secondary);
            background: var(--clr-surface-2);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-pill, 999px);
            padding: 2px 10px;
            cursor: pointer;
            transition: background 0.12s, border-color 0.12s;
        }
        .region-modal-maturity:hover:not(:disabled) {
            background: var(--clr-border);
        }
        .region-modal-maturity:disabled {
            cursor: default;
        }
        .region-modal-uptime {
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--clr-text-secondary);
            background: var(--clr-surface-2);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-pill, 999px);
            padding: 2px 10px;
        }
        .region-modal-stats {
            padding: 20px 24px 4px;
        }
        .region-modal-stats-heading {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--clr-text-secondary);
            margin: 0 0 12px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .region-modal-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 14px;
        }
        .region-modal-stat {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .region-modal-stat-label {
            font-size: 0.72rem;
            color: var(--clr-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .region-modal-stat-value {
            font-size: 1.05rem;
            font-weight: 500;
            color: var(--clr-text-primary);
        }
        .region-modal-stats-note {
            margin: 14px 0 0;
            font-size: 0.78rem;
            color: var(--clr-text-muted);
        }
        .region-modal-estate-line {
            font-size: 0.85rem;
            color: var(--clr-text-secondary);
            margin: 8px 0 0;
            word-break: break-word;
        }
        .region-modal-detail-line {
            font-size: 0.8rem;
            color: var(--clr-text-muted);
            margin: 6px 0 0;
        }
        .region-modal-detail-sep {
            margin: 0 6px;
            color: var(--clr-border-light);
        }
        .region-modal-estate-line .label,
        .region-modal-detail-line .label {
            color: var(--clr-text-secondary);
            font-weight: 500;
        }
        .region-modal-actions {
            margin-top: 18px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .region-modal-actions .btn-primary {
            width: auto;
        }
        .region-modal-refresh-status {
            font-size: 0.78rem;
            color: var(--clr-text-muted);
            margin: 8px 0 0;
            min-height: 1.1em;
        }
        @media (max-width: 600px) {
            .region-modal-panel { padding: 18px; }
            .region-modal-info { flex-direction: column; align-items: stretch; }
            .region-modal-tile-col { flex: none; width: 100%; }
            .region-modal-tile-wrap { width: 100%; height: auto; aspect-ratio: 1 / 1; }
            .region-modal-names {
                padding: 16px 0 0;
                border-left: none;
                border-top: 1px solid rgba(0, 0, 0, 0.12);
            }
        }
        .region-modal-footer {
            padding: 14px 22px;
            border-top: 1px solid var(--clr-border-light);
            display: flex; justify-content: flex-end;
        }

        /* ── Empty state (no estates at all — shouldn't normally happen
               since the page redirects, but kept as a safety net) ──────── */
        .estates-empty {
            margin-top: 24px;
            padding: 40px 24px;
            text-align: center;
            color: var(--clr-text-muted);
            font-size: 0.9rem;
            background: var(--clr-surface);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-xl, var(--radius-lg));
        }

        @media (max-width: 600px) {
            .estate-block-header { padding: 14px 18px; }
            .estate-block-body   { padding: 18px; }
            .region-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 12px; }
        }
    </style>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('regions', [], $userlevel, $has_estate_access); ?>

<main class="page-wrap" id="main-content">

    <div class="estates-header-panel">
        <h1 class="panel-heading">My Estates</h1>
        <p class="panel-subhead">Estates you own or manage, and the regions within them.</p>
    </div>

    <?php if (empty($estates)): ?>
    <div class="estates-empty">
        You don't currently have estate owner or manager access to any estates.
    </div>
    <?php else: ?>
        <?php foreach ($estates as $estate): ?>
        <section class="estate-block" aria-label="<?= htmlspecialchars($estate['name'], ENT_QUOTES) ?>">
            <div class="estate-block-header">
                <h2 class="estate-name"><?= htmlspecialchars($estate['name']) ?></h2>
                <span class="estate-role-badge <?= $estate['role'] === 'owner' ? 'owner' : 'manager' ?>">
                    <?= $estate['role'] === 'owner' ? 'Owner' : 'Manager' ?>
                </span>
                <div class="estate-block-header-spacer"></div>
                <?php $region_count = count($estate['regions']); ?>
                <span class="estate-region-count">
                    <?= $region_count ?> region<?= $region_count === 1 ? '' : 's' ?>
                </span>
                <?php
                    $estate_tools_json = json_encode([
                        'estateId'   => $estate['estate_id'],
                        'name'       => $estate['name'],
                        'ownerUuid'  => $estate['owner_uuid'],
                        'ownerName'  => $estate['owner_name'],
                        'role'       => $estate['role'],
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                ?>
                <button type="button" class="btn-primary"
                        onclick="openEstateToolsModal(<?= htmlspecialchars($estate_tools_json, ENT_QUOTES) ?>)">
                    Estate Tools
                </button>
            </div>
            <div class="estate-block-body">
                <?php if (empty($estate['regions'])): ?>
                <p class="estate-no-regions">No regions are currently assigned to this estate.</p>
                <?php else: ?>
                <div class="region-grid">
                    <?php foreach ($estate['regions'] as $region): ?>
                    <?php
                        $tile_url = 'region_image.php?' . http_build_query([
                            'locX'  => $region['locX'],
                            'locY'  => $region['locY'],
                            'sizeX' => $region['sizeX'],
                            'sizeY' => $region['sizeY'],
                        ]);
                    ?>
                    <?php
                        // canManageMaturity is always true here: this page only ever lists
                        // estates the current user owns or manages (get_estates_for_user()),
                        // and user_can_manage_region_maturity() would return true for exactly
                        // that reason — so re-querying it per region would be redundant work
                        // for a foregone conclusion. The flag is still sent (rather than just
                        // assumed true in JS) so the SAME region JSON payload / openRegionModal()
                        // path can be reused, unchanged, by the future Administrator "All
                        // Estates" tool, where this WILL vary per region (a Grid Staff viewer
                        // may not own/manage every estate shown there) — see Things_to_do.md.
                        // The real enforcement either way happens server-side in
                        // change_maturity.php; this only controls whether the pill in the UI
                        // invites a click.
                        $region_modal_json = json_encode([
                            'uuid'               => $region['uuid'],
                            'name'               => $region['name'],
                            'maturity'           => (int)$region['maturity'],
                            'maturityLabel'      => region_maturity_label($region['maturity']),
                            'canManageMaturity'  => os_write_feature_enabled('ENABLE_CHANGE_MATURITY'),
                            'estateName'         => $estate['name'],
                            'ownerName'          => $estate['owner_name'],
                            'locX'               => (int)$region['locX'],
                            'locY'               => (int)$region['locY'],
                            'sizeX'              => (int)$region['sizeX'],
                            'sizeY'              => (int)$region['sizeY'],
                            'serverPort'         => (int)$region['serverPort'],
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                    ?>
                    <button type="button" class="region-card"
                            onclick="openRegionModal(<?= htmlspecialchars($region_modal_json, ENT_QUOTES) ?>)">
                        <span class="region-tile-wrap">
                            <img src="<?= htmlspecialchars($tile_url, ENT_QUOTES) ?>"
                                 alt="Map tile for <?= htmlspecialchars($region['name'], ENT_QUOTES) ?>"
                                 loading="lazy">
                        </span>
                        <span class="region-card-name"><?= htmlspecialchars($region['name']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>

</main>

<!-- ── Region detail modal ──────────────────────────────────────────────────── -->
<div class="region-modal-overlay" id="regionModalOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="regionModalTitle" aria-hidden="true">
    <div class="region-modal">
        <div class="region-modal-header">
            <h2 class="region-modal-title" id="regionModalTitle">Region details</h2>
            <button type="button" class="region-modal-close" onclick="closeRegionModal()" aria-label="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="region-modal-body">
            <div class="region-modal-panel">
                <div class="region-modal-info">
                    <div class="region-modal-tile-col">
                        <span class="region-modal-tile-wrap">
                            <img id="regionModalTile" src="" alt="">
                        </span>
                    </div>
                    <div class="region-modal-names">
                        <p class="region-modal-region-name">
                            <span class="region-status-dot status-unknown" id="regionModalStatusDot"
                                  title="Checking status…"></span>
                            <span id="regionModalRegionName"></span>
                            <button type="button" class="region-modal-maturity" id="regionModalMaturity"
                                    onclick="openMaturityPicker()"></button>
                            <span class="region-modal-uptime" id="regionModalUptime" hidden></span>
                        </p>
                        <p class="region-modal-estate-line">
                            <span class="label">Estate:</span>
                            <span id="regionModalEstateName"></span>
                            (<span class="label">Owner:</span>
                            <span id="regionModalOwnerName"></span>)
                        </p>
                        <p class="region-modal-detail-line">
                            <span class="label">Location:</span>
                            <span id="regionModalLocation"></span>
                            <span class="region-modal-detail-sep">·</span>
                            <span class="label">Size:</span>
                            <span id="regionModalSize"></span>
                            <span class="region-modal-detail-sep">·</span>
                            <span class="label">Port:</span>
                            <span id="regionModalPort"></span>
                        </p>
                        <div class="region-modal-actions">
                            <button type="button" class="btn-primary" id="regionModalRefreshBtn" onclick="refreshRegionMapImage()">
                                Request new map image
                            </button>
                            <button type="button" class="btn-primary" id="regionModalRestartBtn" onclick="openRestartConfirm()">
                                Restart region
                            </button>
                            <button type="button" class="btn-primary" id="regionModalBroadcastBtn" onclick="openBroadcastConfirm()">
                                Send message to region
                            </button>
                            <?php if (user_level_meets($userlevel, 'Administrator') && defined('ENABLE_REST_CONSOLE') && ENABLE_REST_CONSOLE): ?>
                            <button type="button" class="btn-primary" id="regionModalConsoleBtn" onclick="openRegionConsole()">
                                Console
                            </button>
                            <?php endif; ?>
                        </div>
                        <p class="region-modal-refresh-status" id="regionModalRefreshStatus"></p>
                    </div>
                </div>
            </div>

            <div class="region-modal-stats" id="regionModalStats" hidden>
                <p class="region-modal-stats-heading">Region Stats</p>
                <div class="region-modal-stats-grid">
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Sim FPS</span>
                        <span class="region-modal-stat-value" id="regionStatSimFps">—</span>
                    </div>
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Physics FPS</span>
                        <span class="region-modal-stat-value" id="regionStatPhysFps">—</span>
                    </div>
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Agents</span>
                        <span class="region-modal-stat-value" id="regionStatAgents">—</span>
                    </div>
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Child Agents</span>
                        <span class="region-modal-stat-value" id="regionStatChildAgents">—</span>
                    </div>
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Active Scripts</span>
                        <span class="region-modal-stat-value" id="regionStatActiveScripts">—</span>
                    </div>
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Prims</span>
                        <span class="region-modal-stat-value" id="regionStatPrims">—</span>
                    </div>
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Memory</span>
                        <span class="region-modal-stat-value" id="regionStatMemory">—</span>
                    </div>
                    <div class="region-modal-stat">
                        <span class="region-modal-stat-label">Uptime</span>
                        <span class="region-modal-stat-value" id="regionStatUptime">—</span>
                    </div>
                </div>
                <p class="region-modal-stats-note" id="regionModalStatsNote"></p>
            </div>
        </div>
        <div class="region-modal-footer">
            <button type="button" class="pick-modal-close" onclick="closeRegionModal()">Close</button>
        </div>
    </div>
</div>

<!-- ── Estate Tools modal ──────────────────────────────────────────────────── -->
<div class="region-modal-overlay" id="estateToolsModalOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="estateToolsModalTitle" aria-hidden="true">
    <div class="region-modal estate-tools-modal">
        <div class="region-modal-header">
            <h2 class="region-modal-title" id="estateToolsModalTitle">Estate Tools</h2>
            <button type="button" class="region-modal-close" onclick="closeEstateToolsModal()" aria-label="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="region-modal-body estate-tools-modal-body">
            <p class="estate-tools-detail-line">
                <span class="label">Owner:</span> <span id="estateToolsOwnerName">—</span>
            </p>

            <h3 class="estate-tools-section-heading">Estate Managers</h3>
            <div id="estateToolsManagerArea">
                <p class="estate-tools-loading">Loading…</p>
            </div>

            <div id="estateToolsAddSection" style="display:none;">
                <h3 class="estate-tools-section-heading">Add a Manager</h3>
                <div class="estate-tools-add-wrap" id="estateToolsAddWrap">
                    <input type="text"
                           id="estateToolsAddInput"
                           class="estate-tools-add-input"
                           placeholder="Type a resident's name…"
                           autocomplete="off"
                           spellcheck="false"
                           aria-label="Search residents to add as estate manager"
                           aria-autocomplete="list"
                           aria-controls="estateToolsAddResults">
                    <div class="estate-tools-add-results" id="estateToolsAddResults" role="listbox" aria-label="Search results"></div>
                </div>
                <p class="estate-tools-add-status" id="estateToolsAddStatus"></p>
            </div>
        </div>
        <div class="region-modal-footer">
            <button type="button" class="pick-modal-close" onclick="closeEstateToolsModal()">Close</button>
        </div>
    </div>
</div>

<!-- ── Remove estate manager confirmation modal ────────────────────────────── -->
<div class="dissolve-modal-overlay" id="removeManagerModal"
     role="dialog" aria-modal="true"
     aria-labelledby="removeManagerModalTitle" aria-hidden="true">
    <div class="dissolve-modal-card">
        <h2 class="dissolve-modal-title" id="removeManagerModalTitle">Remove estate manager?</h2>
        <p class="dissolve-modal-body">
            This will remove <strong id="removeManagerName"></strong> as a manager of
            <strong id="removeManagerEstateName"></strong>. They will no longer have
            estate manager access for this estate.
        </p>
        <p class="dissolve-modal-status" id="removeManagerStatus"></p>
        <div class="dissolve-modal-actions">
            <button class="btn-cancel" id="removeManagerCancelBtn" onclick="closeRemoveManagerModal()">
                Cancel
            </button>
            <button class="btn-primary-pill-destructive" id="removeManagerConfirmBtn" onclick="doRemoveManager()">
                Yes, remove
            </button>
        </div>
    </div>
</div>


<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_confirm_modal(); ?>
<?php render_restart_modal(); ?>
<?php render_broadcast_modal(); ?>
<?php render_maturity_modal(); ?>
<?php render_console_modal(); ?>
<?php render_shared_js(); ?>
<script>
/* ── Region detail modal ──────────────────────────────────────────────── */
let regionModalData = null;

function buildRegionTileUrl(region, refresh) {
    const params = new URLSearchParams({
        locX: region.locX,
        locY: region.locY,
        sizeX: region.sizeX,
        sizeY: region.sizeY,
    });
    if (refresh) {
        params.set('refresh', '1');
        // Cache-bust so the browser doesn't serve its own cached copy of
        // this exact URL.
        params.set('_', Date.now());
    }
    return 'region_image.php?' + params.toString();
}

function openRegionModal(region) {
    regionModalData = region;

    if (_restartCountdownTimer) {
        clearInterval(_restartCountdownTimer);
        _restartCountdownTimer = null;
    }

    document.getElementById('regionModalRegionName').textContent = region.name;
    const maturityBtn = document.getElementById('regionModalMaturity');
    maturityBtn.textContent = '(' + region.maturityLabel + ')';
    maturityBtn.disabled = !region.canManageMaturity;
    maturityBtn.title = region.canManageMaturity
        ? 'Click to change region maturity'
        : '';
    document.getElementById('regionModalEstateName').textContent = region.estateName;
    document.getElementById('regionModalOwnerName').textContent = region.ownerName;
    document.getElementById('regionModalRefreshStatus').textContent = '';

    document.getElementById('regionModalLocation').textContent =
        Math.floor(region.locX / 256) + ', ' + Math.floor(region.locY / 256);
    document.getElementById('regionModalSize').textContent =
        region.sizeX + ' x ' + region.sizeY;
    document.getElementById('regionModalPort').textContent = region.serverPort;

    const img = document.getElementById('regionModalTile');
    img.src = buildRegionTileUrl(region, false);
    img.alt = 'Map tile for ' + region.name;

    const refreshBtn = document.getElementById('regionModalRefreshBtn');
    refreshBtn.disabled = false;
    refreshBtn.textContent = 'Request new map image';

    const restartBtn = document.getElementById('regionModalRestartBtn');
    restartBtn.disabled = false;
    restartBtn.textContent = 'Restart region';

    // Region Stats — reset to placeholders; populated below if available.
    const uptimeBadge = document.getElementById('regionModalUptime');
    uptimeBadge.hidden = true;
    uptimeBadge.textContent = '';
    document.getElementById('regionModalStats').hidden = true;
    document.getElementById('regionModalStatsNote').textContent = '';
    ['SimFps', 'PhysFps', 'Agents', 'ChildAgents', 'ActiveScripts', 'Prims', 'Memory', 'Uptime'].forEach(function(key) {
        document.getElementById('regionStat' + key).textContent = '—';
    });

    const o = document.getElementById('regionModalOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');

    // Online/offline status — fetched fresh each time the modal opens, since
    // it's only needed for the region actually being viewed. Guard against a
    // slow response landing after the modal has been reopened for a
    // different region.
    const requestedUuid = region.uuid;
    setRegionStatus('unknown', 'Checking status…');
    fetch('region_status.php?' + new URLSearchParams({ uuid: region.uuid, csrf: PORTAL_CSRF }))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!regionModalData || regionModalData.uuid !== requestedUuid) return;
            if (!data.ok) {
                setRegionStatus('unknown', 'Status unavailable.');
                return;
            }
            if (data.online === true) {
                setRegionStatus('online', 'Online');
            } else if (data.online === false) {
                setRegionStatus('offline', 'Offline');
            } else {
                setRegionStatus('unknown', 'Status unavailable.');
            }
        })
        .catch(function() {
            if (!regionModalData || regionModalData.uuid !== requestedUuid) return;
            setRegionStatus('unknown', 'Status unavailable.');
        });

    // Region Stats — separate, independent fetch via /jsonSimStats.
    // A failed fetch here means "stats unavailable", NOT "region offline" —
    // it does not affect the status dot above.
    fetch('region_stats_data.php?' + new URLSearchParams({ uuid: region.uuid, csrf: PORTAL_CSRF }))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!regionModalData || regionModalData.uuid !== requestedUuid) return;
            if (!data.ok || !data.stats) {
                return; // leave the Region Stats section hidden
            }
            populateRegionStats(data.stats, data.uptime);
        })
        .catch(function() {
            // Silently leave the Region Stats section hidden.
        });
}

/**
 * Populate the "Region Stats" section and the uptime badge from a
 * /jsonSimStats response (region_stats_data.php — see includes/region_stats.php).
 * Field names match jsonSimStats' own keys.
 */
function populateRegionStats(stats, uptime) {
    const get = function(key, fallback) {
        const v = stats[key];
        return (v === undefined || v === null || v === '') ? fallback : v;
    };

    document.getElementById('regionStatSimFps').textContent       = get('SimFPS', '—');
    document.getElementById('regionStatPhysFps').textContent      = get('PhyFPS', '—');
    document.getElementById('regionStatAgents').textContent       = get('RootAg', '—');
    document.getElementById('regionStatChildAgents').textContent  = get('ChldAg', '—');
    document.getElementById('regionStatActiveScripts').textContent = get('AtvScr', '—');
    document.getElementById('regionStatPrims').textContent        = get('Prims', '—');

    const memory = get('Memory', null);
    document.getElementById('regionStatMemory').textContent = memory !== null ? (memory + ' MB') : '—';

    document.getElementById('regionStatUptime').textContent = uptime || get('Uptime', '—');

    if (uptime) {
        const badge = document.getElementById('regionModalUptime');
        badge.textContent = 'Up ' + uptime;
        badge.hidden = false;
    }

    document.getElementById('regionModalStats').hidden = false;
}

function setRegionStatus(state, title) {
    const dot = document.getElementById('regionModalStatusDot');
    dot.className = 'region-status-dot status-' + state;
    dot.title = title;
}

function refreshRegionMapImage() {
    if (!regionModalData) return;

    const refreshBtn = document.getElementById('regionModalRefreshBtn');
    const status = document.getElementById('regionModalRefreshStatus');

    refreshBtn.disabled = true;
    refreshBtn.textContent = 'Requesting…';
    status.textContent = 'Fetching the latest map tiles for this region — this may take a moment.';

    const img = document.getElementById('regionModalTile');
    const newSrc = buildRegionTileUrl(regionModalData, true);

    const probe = new Image();
    probe.onload = function() {
        img.src = newSrc;
        refreshBtn.disabled = false;
        refreshBtn.textContent = 'Request new map image';
        status.textContent = 'Map image updated.';

        // Also refresh the thumbnail in the region grid behind the modal
        // (now that the per-tile cache has been overwritten, a normal
        // request will pick up the new tiles).
        document.querySelectorAll('.region-card img[alt="Map tile for ' + regionModalData.name + '"]')
            .forEach(function(gridImg) {
                gridImg.src = buildRegionTileUrl(regionModalData, false);
            });
    };
    probe.onerror = function() {
        refreshBtn.disabled = false;
        refreshBtn.textContent = 'Request new map image';
        status.textContent = 'Could not refresh the map image — please try again.';
    };
    probe.src = newSrc;
}

function openRestartConfirm() {
    if (!regionModalData) return;
    openRestartModal(regionModalData.name);
}

function openBroadcastConfirm() {
    if (!regionModalData) return;
    openBroadcastModal(regionModalData.name);
}

function sendRegionBroadcast() {
    if (!regionModalData) return;

    const textEl = document.getElementById('broadcastModalText');
    const message = textEl.value.trim();
    const status = document.getElementById('broadcastModalStatus');

    if (message === '') {
        status.textContent = 'Please enter a message to send.';
        return;
    }

    const requestedUuid = regionModalData.uuid;
    const sendBtn = document.getElementById('broadcastModalSendBtn');

    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending…';
    status.textContent = 'Sending message…';

    fetch('region_broadcast.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ uuid: requestedUuid, message: message, csrf: PORTAL_CSRF }),
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!regionModalData || regionModalData.uuid !== requestedUuid) return;

            sendBtn.disabled = false;
            sendBtn.textContent = 'Send';

            if (data.ok) {
                status.textContent = 'Message sent.';
                textEl.value = '';
            } else if (data.not_enabled) {
                status.textContent = 'Sending a message isn\'t available for this region yet — RemoteAdmin isn\'t enabled on its simulator.';
            } else {
                status.textContent = data.error || 'Could not send the message — please try again.';
            }
        })
        .catch(function() {
            if (!regionModalData || regionModalData.uuid !== requestedUuid) return;
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send';
            status.textContent = 'Could not send the message — please try again.';
        });
}

// Tracks the active countdown interval so a second restart click (or the
// modal being reopened for a different region) can cancel any countdown
// already in progress rather than letting two run at once.
let _restartCountdownTimer = null;

function startRestartCountdown(regionName, requestedUuid, seconds) {
    if (_restartCountdownTimer) {
        clearInterval(_restartCountdownTimer);
        _restartCountdownTimer = null;
    }

    const status = document.getElementById('regionModalRefreshStatus');
    let remaining = seconds;

    // This countdown is purely client-side and approximate — it is NOT
    // synced to the simulator's actual restart timing (network latency,
    // RemoteAdmin's own scheduling, etc. all introduce some drift), but it
    // gives a close-enough sense of when the restart will actually fire.
    const tick = function() {
        if (!regionModalData || regionModalData.uuid !== requestedUuid) {
            clearInterval(_restartCountdownTimer);
            _restartCountdownTimer = null;
            return;
        }

        if (remaining <= 0) {
            status.textContent = 'Restart should be happening now for "' + regionName + '" — it should come back online shortly.';
            clearInterval(_restartCountdownTimer);
            _restartCountdownTimer = null;
            return;
        }

        status.textContent = 'Restarting "' + regionName + '" in ' + remaining + ' second' + (remaining === 1 ? '' : 's') + '…';
        remaining--;
    };

    tick();
    _restartCountdownTimer = setInterval(tick, 1000);
}

function restartRegion(delay) {
    if (!regionModalData) return;
    cancelRestartModal();

    const regionName = regionModalData.name;
    const restartBtn = document.getElementById('regionModalRestartBtn');
    const status = document.getElementById('regionModalRefreshStatus');
    const requestedUuid = regionModalData.uuid;

    restartBtn.disabled = true;
    restartBtn.textContent = 'Restarting…';
    status.textContent = 'Sending restart request for "' + regionName + '"…';

    fetch('region_restart.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ uuid: requestedUuid, delay: String(delay), csrf: PORTAL_CSRF }),
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!regionModalData || regionModalData.uuid !== requestedUuid) return;

            restartBtn.disabled = false;
            restartBtn.textContent = 'Restart region';

            if (data.ok) {
                setRegionStatus('unknown', 'Restarting…');
                startRestartCountdown(regionName, requestedUuid, delay);
            } else if (data.not_enabled) {
                status.textContent = 'Restart is not available for this region yet — RemoteAdmin isn\'t enabled on its simulator.';
            } else {
                status.textContent = data.error || 'Could not restart the region — please try again.';
            }
        })
        .catch(function() {
            if (!regionModalData || regionModalData.uuid !== requestedUuid) return;
            restartBtn.disabled = false;
            restartBtn.textContent = 'Restart region';
            status.textContent = 'Could not restart the region — please try again.';
        });
}

function openMaturityPicker() {
    if (!regionModalData || !regionModalData.canManageMaturity) return;
    openMaturityModal(regionModalData.maturity);
}

function changeMaturity(newMaturity) {
    if (!regionModalData) return;

    const requestedUuid = regionModalData.uuid;
    const status = document.getElementById('maturityModalStatus');
    const picker = document.getElementById('maturityPicker');
    const buttons = picker.querySelectorAll('.maturity-option');

    buttons.forEach(function(btn) { btn.disabled = true; });
    status.textContent = 'Saving…';

    fetch('change_maturity.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ uuid: requestedUuid, maturity: String(newMaturity), csrf: PORTAL_CSRF }),
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            buttons.forEach(function(btn) { btn.disabled = false; });

            if (!data.ok) {
                status.textContent = data.error || 'Could not change region maturity — please try again.';
                return;
            }

            // Update the region modal currently on screen — but only if it's
            // still showing the same region (guards against a slow response
            // landing after the modal was reopened for a different region).
            // Note: this does not update the (closed) region card behind the
            // modal — its stored maturity value will be stale until the next
            // page load, but since maturity isn't displayed on the card
            // itself (only inside the modal), this has no visible effect
            // unless this exact region's modal is reopened again without a
            // page refresh, in which case it will simply show the value from
            // before this change. Not worth the complexity of keeping the
            // card's embedded data in sync for that narrow a case.
            if (regionModalData && regionModalData.uuid === requestedUuid) {
                regionModalData.maturity = data.maturity;
                regionModalData.maturityLabel = data.maturityLabel;
                document.getElementById('regionModalMaturity').textContent = '(' + data.maturityLabel + ')';
            }

            closeMaturityModal();
        })
        .catch(function() {
            buttons.forEach(function(btn) { btn.disabled = false; });
            status.textContent = 'Could not change region maturity — please try again.';
        });
}

function closeRegionModal() {
    const o = document.getElementById('regionModalOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');

    if (_restartCountdownTimer) {
        clearInterval(_restartCountdownTimer);
        _restartCountdownTimer = null;
    }
}

/* ── Console (Administrators only) ───────────────────────────────────── */
function openRegionConsole() {
    if (!regionModalData) return;
    openConsoleModal(regionModalData.uuid, regionModalData.name);
}
document.getElementById('regionModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeRegionModal();
});

/* ── Estate Tools modal ───────────────────────────────────────────────── */
// Tracks the estate currently open in the modal so async responses (list
// load, add, remove) can be ignored if the modal has since been closed or
// reopened for a different estate — same guard pattern used throughout this
// file for the region modal (e.g. restartRegion(), changeMaturity()).
let estateToolsData = null;

function openEstateToolsModal(estate) {
    estateToolsData = estate;

    document.getElementById('estateToolsModalTitle').textContent = estate.name;
    document.getElementById('estateToolsOwnerName').textContent  = estate.ownerName || '—';
    document.getElementById('estateToolsAddSection').style.display = 'none';
    document.getElementById('estateToolsAddInput').value = '';
    closeEstateToolsAddResults();
    document.getElementById('estateToolsAddStatus').textContent = '';
    document.getElementById('estateToolsManagerArea').innerHTML =
        '<p class="estate-tools-loading">Loading…</p>';

    const o = document.getElementById('estateToolsModalOverlay');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');

    loadEstateManagers(estate.estateId);
}

function closeEstateToolsModal() {
    const o = document.getElementById('estateToolsModalOverlay');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
    estateToolsData = null;
}
document.getElementById('estateToolsModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeEstateToolsModal();
});

function loadEstateManagers(estateId) {
    const area = document.getElementById('estateToolsManagerArea');

    fetch('estate_tools.php?' + new URLSearchParams({
        action: 'details', estate_id: String(estateId), csrf: PORTAL_CSRF,
    }))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!estateToolsData || estateToolsData.estateId !== estateId) return;

            if (!data.ok) {
                area.innerHTML = '<p class="estate-tools-error">' + escapeHtml(data.error || 'Could not load estate details.') + '</p>';
                return;
            }

            estateToolsData.canManage = data.canManage;
            estateToolsData.ownerName = data.estate.owner_name;
            document.getElementById('estateToolsOwnerName').textContent = data.estate.owner_name;

            renderEstateManagerList(data.managers);
            document.getElementById('estateToolsAddSection').style.display = data.canManage ? '' : 'none';
        })
        .catch(function() {
            if (!estateToolsData || estateToolsData.estateId !== estateId) return;
            area.innerHTML = '<p class="estate-tools-error">Could not load estate details. Please try again.</p>';
        });
}

function renderEstateManagerList(managers) {
    const area = document.getElementById('estateToolsManagerArea');

    if (!managers || managers.length === 0) {
        area.innerHTML = '<p class="estate-tools-empty">This estate has no managers yet.</p>';
        return;
    }

    const canManage = !!(estateToolsData && estateToolsData.canManage);

    // Built via data-* attributes rather than embedding names/uuids inside
    // an onclick="..." string — a manager name containing a double quote
    // would otherwise terminate the HTML attribute early before the browser
    // ever gets to JS-parse it (the same class of bug as CLAUDE.md Hard
    // Rule 8, just via innerHTML + JSON.stringify() rather than PHP +
    // json_encode()). Click handling is wired up via event delegation once,
    // below, instead of a fresh onclick per row.
    area.innerHTML = '<div class="estate-tools-manager-list">' +
        managers.map(function(m) {
            const removeBtn = canManage
                ? '<button type="button" class="btn-primary-pill-destructive estate-tools-remove-btn" ' +
                  'data-uuid="' + escapeHtml(m.uuid) + '" data-name="' + escapeHtml(m.name) + '">Remove</button>'
                : '';
            return '<div class="estate-tools-manager-row">' +
                '<span class="estate-tools-manager-name">' + escapeHtml(m.name) + '</span>' +
                removeBtn +
                '</div>';
        }).join('') +
        '</div>';
}
// Event delegation for "Remove" buttons — survives renderEstateManagerList()
// replacing the list's innerHTML on every load/add/remove, since the
// listener lives on the (never-replaced) parent area rather than on the
// buttons themselves.
document.getElementById('estateToolsManagerArea').addEventListener('click', function(e) {
    const btn = e.target.closest('.estate-tools-remove-btn');
    if (!btn) return;
    openRemoveManagerModal(btn.dataset.uuid, btn.dataset.name);
});

/* ── Add manager: typeahead (adapted from publicprofile.php's live search,
       flattened to top-level functions/vars to match this file's prevailing
       style rather than publicprofile.php's IIFE) ──────────────────────── */
let estateToolsAddDebounceTimer = null;
let estateToolsAddLastQuery     = '';

const estateToolsAddInputEl   = document.getElementById('estateToolsAddInput');
const estateToolsAddResultsEl = document.getElementById('estateToolsAddResults');

estateToolsAddInputEl.addEventListener('input', function () {
    const q = this.value.trim();
    clearTimeout(estateToolsAddDebounceTimer);

    if (q.length < 2) {
        closeEstateToolsAddResults();
        return;
    }
    if (q === estateToolsAddLastQuery) return;

    estateToolsAddDebounceTimer = setTimeout(function() { doEstateToolsAddSearch(q); }, 280);
});

estateToolsAddInputEl.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeEstateToolsAddResults(); return; }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        const first = estateToolsAddResultsEl.querySelector('.estate-tools-add-result-item');
        if (first) first.focus();
    }
});

estateToolsAddResultsEl.addEventListener('keydown', function (e) {
    const items = [...estateToolsAddResultsEl.querySelectorAll('.estate-tools-add-result-item')];
    const idx   = items.indexOf(document.activeElement);
    if (e.key === 'ArrowDown' && idx < items.length - 1) {
        e.preventDefault(); items[idx + 1].focus();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (idx <= 0) estateToolsAddInputEl.focus();
        else items[idx - 1].focus();
    } else if (e.key === 'Escape') {
        closeEstateToolsAddResults(); estateToolsAddInputEl.focus();
    }
});

// Delegated click handling for result items — see renderEstateToolsAddResults()
// below for why these carry data-* attributes rather than onclick="...".
estateToolsAddResultsEl.addEventListener('click', function (e) {
    const btn = e.target.closest('.estate-tools-add-result-item');
    if (!btn) return;
    addEstateManager(btn.dataset.uuid, btn.dataset.fullname);
});

document.addEventListener('click', function (e) {
    const wrap = document.getElementById('estateToolsAddWrap');
    if (wrap && !wrap.contains(e.target)) {
        closeEstateToolsAddResults();
    }
});

function doEstateToolsAddSearch(q) {
    if (!estateToolsData) return;
    estateToolsAddLastQuery = q;
    estateToolsAddResultsEl.innerHTML = '<div class="estate-tools-add-results-searching">Searching…</div>';
    estateToolsAddResultsEl.classList.add('open');

    fetch('estate_tools.php?' + new URLSearchParams({
        action: 'search_residents', estate_id: String(estateToolsData.estateId), q: q, csrf: PORTAL_CSRF,
    }))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) { showEstateToolsAddError(data.error || 'Search failed.'); return; }
            renderEstateToolsAddResults(data.results, q);
        })
        .catch(function() { showEstateToolsAddError('Search failed. Please try again.'); });
}

function renderEstateToolsAddResults(items, q) {
    if (items.length === 0) {
        estateToolsAddResultsEl.innerHTML = '<div class="estate-tools-add-results-empty">No matching residents found for "' + escapeHtml(q) + '"</div>';
        return;
    }
    // data-* attributes rather than an onclick="..." string built with
    // embedded names — see renderEstateManagerList()'s comment above for
    // why. Click handling is delegated above, on estateToolsAddResultsEl.
    estateToolsAddResultsEl.innerHTML = items.map(function(r) {
        return '<button type="button" class="estate-tools-add-result-item" ' +
            'data-uuid="' + escapeHtml(r.uuid) + '" data-fullname="' + escapeHtml(r.fullname) + '">' +
            escapeHtml(r.fullname) +
            '</button>';
    }).join('');
}

function showEstateToolsAddError(msg) {
    estateToolsAddResultsEl.innerHTML = '<div class="estate-tools-add-results-empty">' + escapeHtml(msg) + '</div>';
}

function closeEstateToolsAddResults() {
    estateToolsAddResultsEl.classList.remove('open');
    estateToolsAddResultsEl.innerHTML = '';
    estateToolsAddLastQuery = '';
}

function addEstateManager(uuid, fullname) {
    if (!estateToolsData) return;
    const estateId = estateToolsData.estateId;
    const status   = document.getElementById('estateToolsAddStatus');
    const input    = document.getElementById('estateToolsAddInput');

    closeEstateToolsAddResults();
    input.value = '';
    status.textContent = 'Adding ' + fullname + '…';

    fetch('estate_tools.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'add_manager', estate_id: String(estateId), uuid: uuid, csrf: PORTAL_CSRF,
        }),
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!estateToolsData || estateToolsData.estateId !== estateId) return;

            if (!data.ok) {
                status.textContent = data.error || 'Could not add manager — please try again.';
                return;
            }

            status.textContent = fullname + ' added as an estate manager.';
            renderEstateManagerList(data.managers);
        })
        .catch(function() {
            if (!estateToolsData || estateToolsData.estateId !== estateId) return;
            status.textContent = 'Could not add manager — please try again.';
        });
}

/* ── Remove manager: confirm modal ───────────────────────────────────── */
let removeManagerTarget = null;

function openRemoveManagerModal(uuid, name) {
    if (!estateToolsData) return;
    removeManagerTarget = { uuid: uuid, name: name, estateId: estateToolsData.estateId };

    document.getElementById('removeManagerName').textContent       = name;
    document.getElementById('removeManagerEstateName').textContent = estateToolsData.name;
    document.getElementById('removeManagerStatus').textContent     = '';

    const o = document.getElementById('removeManagerModal');
    o.classList.add('open');
    o.setAttribute('aria-hidden', 'false');
}

function closeRemoveManagerModal() {
    const o = document.getElementById('removeManagerModal');
    o.classList.remove('open');
    o.setAttribute('aria-hidden', 'true');
    removeManagerTarget = null;
}
document.getElementById('removeManagerModal').addEventListener('click', function(e) {
    if (e.target === this) closeRemoveManagerModal();
});

function doRemoveManager() {
    if (!removeManagerTarget) return;
    const target      = removeManagerTarget;
    const confirmBtn  = document.getElementById('removeManagerConfirmBtn');
    const status      = document.getElementById('removeManagerStatus');

    confirmBtn.disabled = true;
    status.textContent  = 'Removing…';

    fetch('estate_tools.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'remove_manager', estate_id: String(target.estateId), uuid: target.uuid, csrf: PORTAL_CSRF,
        }),
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            confirmBtn.disabled = false;

            if (!data.ok) {
                status.textContent = data.error || 'Could not remove manager — please try again.';
                return;
            }

            closeRemoveManagerModal();
            if (estateToolsData && estateToolsData.estateId === target.estateId) {
                renderEstateManagerList(data.managers);
            }
        })
        .catch(function() {
            confirmBtn.disabled = false;
            status.textContent = 'Could not remove manager — please try again.';
        });
}

/* ── Extend Escape to cover the region modal, Estate Tools modal, and the
       remove-manager confirm modal (checked first, since it can be open
       ON TOP of the Estate Tools modal — Escape should close the topmost
       one first rather than both at once) ─────────────────────────────── */
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Escape') return;

    if (document.getElementById('removeManagerModal').classList.contains('open')) {
        closeRemoveManagerModal();
        return;
    }
    if (document.getElementById('regionModalOverlay').classList.contains('open')) {
        closeRegionModal();
    }
    if (document.getElementById('estateToolsModalOverlay').classList.contains('open')) {
        closeEstateToolsModal();
    }
});
</script>
</body>
</html>
