<?php
/**
 * profile.php — User Profile page
 *
 * Displays the logged-in user's in-world profile including:
 *   - Username and UUID (from UserAccounts)
 *   - Profile picture (from ROBUST asset service via php-imagick)
 *   - About text (from userprofile table)
 *   - Profile picks (from userprofile table)
 *   - Partner name (from userprofile.profilePartner UUID)
 *
 * When PUBLIC_PROFILES is enabled in config.php, a "Make my profile public"
 * toggle is shown at the bottom of the left column. The preference is stored
 * in portal_prefs.public_profile (TINYINT, default 0). This only controls
 * public (unauthenticated) access to the user's profile — it does NOT affect
 * visibility between logged-in users (e.g. the friend profile modal).
 *
 * Session: real authentication via includes/auth.php
 * Profile data: real DB queries via includes/profile_data.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/assets.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/estates.php';

// Start session and enforce login — redirects to login.php if not authenticated
session_start_secure();
require_login();

// ─── Data ────────────────────────────────────────────────────────────────────
$session_user = get_session_user();
$profile      = get_user_profile($session_user['uuid']);
$picks        = get_user_picks($session_user['uuid']);

$full_name   = htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']);
$uuid        = htmlspecialchars($profile['uuid']);
$about       = htmlspecialchars($profile['about_text']);
$profile_img = get_profile_image_url($profile['profile_image_uuid']);

// Partner — look up name from partner UUID if one is set
$partner_name = get_partner_name($profile['partner_uuid'] ?? '00000000-0000-0000-0000-000000000000');
$partner_uuid = htmlspecialchars($profile['partner_uuid'] ?? '');
$partner_link = $partner_name !== null
    ? get_partner_link_url($session_user['uuid'], $profile['partner_uuid'] ?? '00000000-0000-0000-0000-000000000000')
    : null;

// Member since — formatted from Unix timestamp
$member_since = date('F Y', $profile['created']);

// ─── Estate access (for "My Estates" drawer item) ─────────────────────────────
$has_estate_access = user_has_estate_access(get_db(), $session_user['uuid']);

// ─── Presence / nav state ─────────────────────────────────────────────────────
[
    'status_class'   => $status_class,
    'status_label'   => $status_label,
    'status_tooltip' => $status_tooltip,
] = build_presence_display($session_user);

// ─── Theme / display preferences (cookie) ────────────────────────────────────
$prefs      = get_portal_prefs();
$bg_enabled = $prefs['bg'];

// ─── CSRF token ───────────────────────────────────────────────────────────────
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

// ─── Unread notification count (for nav badge) ────────────────────────────────
$unread_notifications = get_unread_notification_count($profile['uuid']);

// ─── Public profile opt-in (portal_prefs table) ───────────────────────────────
// Only active when PUBLIC_PROFILES is enabled in config.php.
// Stores a per-user boolean in portal_prefs.public_profile.
// Does NOT affect visibility between logged-in users (friends etc.) —
// only controls whether a future public profile page can show this user.
$portal_public_profile  = false;
$public_profile_message = null;

if (defined('PUBLIC_PROFILES') && PUBLIC_PROFILES) {

    // Read current preference
    try {
        $db   = get_db();
        $stmt = $db->prepare('SELECT public_profile FROM portal_prefs WHERE uuid = ? LIMIT 1');
        $stmt->execute([$profile['uuid']]);
        $row = $stmt->fetch();
        if ($row) {
            $portal_public_profile = (bool)(int)$row['public_profile'];
        }
    } catch (Throwable $e) {
        error_log('portal_prefs read failed: ' . $e->getMessage());
    }

    // Handle toggle POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['action'])
        && $_POST['action'] === 'set_public_profile'
    ) {
        if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
            $public_profile_message = ['type' => 'error',
                                       'text' => 'Security token mismatch. Please refresh and try again.'];
        } else {
            $new_value = isset($_POST['public_profile']) ? 1 : 0;
            try {
                $db = get_db();
                $db->prepare('
                    INSERT INTO portal_prefs (uuid, public_profile)
                    VALUES (:uuid, :val)
                    ON DUPLICATE KEY UPDATE public_profile = :val2
                ')->execute([
                    ':uuid' => $profile['uuid'],
                    ':val'  => $new_value,
                    ':val2' => $new_value,
                ]);
                $portal_public_profile  = (bool)$new_value;
                $public_profile_message = ['type' => 'success',
                                           'text' => $new_value
                                               ? 'Your profile is now public.'
                                               : 'Your profile is now private.'];
            } catch (Throwable $e) {
                error_log('portal_prefs update failed: ' . $e->getMessage());
                $public_profile_message = ['type' => 'error',
                                           'text' => 'Could not save your preference. Please try again.'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — <?= GRID_NAME ?></title>
    <?php render_shared_css(); ?>
    <script>
const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;
const PARTNERSHIPS_ENABLED = <?= json_encode(defined('ENABLE_PARTNERSHIPS') && ENABLE_PARTNERSHIPS) ?>;

function confirmDissolve(partnerName) {
    const modal = document.getElementById('dissolveModal');
    document.getElementById('dissolvePartnerName').textContent = partnerName;
    document.getElementById('dissolveStatus').textContent = '';
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('dissolveCancelBtn').focus();
}

function closeDissolvModal() {
    const modal = document.getElementById('dissolveModal');
    modal.setAttribute('aria-hidden', 'true');
    modal.classList.remove('open');
    document.body.style.overflow = '';
}

function doDissolve() {
    const confirmBtn = document.getElementById('dissolveConfirmBtn');
    const statusEl   = document.getElementById('dissolveStatus');
    confirmBtn.disabled = true;
    statusEl.textContent = 'Dissolving partnership…';

    const fd = new FormData();
    fd.append('csrf',   PORTAL_CSRF);
    fd.append('action', 'dissolve');

    fetch('partner_action.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error(data.error || 'Could not dissolve partnership.');
            statusEl.textContent = 'Partnership dissolved.';
            setTimeout(() => window.location.reload(), 1200);
        })
        .catch(err => {
            confirmBtn.disabled  = false;
            statusEl.textContent = err.message;
            statusEl.style.color = 'var(--clr-accent-rose)';
        });
}
    </script>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('profile', [], (int)($session_user['userlevel'] ?? 0), $has_estate_access); ?>


<!-- ══════════════════════════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════════════════════════ -->
<main class="page-wrap" id="main-content">

    <!-- Profile panel: header strip + left col + right col -->
    <div class="profile-panel" role="region" aria-label="User profile">

        <!-- ── Full-width header inside the panel ───────────────────── -->
        <div class="profile-panel-header">
            <h1 class="panel-heading">My Profile</h1>
            <span class="panel-subhead">Your in-world identity, as seen by other residents</span>
        </div>

        <!-- ── Left column ───────────────────────────────────────────── -->
        <section class="profile-left" aria-label="Profile picture and about">

            <!-- Avatar image -->
            <div class="profile-avatar-wrap">
                <img
                    src="<?= htmlspecialchars($profile_img) ?>"
                    alt="Profile picture of <?= $full_name ?>"
                    loading="lazy"
                >
            </div>

            <!-- Identity: name + UUID -->
            <div class="profile-identity">
                <div class="profile-fullname"><?= $full_name ?></div>

                <dl class="profile-meta">
                    <div class="profile-meta-row">
                        <dt class="profile-meta-label">Name</dt>
                        <dd class="profile-meta-value name-value"><?= $full_name ?></dd>
                    </div>
                    <div class="profile-meta-row">
                        <dt class="profile-meta-label">UUID</dt>
                        <dd class="profile-meta-value" title="<?= $uuid ?>"><?= $uuid ?></dd>
                    </div>
                    <?php if ($partner_name !== null): ?>
                    <div class="profile-meta-row">
                        <dt class="profile-meta-label">Partner</dt>
                        <dd class="profile-meta-value partner-value">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"
                                 stroke="none" aria-hidden="true" style="color:var(--clr-accent-rose);vertical-align:-1px;">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                            </svg>
                            <?php if ($partner_link !== null): ?>
                                <a href="<?= htmlspecialchars($partner_link) ?>" class="partner-link"><?= htmlspecialchars($partner_name) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars($partner_name) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <?php if (defined('ENABLE_PARTNERSHIPS') && ENABLE_PARTNERSHIPS): ?>
                    <div class="profile-meta-row profile-dissolve-row">
                        <dt class="profile-meta-label"></dt>
                        <dd class="profile-meta-value">
                            <button class="btn-dissolve-partner"
                                    onclick="confirmDissolve(<?= htmlspecialchars(json_encode($partner_name), ENT_QUOTES) ?>)"
                                    aria-label="Dissolve partnership with <?= htmlspecialchars($partner_name) ?>">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                                Dissolve partnership
                            </button>
                        </dd>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="profile-divider" role="separator"></div>

            <!-- About text -->
            <div class="profile-about-section">
                <p class="section-label">About</p>
                <p class="profile-about-text">
                    <?php if (!empty($about)): ?>
                        <?= nl2br(linkify($about)) ?>
                    <?php else: ?>
                        <em>No about text set.</em>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Member since -->
            <div class="profile-member-since">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="18" rx="2"/>
                    <path d="M16 2v4M8 2v4M3 10h18"/>
                </svg>
                Member since <?= $member_since ?>
            </div>

        </section>


        <!-- ── Right column: picks ──────────────────────────────────── -->
        <section class="profile-right" aria-label="Profile picks">

            <?php
                $blank_count   = count(array_filter($picks, fn($p) => $p['is_blank']));
                $visible_count = count($picks) - $blank_count;
            ?>

            <div class="picks-header">
                <h2 class="picks-title">Picks</h2>
                <div class="picks-header-right">
                    <?php if ($blank_count > 0): ?>
                    <label class="picks-toggle"
                           title="Show placeholder picks with no name or description">
                        <input type="checkbox" id="showBlanks" onchange="toggleBlanks(this)">
                        Show blank picks (<?= $blank_count ?>)
                    </label>
                    <?php endif; ?>
                    <?php if (!empty($picks)): ?>
                    <span class="picks-count">
                        <span id="picks-visible-count"><?= $visible_count ?></span>
                        place<?= $visible_count !== 1 ? 's' : '' ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (empty($picks)): ?>
                <div class="picks-empty">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="1.5" opacity="0.4" aria-hidden="true">
                        <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <p>No picks yet.<br>Add favourite places from within the world.</p>
                </div>
            <?php else: ?>
                <ul class="picks-list" role="list" id="picksList">
                    <?php foreach ($picks as $pick): ?>
                    <li class="pick-card"
                        role="listitem"
                        data-blank="<?= $pick['is_blank'] ? '1' : '0' ?>"
                        data-name="<?= htmlspecialchars($pick['name'] ?: 'Unnamed', ENT_QUOTES) ?>"
                        data-img="<?= htmlspecialchars(get_pick_image_url($pick['image_uuid']), ENT_QUOTES) ?>"
                        data-sim="<?= htmlspecialchars($pick['sim_name'] ?? '', ENT_QUOTES) ?>"
                        data-x="<?= (int)$pick['pos_x'] ?>"
                        data-y="<?= (int)$pick['pos_y'] ?>"
                        data-z="<?= (int)$pick['pos_z'] ?>"
                        data-desc="<?= htmlspecialchars($pick['description'] ?? '', ENT_QUOTES) ?>"
                        data-desc-html="<?= htmlspecialchars(linkify(htmlspecialchars($pick['description'] ?? '')), ENT_QUOTES) ?>"
                        onclick="openPickModal(this)"
                        style="cursor:pointer"
                        tabindex="0"
                        onkeydown="if(event.key==='Enter'||event.key===' ')openPickModal(this)"
                    >
                        <div class="pick-image">
                            <img
                                src="<?= htmlspecialchars(get_pick_image_url($pick['image_uuid'])) ?>"
                                alt="<?= htmlspecialchars($pick['name'] ?: 'Blank pick') ?>"
                                loading="lazy"
                            >
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
                                (<?= (int)$pick['pos_x'] ?>,
                                 <?= (int)$pick['pos_y'] ?>,
                                 <?= (int)$pick['pos_z'] ?>)
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($pick['description'])): ?>
                            <p class="pick-desc"><?= linkify(htmlspecialchars($pick['description'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

        </section>

        <?php if (defined('PUBLIC_PROFILES') && PUBLIC_PROFILES): ?>
        <!-- ── Full-width privacy footer ────────────────────────────── -->
        <div class="profile-privacy-footer">

            <div class="profile-privacy-text">
                <span class="profile-privacy-title">Profile visibility</span>
                <span class="profile-privacy-desc">
                    <?php if ($portal_public_profile): ?>
                        Your profile is currently <strong>public</strong> — visitors can view your
                        display name, about text, and picks without signing in.
                        Your email address and account details are never shown.
                    <?php else: ?>
                        Your profile is currently <strong>private</strong> — only signed-in residents
                        can view it. Enable the toggle to allow public access.
                        Your email address and account details are never shown publicly.
                    <?php endif; ?>
                </span>

                <?php if ($portal_public_profile): ?>
                <?php $public_url = rtrim(PORTAL_BASE_URL, '/') . '/publicprofile.php?view=' . urlencode($profile['uuid']); ?>
                <div class="profile-public-link" style="margin-top:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="<?= htmlspecialchars($public_url) ?>"
                       target="_blank" rel="noopener noreferrer"
                       class="inline-link"
                       style="font-size:0.78rem;word-break:break-all;"
                       title="<?= htmlspecialchars($public_url) ?>">
                        <?= htmlspecialchars($public_url) ?>
                    </a>
                    <button type="button"
                            id="copyProfileLink"
                            onclick="copyPublicProfileLink()"
                            style="font-size:0.75rem;font-family:var(--font-body);font-weight:500;
                                   color:var(--clr-lilac-text);background:var(--clr-lilac-soft);
                                   border:1px solid var(--clr-lilac-mid);border-radius:var(--radius-pill);
                                   padding:4px 12px;cursor:pointer;white-space:nowrap;
                                   transition:background 0.15s,color 0.15s;flex-shrink:0;">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5" aria-hidden="true"
                             style="vertical-align:-1px;margin-right:4px;">
                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <span id="copyBtnLabel">Copy link</span>
                    </button>
                </div>
                <?php endif; ?>

                <?php if ($public_profile_message): ?>
                <div class="alert alert-<?= htmlspecialchars($public_profile_message['type']) ?>"
                     role="alert"
                     style="margin-top:10px;font-size:0.82rem;padding:8px 12px;display:flex;align-items:center;gap:8px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" aria-hidden="true" style="flex-shrink:0;">
                        <?php if ($public_profile_message['type'] === 'success'): ?>
                            <polyline points="20 6 9 17 4 12"/>
                        <?php else: ?>
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        <?php endif; ?>
                    </svg>
                    <?= htmlspecialchars($public_profile_message['text']) ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="profile-privacy-toggle">
                <form method="post" action="profile.php" id="publicProfileForm">
                    <input type="hidden" name="action"     value="set_public_profile">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <label class="toggle-switch-wrap" for="public_profile" title="Make my profile public">
                        <input type="checkbox"
                               id="public_profile"
                               name="public_profile"
                               class="toggle-checkbox"
                               <?= $portal_public_profile ? 'checked' : '' ?>
                               onchange="this.form.submit()">
                        <span class="toggle-switch" aria-hidden="true"></span>
                    </label>
                </form>
            </div>

        </div>
        <?php endif; ?>

    </div><!-- /.profile-panel -->

    <!-- Pick detail modal -->
    <div class="pick-modal-overlay" id="pickModalOverlay"
         aria-hidden="true" role="dialog" aria-modal="true"
         aria-labelledby="pickModalTitle">
        <div class="pick-modal">
            <div class="pick-modal-img-wrap">
                <img id="pickModalImg" src="" alt="">
            </div>
            <div class="pick-modal-body">
                <h2 class="pick-modal-name" id="pickModalTitle"></h2>
                <div class="pick-modal-location" id="pickModalLocation"></div>
                <p class="pick-modal-desc" id="pickModalDesc"></p>
            </div>
            <div class="pick-modal-footer">
                <button class="pick-modal-close" onclick="closePickModal()">Close</button>
            </div>
        </div>
    </div>

</main>


<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_shared_js(); ?>

<script>
/* ── Blank picks toggle ──────────────────────────────────────────── */
function toggleBlanks(checkbox) {
    const blanks  = document.querySelectorAll('.pick-card[data-blank="1"]');
    const counter = document.getElementById('picks-visible-count');

    blanks.forEach(card => card.classList.toggle('show-blank', checkbox.checked));

    if (counter) {
        const total   = document.querySelectorAll('.pick-card').length;
        const hidden  = checkbox.checked ? 0 : blanks.length;
        counter.textContent = total - hidden;
    }
}

/* ── Pick modal ──────────────────────────────────────────────────── */
function openPickModal(card) {
    const overlay  = document.getElementById('pickModalOverlay');
    const name     = card.dataset.name    || 'Unnamed';
    const img      = card.dataset.img     || '';
    const sim      = card.dataset.sim     || '';
    const x        = card.dataset.x;
    const y        = card.dataset.y;
    const z        = card.dataset.z;
    const descHtml = card.dataset.descHtml || '';

    document.getElementById('pickModalImg').src           = img;
    document.getElementById('pickModalImg').alt           = name;
    document.getElementById('pickModalTitle').textContent = name;
    document.getElementById('pickModalDesc').innerHTML    = descHtml;

    const locEl = document.getElementById('pickModalLocation');
    if (sim) {
        const coords = (x || y || z) ? ` (${x}, ${y}, ${z})` : '';
        locEl.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 1 1 18 0z"/></svg>${sim}${coords}`;
    } else {
        locEl.textContent = '';
    }

    overlay.classList.add('open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    overlay.querySelector('.pick-modal-close').focus();
}

function closePickModal() {
    const overlay = document.getElementById('pickModalOverlay');
    overlay.classList.remove('open');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

/* ── Copy public profile link ────────────────────────────────────── */
function copyPublicProfileLink() {
    const url   = <?= json_encode(isset($public_url) ? $public_url : '') ?>;
    const btn   = document.getElementById('copyProfileLink');
    const label = document.getElementById('copyBtnLabel');
    if (!url || !btn) return;

    navigator.clipboard.writeText(url).then(() => {
        label.textContent = 'Copied!';
        btn.style.background    = 'var(--clr-lilac-deep)';
        btn.style.color         = '#fff';
        btn.style.borderColor   = 'var(--clr-lilac-deep)';
        setTimeout(() => {
            label.textContent   = 'Copy link';
            btn.style.background  = '';
            btn.style.color       = '';
            btn.style.borderColor = '';
        }, 2000);
    }).catch(() => {
        // Fallback for browsers without clipboard API
        const ta = document.createElement('textarea');
        ta.value = url;
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        label.textContent = 'Copied!';
        setTimeout(() => { label.textContent = 'Copy link'; }, 2000);
    });
}

/* ── Close pick modal when clicking outside it ────────────────────── */
document.getElementById('pickModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePickModal();
});

/* ── Extend shared Escape handler to cover pick modal too ────────── */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('pickModalOverlay').classList.contains('open')) {
        closePickModal();
    }
});
</script>

<!-- ── Dissolve partnership confirmation modal ─────────────────────── -->
<div class="dissolve-modal-overlay" id="dissolveModal"
     role="dialog" aria-modal="true"
     aria-labelledby="dissolveModalTitle" aria-hidden="true">
    <div class="dissolve-modal-card">
        <h2 class="dissolve-modal-title" id="dissolveModalTitle">Dissolve Partnership?</h2>
        <p class="dissolve-modal-body">
            This will end your partnership with
            <strong id="dissolvePartnerName"></strong>
            and remove the partnership from both profiles.
            This cannot be undone.
        </p>
        <p class="dissolve-modal-status" id="dissolveStatus"></p>
        <div class="dissolve-modal-actions">
            <button class="btn-cancel" id="dissolveCancelBtn" onclick="closeDissolvModal()">
                Keep partnership
            </button>
            <button class="btn-primary-pill-destructive" id="dissolveConfirmBtn" onclick="doDissolve()">
                Yes, dissolve
            </button>
        </div>
    </div>
</div>
