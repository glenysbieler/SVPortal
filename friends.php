<?php
/**
 * friends.php — Friends List
 *
 * Displays the logged-in user's friends from the Friends table.
 * Presence (online status) is shown for local friends where the friend
 * has granted the "can see my online status" permission (Flags & 1).
 * Hypergrid friends are identified by a URI in the Friend column and
 * are always shown as "Status unknown" with a prominent disclaimer.
 *
 * Privacy: we respect the Flags field exactly as the in-world viewer does.
 * If a friend has not granted online visibility (Flags & 1 == 0), we do
 * NOT query or display their presence — same behaviour as the viewer.
 *
 * Session: real authentication via includes/auth.php
 * Data:    SELECT only — Friends, UserAccounts, Presence tables
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/estates.php';

session_start_secure();
require_login();

// ─── Constants ───────────────────────────────────────────────────────────────

// Friends table Flags bitmask: bit 1 = friend can see your online status
define('FRIEND_FLAG_ONLINE_VISIBLE', 1);

// ─── Data ────────────────────────────────────────────────────────────────────

$session_user = get_session_user();
$user_uuid    = $session_user['uuid'];

/**
 * Determine whether a Friend column value is a hypergrid URI.
 * Local friends are plain UUIDs (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx).
 * HG friends contain a URL: http://othergrid.example.com:8002/UUID
 */
function is_hypergrid_friend(string $friend_id): bool
{
    // HG friends are stored as: UUID;http://host:port/;Name;shortid
    // Local friends are plain UUIDs only.
    // Detect by presence of 'http' after a semicolon, or a bare http URI.
    return str_contains($friend_id, ';http') || str_starts_with($friend_id, 'http');
}

/**
 * Parse a hypergrid friend URI into display components.
 * Format: UUID;http://host:port/;First Last;shortid
 *
 * Returns array with keys: grid_url, display_name, grid_host
 */
function parse_hg_friend(string $uri): array
{
    $parts       = explode(';', $uri);
    $grid_url    = isset($parts[1]) ? trim($parts[1]) : '';
    $name        = isset($parts[2]) ? trim($parts[2]) : '';
    $grid_host   = '';

    if ($grid_url) {
        $parsed    = parse_url($grid_url);
        $grid_host = $parsed['host'] ?? '';
        if (!empty($parsed['port'])) $grid_host .= ':' . $parsed['port'];
    }

    return [
        'grid_url'     => $grid_url,
        'display_name' => $name,
        'grid_host'    => $grid_host,
    ];
}

$friends  = [];
$db_error = null;

try {
    $db = get_db();

    // ── Step 1: load this user's friend rows ──────────────────────────────────
    $stmt = $db->prepare('
        SELECT Friend, Flags
        FROM   Friends
        WHERE  PrincipalID = ?
    ');
    $stmt->execute([$user_uuid]);
    $rows = $stmt->fetchAll();

    // ── Step 2: split into local (UUID) and hypergrid (URI) ───────────────────
    $local_friends = [];
    $hg_friends    = [];

    foreach ($rows as $row) {
        if (is_hypergrid_friend($row['Friend'])) {
            $hg_friends[] = ['uri' => $row['Friend'], 'flags' => (int)$row['Flags']];
        } else {
            $local_friends[$row['Friend']] = (int)$row['Flags'];
        }
    }

    // ── Step 3: look up names for local friends ────────────────────────────────
    $local_names = [];
    if (!empty($local_friends)) {
        $placeholders = implode(',', array_fill(0, count($local_friends), '?'));
        $stmt = $db->prepare("
            SELECT PrincipalID, FirstName, LastName
            FROM   UserAccounts
            WHERE  PrincipalID IN ($placeholders)
        ");
        $stmt->execute(array_keys($local_friends));
        foreach ($stmt->fetchAll() as $u) {
            $local_names[$u['PrincipalID']] = trim($u['FirstName'] . ' ' . $u['LastName']);
        }
    }

    // ── Step 4: collect UUIDs that have granted online visibility ─────────────
    $local_uuids = array_filter(
        $local_friends,
        fn($flags) => (bool)($flags & FRIEND_FLAG_ONLINE_VISIBLE)
    );

    // ── Step 5: single Presence query for all visible-status friends ──────────
    $online_uuids = [];
    if (!empty($local_uuids)) {
        $placeholders = implode(',', array_fill(0, count($local_uuids), '?'));
        $stmt = $db->prepare("
            SELECT UserID
            FROM   Presence
            WHERE  UserID IN ($placeholders)
              AND  RegionID != '00000000-0000-0000-0000-000000000000'
        ");
        $stmt->execute(array_keys($local_uuids));
        foreach ($stmt->fetchAll() as $p) {
            $online_uuids[$p['UserID']] = true;
        }
    }

    // ── Step 6: assemble local friend records ─────────────────────────────────
    foreach ($local_friends as $uuid => $flags) {
        $can_see_online = (bool)($flags & FRIEND_FLAG_ONLINE_VISIBLE);

        if ($can_see_online) {
            $status = isset($online_uuids[$uuid]) ? 'online' : 'offline';
        } else {
            // Friend has not granted online visibility — honour that
            $status = 'hidden';
        }

        $friends[] = [
            'type'        => 'local',
            'uuid'        => $uuid,
            'name'        => $local_names[$uuid] ?? 'Unknown User',
            'status'      => $status,   // 'online' | 'offline' | 'hidden'
            'is_local'    => true,
            'grid_label'  => null,
        ];
    }

    // ── Step 7: assemble hypergrid friend records ─────────────────────────────
    foreach ($hg_friends as $hg) {
        $parsed = parse_hg_friend($hg['uri']);
        $name   = $parsed['display_name'] ?: ($parsed['grid_host'] ? 'Visitor @ ' . $parsed['grid_host'] : 'Hypergrid Visitor');
        $grid   = $parsed['grid_url'];

        $friends[] = [
            'type'       => 'hypergrid',
            'uuid'       => $hg['uri'],   // full URI stored as identifier
            'name'       => $name,
            'status'     => 'unknown',    // always — HG presence is unreliable
            'is_local'   => false,
            'grid_label' => $grid,
        ];
    }

    // ── Step 8: sort — online first, then offline, then hidden, then HG ──────
    usort($friends, function(array $a, array $b): int {
        $order = ['online' => 0, 'offline' => 1, 'hidden' => 2, 'unknown' => 3];
        $ao = $order[$a['status']] ?? 4;
        $bo = $order[$b['status']] ?? 4;
        if ($ao !== $bo) return $ao - $bo;
        return strcasecmp($a['name'], $b['name']);
    });

} catch (Throwable $e) {
    $db_error = 'Could not load friends list. Please try again later.';
}

$friend_count = count($friends);
$online_count = count(array_filter($friends, fn($f) => $f['status'] === 'online'));
$hg_count     = count(array_filter($friends, fn($f) => $f['type'] === 'hypergrid'));

// ─── Nav / presence state ─────────────────────────────────────────────────────

$full_name = htmlspecialchars(
    ($session_user['firstname'] ?? '') . ' ' . ($session_user['lastname'] ?? '')
);

[
    'status_class'   => $status_class,
    'status_label'   => $status_label,
    'status_tooltip' => $status_tooltip,
] = build_presence_display($session_user);

$prefs      = get_portal_prefs();
$bg_enabled = $prefs['bg'];

// ─── CSRF token for AJAX calls ────────────────────────────────────────────────
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

// ─── Unread notification count (for nav badge) ────────────────────────────────
$unread_notifications = get_unread_notification_count($user_uuid);

// ─── My own partner UUID (for offer-button logic) ─────────────────────────────
$my_partner_uuid = '00000000-0000-0000-0000-000000000000';
if (defined('ENABLE_PARTNERSHIPS') && ENABLE_PARTNERSHIPS) {
    try {
        $db   = get_db();
        $stmt = $db->prepare(
            "SELECT profilePartner FROM userprofile WHERE useruuid = ? LIMIT 1"
        );
        $stmt->execute([$user_uuid]);
        $row = $stmt->fetch();
        if ($row) $my_partner_uuid = $row['profilePartner'];
    } catch (Throwable) { /* non-fatal */ }
}

// ─── Pending partner offers I have already SENT (suppress duplicate button) ───
$sent_offers = [];
if (defined('ENABLE_PARTNERSHIPS') && ENABLE_PARTNERSHIPS) {
    try {
        $db   = get_db();
        $stmt = $db->prepare(
            "SELECT to_uuid FROM portal_notifications
             WHERE from_uuid = ? AND type = 'partner_offer' AND is_read = 0"
        );
        $stmt->execute([$user_uuid]);
        foreach ($stmt->fetchAll() as $r) {
            $sent_offers[$r['to_uuid']] = true;
        }
    } catch (Throwable) { /* non-fatal */ }
}

// ─── Estate access (for "My Estates" drawer item) ─────────────────────────────
$has_estate_access = user_has_estate_access(get_db(), $user_uuid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Friends — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
    <script>const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;
    const PARTNERSHIPS_ENABLED = <?= json_encode(defined('ENABLE_PARTNERSHIPS') && ENABLE_PARTNERSHIPS) ?>;
    const MY_PARTNER_UUID = <?= json_encode($my_partner_uuid) ?>;
    const SENT_OFFERS     = <?= json_encode(array_keys($sent_offers)) ?>;
    const NULL_UUID       = '00000000-0000-0000-0000-000000000000';
    </script>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('friends', [], (int)($session_user['userlevel'] ?? 0), $has_estate_access); ?>


<!-- ══════════════════════════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════════════════════════ -->
<div class="page-wrap">

    <main class="main-card" id="main-content">

        <!-- Card header -->
        <div class="card-header">
            <div class="card-header-left">
                <h1 class="card-title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    Friends
                </h1>
                <p class="card-subtitle">
                    <?php if ($friend_count > 0): ?>
                        <?= $online_count ?> online · <?= $friend_count ?> total
                        <?php if ($hg_count > 0): ?>
                            · <?= $hg_count ?> hypergrid
                        <?php endif; ?>
                    <?php else: ?>
                        Your in-world friends list
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($friend_count > 0): ?>
                <span class="friends-count-badge">
                    <?= $friend_count ?> friend<?= $friend_count !== 1 ? 's' : '' ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!$db_error && $friend_count > 0): ?>

        <!-- Presence disclaimer -->
        <div class="presence-notice" role="note" aria-label="Presence accuracy notice">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p class="presence-notice-text">
                <strong>About online status:</strong>
                Presence data is read directly from the OpenSim database and may not be accurate in real time —
                particularly for <strong>hypergrid visitors</strong>, who may appear online after logging out if the
                logout signal did not reach this grid. Hypergrid friends are always shown as
                <strong>Status unknown</strong>. For local friends, status is only shown where they have
                granted permission for you to see it in-world.
            </p>
        </div>

        <!-- Filter / search bar -->
        <div class="filter-bar">
            <div class="search-wrap">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="search"
                       class="search-input"
                       id="friendSearch"
                       placeholder="Search friends…"
                       aria-label="Search friends"
                       oninput="filterFriends()">
            </div>
            <div class="filter-tabs" role="group" aria-label="Filter by status">
                <button class="filter-tab active" data-filter="all"     onclick="setFilter(this)">All</button>
                <button class="filter-tab"         data-filter="online"  onclick="setFilter(this)">Online</button>
                <button class="filter-tab"         data-filter="offline" onclick="setFilter(this)">Offline</button>
                <?php if ($hg_count > 0): ?>
                <button class="filter-tab"         data-filter="hypergrid" onclick="setFilter(this)">Hypergrid</button>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>

        <?php if ($db_error): ?>
            <div class="state-error" role="alert">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <p><?= htmlspecialchars($db_error) ?></p>
            </div>

        <?php elseif (empty($friends)): ?>
            <div class="state-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.3" aria-hidden="true">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <p>No friends found.<br>Add friends from within the world and they'll appear here.</p>
            </div>

        <?php else: ?>
            <ul class="friends-list" id="friendsList" role="list" aria-label="Friends list">
                <?php foreach ($friends as $f): ?>
                <?php
                    if (str_contains($f['name'], '://')) {
                        $initials = 'HG';
                    } else {
                        $name_parts = explode(' ', $f['name']);
                        $initials   = strtoupper(substr($name_parts[0] ?? '?', 0, 1));
                        if (isset($name_parts[1])) $initials .= strtoupper(substr($name_parts[1], 0, 1));
                    }

                    [$status_txt, $status_cls, $pip_cls, $aria_status] = match($f['status']) {
                        'online'  => ['Online',         's-online',  'pip-online',  'Online'],
                        'offline' => ['Offline',        's-offline', 'pip-offline', 'Offline'],
                        'hidden'  => ['Status private', 's-hidden',  'pip-hidden',  'Status not shared'],
                        default   => ['Status unknown', 's-unknown', 'pip-unknown', 'Status unknown'],
                    };

                    $data_filter = $f['type'] === 'hypergrid' ? 'hypergrid'
                                 : ($f['status'] === 'online' ? 'online' : 'offline');
                ?>
                <li class="friend-card"
                    data-name="<?= htmlspecialchars(strtolower($f['name'])) ?>"
                    data-filter="<?= $data_filter ?>"
                    role="listitem">

                    <div class="friend-avatar" aria-hidden="true">
                        <?= htmlspecialchars($initials) ?>
                        <span class="friend-avatar-pip <?= $pip_cls ?>"
                              title="<?= htmlspecialchars($aria_status) ?>"></span>
                    </div>

                    <div class="friend-info">
                        <div class="friend-name">
                            <?php if ($f['is_local']): ?>
                                <button type="button"
                                        class="friend-name-btn"
                                        onclick="openProfileModal(<?= htmlspecialchars(json_encode($f['uuid']), ENT_QUOTES) ?>)"
                                        aria-label="View profile of <?= htmlspecialchars($f['name']) ?>">
                                    <?= htmlspecialchars($f['name']) ?>
                                </button>
                            <?php else: ?>
                                <?= htmlspecialchars($f['name']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="friend-meta">
                            <?php if ($f['type'] === 'hypergrid'): ?>
                                <span class="hg-badge">Hypergrid</span>
                                <?php if ($f['grid_label']): ?>
                                    <span title="Home grid"><?= htmlspecialchars($f['grid_label']) ?></span>
                                <?php endif; ?>
                            <?php elseif ($f['status'] === 'hidden'): ?>
                                <span>Online status not shared</span>
                            <?php else: ?>
                                <span><?= htmlspecialchars($f['uuid']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="friend-status <?= $status_cls ?>"
                          aria-label="Status: <?= htmlspecialchars($aria_status) ?>">
                        <?= htmlspecialchars($status_txt) ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>

            <p class="no-results" id="noResults" role="status" aria-live="polite">
                No friends match your search.
            </p>
        <?php endif; ?>

    </main>
</div>

<!-- ── Friend profile modal ─────────────────────────────────────────────────── -->
<div class="fp-overlay" id="fpOverlay"
     role="dialog" aria-modal="true"
     aria-labelledby="fpName" aria-hidden="true">
    <div class="fp-modal">

        <!-- Loading state -->
        <div class="fp-loading" id="fpLoading" aria-live="polite">
            <div class="fp-spinner" aria-hidden="true"></div>
            <p>Loading profile…</p>
            <button class="fp-close fp-close-standalone" onclick="closeProfileModal()" aria-label="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Error state -->
        <div class="fp-error" id="fpError" style="display:none;" role="alert">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p id="fpErrorMsg">Could not load profile.</p>
            <button class="fp-close fp-close-standalone" onclick="closeProfileModal()" aria-label="Close">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <!-- Profile content -->
        <div class="fp-content" id="fpContent" style="display:none;">

            <!-- Left column -->
            <div class="fp-left">
                <div class="fp-avatar-wrap">
                    <img id="fpAvatar" src="" alt="">
                </div>

                <div class="fp-identity">
                    <div class="fp-fullname" id="fpName"></div>

                    <dl class="fp-meta">
                        <div class="fp-meta-row fp-meta-row--stacked">
                            <dt class="fp-meta-label">UUID</dt>
                            <dd class="fp-meta-value fp-uuid" id="fpUUID"></dd>
                        </div>
                        <div class="fp-meta-row" id="fpPartnerRow" style="display:none;">
                            <dt class="fp-meta-label">Partner</dt>
                            <dd class="fp-meta-value fp-partner" id="fpPartner">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"
                                     stroke="none" aria-hidden="true" style="color:var(--clr-accent-rose);vertical-align:-1px;">
                                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                                </svg>
                                <span id="fpPartnerName"></span>
                            </dd>
                        </div>
                    </dl>
                </div>

                <!-- Partnership offer button — shown only when neither party has a partner
                     and no offer has already been sent. Local friends only. -->
                <div id="fpPartnerOfferWrap" style="display:none;">
                    <button class="btn-offer-partner" id="fpOfferPartnerBtn"
                            onclick="sendPartnerOffer()"
                            aria-label="Offer partnership">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"
                             stroke="none" aria-hidden="true">
                            <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                        </svg>
                        Offer Partnership
                    </button>
                    <p class="fp-offer-status" id="fpOfferStatus"></p>
                </div>

                <div class="fp-divider" role="separator"></div>

                <div class="fp-about-section">
                    <p class="fp-section-label">About</p>
                    <p class="fp-about-text" id="fpAbout"></p>
                </div>

                <div class="fp-member-since" id="fpSince">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2"/>
                        <path d="M16 2v4M8 2v4M3 10h18"/>
                    </svg>
                    <span id="fpSinceText"></span>
                </div>
            </div>

            <!-- Right column: picks -->
            <div class="fp-right">
                <div class="picks-header">
                    <h2 class="picks-title">Picks</h2>
                    <div class="fp-picks-header-right">
                        <span class="picks-count" id="fpPicksCount"></span>
                        <button class="fp-close" id="fpClose" onclick="closeProfileModal()" aria-label="Close profile">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                 stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18"/>
                                <line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="fp-picks-empty" id="fpPicksEmpty" style="display:none;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" opacity="0.4" aria-hidden="true">
                        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <p>No picks yet.</p>
                </div>
                <ul class="picks-list fp-picks-list" id="fpPicksList" role="list"></ul>
            </div>

        </div><!-- /.fp-content -->
    </div><!-- /.fp-modal -->
</div>

<!-- Pick detail modal (reused for friend profile picks) -->
<div class="pick-modal-overlay" id="fpPickModal"
     aria-hidden="true" role="dialog" aria-modal="true"
     aria-labelledby="fpPickModalTitle">
    <div class="pick-modal">
        <div class="pick-modal-img-wrap">
            <img id="fpPickModalImg" src="" alt="">
        </div>
        <div class="pick-modal-body">
            <h2 class="pick-modal-name" id="fpPickModalTitle"></h2>
            <div class="pick-modal-location" id="fpPickModalLocation"></div>
            <p class="pick-modal-desc" id="fpPickModalDesc"></p>
        </div>
        <div class="pick-modal-footer">
            <button class="pick-modal-close" onclick="closeFpPickModal()">Close</button>
        </div>
    </div>
</div>


<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_shared_js(); ?>

<script>
/* ── Filter / search ─────────────────────────────────────────────── */
let currentFilter = 'all';

function setFilter(btn) {
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    currentFilter = btn.dataset.filter;
    applyFilters();
}

function filterFriends() { applyFilters(); }

function applyFilters() {
    const query   = document.getElementById('friendSearch').value.toLowerCase().trim();
    const cards   = document.querySelectorAll('#friendsList .friend-card');
    let   visible = 0;

    cards.forEach(card => {
        const nameMatch   = card.dataset.name.includes(query);
        const filterMatch = currentFilter === 'all' || card.dataset.filter === currentFilter;
        const show        = nameMatch && filterMatch;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const noResults = document.getElementById('noResults');
    if (noResults) noResults.style.display = visible === 0 ? 'block' : 'none';
}

/* ── Friend profile modal ──────────────────────────────────────── */

function openProfileModal(uuid) {
    const overlay  = document.getElementById('fpOverlay');
    const loading  = document.getElementById('fpLoading');
    const error    = document.getElementById('fpError');
    const content  = document.getElementById('fpContent');

    // Reset state
    loading.style.display = '';
    error.style.display   = 'none';
    content.style.display = 'none';

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.getElementById('fpClose').focus();

    // Fetch profile data
    fetch('profile_viewer.php?uuid=' + encodeURIComponent(uuid)
          + '&csrf=' + encodeURIComponent(PORTAL_CSRF))
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error(data.error || 'Unknown error');
            renderProfileModal(data.profile, data.picks);
            loading.style.display = 'none';
            content.style.display = '';
        })
        .catch(err => {
            loading.style.display = 'none';
            document.getElementById('fpErrorMsg').textContent =
                err.message || 'Could not load profile. Please try again.';
            error.style.display = '';
        });
}

function closeProfileModal() {
    const overlay = document.getElementById('fpOverlay');
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    // Also close pick detail if open
    closeFpPickModal();
}

function renderProfileModal(p, picks) {
    // Avatar
    document.getElementById('fpAvatar').src = p.image_url;
    document.getElementById('fpAvatar').alt = 'Profile picture of ' + p.fullname;

    // Identity
    document.getElementById('fpName').textContent = p.fullname;
    document.getElementById('fpUUID').textContent  = p.uuid;

    // Partner
    const partnerRow = document.getElementById('fpPartnerRow');
    if (p.partner_name) {
        document.getElementById('fpPartnerName').textContent = p.partner_name;
        partnerRow.style.display = '';
    } else {
        partnerRow.style.display = 'none';
    }

    // Partnership offer button logic
    const offerWrap = document.getElementById('fpPartnerOfferWrap');
    const offerStatus = document.getElementById('fpOfferStatus');
    offerStatus.textContent = '';
    offerStatus.className = 'fp-offer-status';

    // Show offer button only when:
    //   - Partnerships are enabled on this portal
    //   - This is a local (non-hypergrid) friend
    //   - Neither party has a partner
    //   - We have not already sent an offer to this person
    const theyHavePartner = p.partner_uuid && p.partner_uuid !== NULL_UUID;
    const iHavePartner    = MY_PARTNER_UUID && MY_PARTNER_UUID !== NULL_UUID;
    const alreadySent     = SENT_OFFERS.includes(p.uuid);

    if (PARTNERSHIPS_ENABLED
        && !theyHavePartner
        && !iHavePartner
        && !alreadySent) {
        offerWrap.style.display = '';
        document.getElementById('fpOfferPartnerBtn').disabled = false;
        // Store current target UUID for the send function
        document.getElementById('fpOfferPartnerBtn').dataset.targetUuid = p.uuid;
        document.getElementById('fpOfferPartnerBtn').dataset.targetName = p.fullname;
    } else {
        offerWrap.style.display = 'none';
    }

    // About
    const aboutEl = document.getElementById('fpAbout');
    if (p.about) {
        aboutEl.innerHTML = linkifyText(escapeHtml(p.about)).replace(/\n/g, '<br>');
    } else {
        aboutEl.innerHTML = '<em>No about text set.</em>';
    }

    // Member since
    const sinceDate = new Date(p.created * 1000);
    document.getElementById('fpSinceText').textContent =
        'Member since ' + sinceDate.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

    // Picks
    const nonBlank  = picks.filter(pk => !pk.is_blank);
    const countEl   = document.getElementById('fpPicksCount');
    const emptyEl   = document.getElementById('fpPicksEmpty');
    const listEl    = document.getElementById('fpPicksList');

    countEl.textContent = nonBlank.length
        ? nonBlank.length + ' place' + (nonBlank.length !== 1 ? 's' : '')
        : '';
    listEl.innerHTML = '';

    if (nonBlank.length === 0) {
        emptyEl.style.display = '';
        listEl.style.display  = 'none';
    } else {
        emptyEl.style.display = 'none';
        listEl.style.display  = '';

        nonBlank.forEach(pk => {
            const coords = (pk.pos_x || pk.pos_y || pk.pos_z)
                ? ` (${pk.pos_x}, ${pk.pos_y}, ${pk.pos_z})` : '';
            const locHtml = pk.sim_name
                ? `<div class="pick-location"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/></svg>${escapeHtml(pk.sim_name)}${escapeHtml(coords)}</div>` : '';
            const descHtml = pk.description
                ? `<p class="pick-desc">${linkifyText(escapeHtml(pk.description))}</p>` : '';
            const descHtmlEncoded = escapeHtml(
                pk.description ? linkifyText(escapeHtml(pk.description)) : ''
            );

            const li = document.createElement('li');
            li.className = 'pick-card';
            li.setAttribute('role', 'listitem');
            li.setAttribute('tabindex', '0');
            li.style.cursor = 'pointer';
            li.dataset.name    = pk.name || 'Unnamed';
            li.dataset.img     = pk.image_url;
            li.dataset.sim     = pk.sim_name || '';
            li.dataset.x       = pk.pos_x;
            li.dataset.y       = pk.pos_y;
            li.dataset.z       = pk.pos_z;
            li.dataset.descHtml = linkifyText(escapeHtml(pk.description || ''));

            li.innerHTML = `
                <div class="pick-image"><img src="${escapeHtml(pk.image_url)}" alt="${escapeHtml(pk.name || 'Pick')}" loading="lazy"></div>
                <div class="pick-body">
                    <div class="pick-name">${escapeHtml(pk.name || 'Unnamed')}</div>
                    ${locHtml}${descHtml}
                </div>`;

            li.addEventListener('click',   () => openFpPickModal(li));
            li.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') openFpPickModal(li); });
            listEl.appendChild(li);
        });
    }
}

/* ── Partnership offer ────────────────────────────────────────── */
function sendPartnerOffer() {
    const btn        = document.getElementById('fpOfferPartnerBtn');
    const statusEl   = document.getElementById('fpOfferStatus');
    const targetUuid = btn.dataset.targetUuid;
    const targetName = btn.dataset.targetName;

    if (!targetUuid) return;

    btn.disabled = true;
    statusEl.textContent = 'Sending offer…';
    statusEl.className   = 'fp-offer-status';

    const fd = new FormData();
    fd.append('csrf',        PORTAL_CSRF);
    fd.append('action',      'offer');
    fd.append('target_uuid', targetUuid);

    fetch('partner_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error(data.error || 'Could not send offer.');
            statusEl.textContent = '💕 Partnership offer sent to ' + escapeHtml(targetName) + '!';
            statusEl.className   = 'fp-offer-status success';
            // Add to local sent-offers set so button won't re-appear if modal is reopened
            SENT_OFFERS.push(targetUuid);
        })
        .catch(err => {
            btn.disabled         = false;
            statusEl.textContent = err.message;
            statusEl.className   = 'fp-offer-status error';
        });
}

/* ── Friend pick detail modal ─────────────────────────────────── */
function openFpPickModal(card) {
    const overlay  = document.getElementById('fpPickModal');
    const name     = card.dataset.name    || 'Unnamed';
    const img      = card.dataset.img     || '';
    const sim      = card.dataset.sim     || '';
    const x        = card.dataset.x;
    const y        = card.dataset.y;
    const z        = card.dataset.z;
    const descHtml = card.dataset.descHtml || '';

    document.getElementById('fpPickModalImg').src              = img;
    document.getElementById('fpPickModalImg').alt              = name;
    document.getElementById('fpPickModalTitle').textContent    = name;
    document.getElementById('fpPickModalDesc').innerHTML       = descHtml;

    const locEl = document.getElementById('fpPickModalLocation');
    if (sim) {
        const coords = (x || y || z) ? ` (${x}, ${y}, ${z})` : '';
        locEl.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/></svg>${sim}${coords}`;
    } else {
        locEl.textContent = '';
    }

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    overlay.querySelector('.pick-modal-close').focus();
}

function closeFpPickModal() {
    const overlay = document.getElementById('fpPickModal');
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
}

document.getElementById('fpPickModal').addEventListener('click', function(e) {
    if (e.target === this) closeFpPickModal();
});

/* ── Utility ──────────────────────────────────────────────────── */
// escapeHtml() is defined in render_shared_js() (helpers.php) — available on all pages.

function linkifyText(html) {
    // Pass 1: Firestorm [https://url Label] syntax → named anchor
    html = html.replace(
        /\[(https?:\/\/(?:(?!&amp;)[^\s\[\]<>"']|&amp;)+)\s([^\]]+)\]/gi,
        (_, url, label) => `<a href="${url.replace(/&amp;/g,'&')}" target="_blank" rel="noopener noreferrer" class="inline-link">${label.trim()}</a>`
    );
    // Pass 2: plain bare URLs (skip those already in href="...")
    html = html.replace(
        /(?<!href=")(https?:\/\/(?:(?!&amp;)[^\s<>"']|&amp;)+)(?<![.,)!\]?])/gi,
        url => `<a href="${url.replace(/&amp;/g,'&')}" target="_blank" rel="noopener noreferrer" class="inline-link">${url}</a>`
    );
    return html;
}

/* ── Extend Escape to cover profile modal ────────────────────── */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (document.getElementById('fpPickModal').classList.contains('open')) {
            closeFpPickModal();
        } else if (document.getElementById('fpOverlay').classList.contains('open')) {
            closeProfileModal();
        }
    }
});

// Close profile modal when clicking backdrop
document.getElementById('fpOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeProfileModal();
});

/* ── Auto-open friend profile modal if ?view=<UUID> is present ──── */
(function () {
    const params = new URLSearchParams(window.location.search);
    const viewUuid = params.get('view');
    const uuidRe = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    if (viewUuid && uuidRe.test(viewUuid)) {
        openProfileModal(viewUuid);
    }
})();

</script>

</body>
</html>
