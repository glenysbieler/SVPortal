<?php
/**
 * admin.php — Administration panel
 *
 * Three sections, selected via ?section=approvals (default), ?section=news,
 * or ?section=online, and reflected in the drawer as an "Administration"
 * submenu with "Account Approvals", "Grid News", and "Who's Online" entries.
 *
 * ── Account Approvals ────────────────────────────────────────────────────
 * Review pending registrations (from pending_registrations, populated by
 * register.php) and approve or reject them.
 *
 * Approve flow:
 *   1. Re-validate the pending row still exists and is 'pending'.
 *   2. Call robust_create_user() — ROBUST creates the UserAccounts + auth
 *      rows, hashing the plain-text password internally with a fresh salt.
 *   3. On success, mark the pending row 'approved', clear its password
 *      column, and write a portal_log entry.
 *   4. On failure, leave the row 'pending' and show the error — nothing is
 *      partially applied since ROBUST either creates the account or it
 *      doesn't.
 *
 * Reject flow:
 *   Marks the row 'rejected' and clears its password column. The row is
 *   kept for audit purposes (see pending_registrations.status).
 *
 * ── Grid News ────────────────────────────────────────────────────────────
 * Post, hide/unhide, and (Administrator-only) delete entries in the
 * portal-owned `portal_news` table (see portal_news.sql / includes/news_data.php).
 * Visible (non-hidden) posts are shown on login.php and splash.php.
 *
 * Posting and hiding/unhiding require meeting the 'Grid Staff' tier (see
 * USERLEVEL_LABELS / user_level_meets() in config.php)
 * — same as Account Approvals.
 *
 * Permanent deletion requires meeting the 'Administrator' tier. This is
 * deliberate: a staff member who posts something inappropriate can only
 * hide it, not erase the record that they posted it. Only an Administrator
 * can delete it outright.
 *
 * ── Who's Online ──────────────────────────────────────────────────────────
 * Grid-wide presence view backed by get_all_online_users() (see
 * includes/profile_data.php) — every active session in the Presence table,
 * joined to the avatar's name and current region. Sortable by name or
 * region, and groupable by region, entirely client-side (no extra requests
 * — the full list is small enough to ship in one page load). Read-only;
 * no POST actions in this section. Same hypergrid/ghost-session caveat as
 * the per-user presence badge applies, shown as a standing disclaimer.
 *
 * Hypergrid visitors DO appear here (get_all_online_users() uses a LEFT
 * JOIN to UserAccounts specifically so they aren't silently dropped). Their
 * name is resolved from GridUser (which stores a composite
 * "<uuid>;http://homegrid:port/;First Last" string for foreign visitors on
 * this OpenSim build — see get_all_online_users() for the confirmed live
 * format). In practice this lookup should always succeed: any UserID with
 * a Presence row but no UserAccounts row got there via a hypergrid login,
 * which is exactly what populates GridUser — there's no path that produces
 * one without the other. The "Unknown Hypergrid User" fallback exists only
 * as a safeguard for that logical guarantee breaking somehow (e.g. a
 * different OpenSim version/config that doesn't populate GridUser the same
 * way); seeing it in practice is worth investigating, not a default to
 * expect. Either way, hypergrid visitors are always sorted to the end of
 * the list (or of each region group), regardless of the active sort mode —
 * this is deliberate, not just a workaround for missing names: keeping
 * "visitor from elsewhere" visually separate from "resident of this grid"
 * is the point, and it also avoids an unresolved visitor's placeholder
 * label being sorted as if it were a real (and possibly duplicate-looking)
 * name.
 *
 * Access: requires meeting the 'Grid Staff' tier for the whole page.
 * Section-specific actions enforce their own tier checks again at the
 * point of action (never trust the section query string alone).
 *
 * Data sources: pending_registrations, portal_log, portal_news (all
 * portal-owned tables); Presence, UserAccounts, regions (read-only, via
 * get_all_online_users()). No writes to any OpenSim-owned table occur
 * directly; account creation goes through robust_create_user().
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/robust_api.php';
require_once __DIR__ . '/includes/news_data.php';
require_once __DIR__ . '/includes/estates.php';

session_start_secure();
require_login();

$session_user = get_session_user();
$userlevel    = (int)($session_user['userlevel'] ?? 0);

// ─── Access control ─────────────────────────────────────────────────────────
if (!user_level_meets($userlevel, 'Grid Staff')) {
    http_response_code(403);
    $profile = get_user_profile($session_user['uuid']);
    $full_name = htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']);
    [
        'status_class'   => $status_class,
        'status_label'   => $status_label,
        'status_tooltip' => $status_tooltip,
    ] = build_presence_display($session_user);
    $prefs      = get_portal_prefs();
    $bg_enabled = $prefs['bg'];
    $unread_notifications = get_unread_notification_count($session_user['uuid']);
    $has_estate_access = user_has_estate_access(get_db(), $session_user['uuid']);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>
<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('admin_approvals', [], $userlevel, $has_estate_access); ?>
<main class="page-wrap" id="main-content">
    <div class="account-panel" role="region" aria-label="Administration">
        <div class="account-panel-header">
            <h1 class="panel-heading">Administration</h1>
            <span class="panel-subhead">Access restricted</span>
        </div>
        <div class="account-section">
            <p>You don't have permission to view this page.</p>
        </div>
    </div>
</main>
<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_shared_js(); ?>
</body>
</html>
    <?php
    exit;
}

// ─── Determine active section ──────────────────────────────────────────────
$section = in_array($_GET['section'] ?? '', ['news', 'online'], true)
    ? $_GET['section']
    : 'approvals';
$active_page = match ($section) {
    'news'   => 'admin_news',
    'online' => 'admin_online',
    default  => 'admin_approvals',
};

// ─── Data ─────────────────────────────────────────────────────────────────────
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

$action_message = null;

// ─── POST handler: approvals ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
    && in_array($_POST['action'], ['approve_registration', 'reject_registration'], true)
) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $action_message = ['type' => 'error', 'text' => 'Security token mismatch. Please refresh and try again.'];
    } else {
        $id = (int)($_POST['id'] ?? 0);

        try {
            $db = get_db();

            $stmt = $db->prepare(
                "SELECT * FROM pending_registrations WHERE id = :id AND status = 'pending' LIMIT 1"
            );
            $stmt->execute([':id' => $id]);
            $reg = $stmt->fetch();

            if (!$reg) {
                $action_message = ['type' => 'error', 'text' => 'That registration is no longer pending (it may have already been processed).'];

            } elseif ($_POST['action'] === 'approve_registration') {

                $api_result = robust_create_user(
                    firstname:      $reg['firstname'],
                    lastname:       $reg['lastname'],
                    password:       $reg['password'],
                    email:          $reg['email'],
                    starter_avatar: $reg['starter_avatar'] ?? ''
                );

                if ($api_result['success']) {
                    $db->prepare(
                        "UPDATE pending_registrations
                            SET status = 'approved', password = '', decided_at = NOW(), decided_by = :who
                          WHERE id = :id"
                    )->execute([':who' => $session_user['uuid'], ':id' => $id]);

                    $db->prepare(
                        'INSERT INTO portal_log (actor_uuid, action, details) VALUES (:who, :action, :details)'
                    )->execute([
                        ':who'     => $session_user['uuid'],
                        ':action'  => 'registration_approved',
                        ':details' => $reg['firstname'] . ' ' . $reg['lastname'] . ' <' . $reg['email'] . '>',
                    ]);

                    $action_message = [
                        'type' => 'success',
                        'text' => 'Account created for ' . $reg['firstname'] . ' ' . $reg['lastname'] . '.',
                    ];
                } else {
                    $action_message = [
                        'type' => 'error',
                        'text' => $api_result['error'] ?? 'The grid service could not create the account. Please try again.',
                    ];
                }

            } else {
                // reject_registration
                $db->prepare(
                    "UPDATE pending_registrations
                        SET status = 'rejected', password = '', decided_at = NOW(), decided_by = :who
                      WHERE id = :id"
                )->execute([':who' => $session_user['uuid'], ':id' => $id]);

                $db->prepare(
                    'INSERT INTO portal_log (actor_uuid, action, details) VALUES (:who, :action, :details)'
                )->execute([
                    ':who'     => $session_user['uuid'],
                    ':action'  => 'registration_rejected',
                    ':details' => $reg['firstname'] . ' ' . $reg['lastname'] . ' <' . $reg['email'] . '>',
                ]);

                $action_message = [
                    'type' => 'success',
                    'text' => 'Registration for ' . $reg['firstname'] . ' ' . $reg['lastname'] . ' was rejected.',
                ];
            }
        } catch (Throwable $e) {
            error_log('admin.php: ' . $e->getMessage());
            $action_message = ['type' => 'error', 'text' => 'A database error occurred. Please try again.'];
        }
    }
}

// ─── POST handler: news ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
    && in_array($_POST['action'], ['post_news', 'hide_news', 'unhide_news', 'delete_news'], true)
) {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf_token, $_POST['csrf_token'])) {
        $action_message = ['type' => 'error', 'text' => 'Security token mismatch. Please refresh and try again.'];
    } elseif ($_POST['action'] === 'post_news') {

        $body = trim((string)($_POST['body'] ?? ''));
        if ($body === '') {
            $action_message = ['type' => 'error', 'text' => 'News post cannot be empty.'];
        } elseif (create_news_post($session_user['uuid'], $body)) {
            $db = get_db();
            $db->prepare(
                'INSERT INTO portal_log (actor_uuid, action, details) VALUES (:who, :action, :details)'
            )->execute([
                ':who'     => $session_user['uuid'],
                ':action'  => 'news_posted',
                ':details' => mb_substr($body, 0, 200),
            ]);
            $action_message = ['type' => 'success', 'text' => 'News post published.'];
        } else {
            $action_message = ['type' => 'error', 'text' => 'Could not save the news post. Please try again.'];
        }

    } else {
        // hide_news / unhide_news / delete_news — all operate on an existing id
        $id   = (int)($_POST['id'] ?? 0);
        $post = get_news_post($id);

        if (!$post) {
            $action_message = ['type' => 'error', 'text' => 'That news post no longer exists.'];

        } elseif ($_POST['action'] === 'hide_news') {
            if (set_news_post_hidden($id, true)) {
                $db = get_db();
                $db->prepare(
                    'INSERT INTO portal_log (actor_uuid, action, details) VALUES (:who, :action, :details)'
                )->execute([':who' => $session_user['uuid'], ':action' => 'news_hidden', ':details' => 'post #' . $id]);
                $action_message = ['type' => 'success', 'text' => 'News post hidden.'];
            } else {
                $action_message = ['type' => 'error', 'text' => 'Could not hide the news post.'];
            }

        } elseif ($_POST['action'] === 'unhide_news') {
            if (set_news_post_hidden($id, false)) {
                $db = get_db();
                $db->prepare(
                    'INSERT INTO portal_log (actor_uuid, action, details) VALUES (:who, :action, :details)'
                )->execute([':who' => $session_user['uuid'], ':action' => 'news_unhidden', ':details' => 'post #' . $id]);
                $action_message = ['type' => 'success', 'text' => 'News post restored.'];
            } else {
                $action_message = ['type' => 'error', 'text' => 'Could not restore the news post.'];
            }

        } elseif ($_POST['action'] === 'delete_news') {
            // Permanent deletion is Administrator-only (meeting the
            // 'Administrator' tier — see USERLEVEL_LABELS).
            // Grid staff below this tier can only hide posts — see class comment.
            if (!user_level_meets($userlevel, 'Administrator')) {
                http_response_code(403);
                $action_message = ['type' => 'error', 'text' => 'Only an Administrator can permanently delete a news post.'];
            } elseif (delete_news_post($id)) {
                $db = get_db();
                $db->prepare(
                    'INSERT INTO portal_log (actor_uuid, action, details) VALUES (:who, :action, :details)'
                )->execute([':who' => $session_user['uuid'], ':action' => 'news_deleted', ':details' => 'post #' . $id . ': ' . mb_substr($post['body'], 0, 200)]);
                $action_message = ['type' => 'success', 'text' => 'News post permanently deleted.'];
            } else {
                $action_message = ['type' => 'error', 'text' => 'Could not delete the news post.'];
            }
        }
    }
}

// ─── Fetch pending registrations (only needed for the approvals section) ───
$pending = [];
if ($section === 'approvals') {
    try {
        $stmt = get_db()->query(
            "SELECT id, firstname, lastname, email, starter_avatar, submitted_at
               FROM pending_registrations
              WHERE status = 'pending'
              ORDER BY submitted_at ASC"
        );
        $pending = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('admin.php: failed to load pending registrations: ' . $e->getMessage());
    }
}

// ─── Fetch news posts (only needed for the news section) ───────────────────
$show_hidden = isset($_GET['show_hidden']) && $_GET['show_hidden'] === '1';
$news_posts  = [];
$news_action_url = 'admin.php?section=news' . ($show_hidden ? '&show_hidden=1' : '');
if ($section === 'news') {
    $news_posts = get_all_news_posts();
    if (!$show_hidden) {
        $news_posts = array_values(array_filter($news_posts, fn(array $p): bool => !$p['hidden']));
    }
}

// ─── Fetch online users (only needed for the online section) ───────────────
$online_users = [];
if ($section === 'online') {
    $online_users = get_all_online_users();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
    <script>const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;</script>
    <style>
        .admin-news-post {
            display: grid;
            grid-template-columns: 15% 1fr 15%;
            gap: 0;
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-md);
            overflow: hidden;
            align-items: stretch;
            margin-bottom: 6px;
        }
        .admin-news-post:last-child { margin-bottom: 0; }
        .admin-news-meta,
        .admin-news-actions {
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding: 8px 12px;
            background: linear-gradient(175deg, var(--clr-surface) 0%, var(--clr-surface-2) 100%);
        }
        .admin-news-meta {
            border-right: 1px solid var(--clr-border-light);
            font-size: 0.78rem;
            color: var(--clr-text-muted);
        }
        .admin-news-actions {
            border-left: 1px solid var(--clr-border-light);
            align-items: stretch;
        }
        .admin-news-body {
            font-size: 0.92rem;
            color: var(--clr-text-primary);
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            line-height: 1.5;
            padding: 8px 16px;
            background: var(--clr-surface);
        }
        /* Hidden posts: dim the whole row (including the body column) to the
           side columns' darker gradient stop, so the row reads as a single
           muted block — without losing contrast for the "Hidden" badge or
           the Unhide/Delete buttons. */
        .admin-news-post.is-hidden .admin-news-body {
            background: var(--clr-surface-2);
            opacity: 0.7;
        }
        .admin-news-post.is-hidden .admin-news-meta,
        .admin-news-post.is-hidden .admin-news-actions {
            background: var(--clr-surface-2);
        }
        @media (max-width: 560px) {
            .admin-news-post {
                grid-template-columns: 1fr;
            }
            .admin-news-meta {
                border-right: none;
                border-bottom: 1px solid var(--clr-border-light);
            }
            .admin-news-actions {
                border-left: none;
                border-top: 1px solid var(--clr-border-light);
                flex-direction: row;
            }
        }
        .badge-hidden {
            display: block;
            width: 100%;
            box-sizing: border-box;
            font-size: 0.72rem;
            font-weight: 500;
            color: var(--clr-text-muted);
            background: var(--clr-surface);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-pill);
            padding: 1px 8px;
            text-align: center;
        }
        .btn-secondary-sm,
        .btn-danger-sm {
            font-size: 0.82rem;
            padding: 6px 14px;
            border-radius: var(--radius-md);
            border: 1px solid var(--clr-border-light);
            background: var(--clr-surface);
            color: var(--clr-text-primary);
            cursor: pointer;
            width: 100%;
        }
        .btn-danger-sm {
            border-color: var(--clr-accent-rose);
            color: var(--clr-accent-rose);
            background: transparent;
        }
        .admin-news-actions form {
            width: 100%;
        }
        .news-post-form textarea {
            width: 100%;
            min-height: 100px;
            padding: 10px 12px;
            border-radius: var(--radius-md);
            border: 1px solid var(--clr-border-light);
            background: var(--clr-surface);
            color: var(--clr-text-primary);
            font-family: var(--font-body);
            font-size: 0.92rem;
            resize: vertical;
            box-sizing: border-box;
        }
        .news-post-form {
            margin-bottom: 18px;
        }
        .news-post-form .btn-primary {
            margin-top: 8px;
        }
        .online-region-header {
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            color: var(--clr-text-primary);
            padding: 16px 4px 8px;
            border-bottom: 1px solid var(--clr-border-light);
            margin-bottom: 4px;
        }
        .online-region-header:first-child {
            padding-top: 0;
        }
        .online-user-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 5px 4px;
            flex-wrap: wrap;
        }
        #onlineUsersList.grouped .online-user-row {
            padding-left: 18px;
        }
        .online-user-name {
            font-weight: 500;
            color: var(--clr-text-primary);
            flex: 1 1 160px;
        }
        /* When grouped by region, the region header (above) carries the
           visual weight that the now-hidden per-row region column would
           otherwise share with the name — so the name is sized down a
           touch to read clearly as a row under a heading, not a peer of it. */
        #onlineUsersList.grouped .online-user-name {
            font-size: 0.9rem;
        }
        .online-user-region {
            font-size: 0.85rem;
            color: var(--clr-text-secondary);
            flex: 1 1 160px;
        }
        .online-user-row.is-hypergrid .online-user-name,
        .online-user-row.is-hypergrid .online-user-region {
            color: var(--clr-text-muted);
        }
        .online-user-hg-label {
            font-style: italic;
            border-bottom: 1px dashed var(--clr-text-muted);
            cursor: help;
        }
        .online-user-hg-badge {
            font-size: 0.72rem;
            font-weight: 500;
            color: var(--clr-text-muted);
            background: var(--clr-surface-2);
            border: 1px solid var(--clr-border-light);
            border-radius: var(--radius-pill);
            padding: 1px 8px;
            cursor: help;
        }
    </style>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer($active_page, [], $userlevel, $has_estate_access); ?>

<main class="page-wrap" id="main-content">

    <div class="account-panel" role="region" aria-label="Administration">

        <div class="account-panel-header">
            <h1 class="panel-heading">Administration</h1>
            <span class="panel-subhead">Grid management tools</span>
        </div>

        <?php if ($action_message): ?>
        <div class="alert alert-<?= htmlspecialchars($action_message['type']) ?>" role="alert">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" aria-hidden="true">
                <?php if ($action_message['type'] === 'success'): ?>
                    <polyline points="20 6 9 17 4 12"/>
                <?php else: ?>
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                <?php endif; ?>
            </svg>
            <?= htmlspecialchars($action_message['text']) ?>
        </div>
        <?php endif; ?>

        <?php if ($section === 'approvals'): ?>

        <!-- ── Account Approvals ───────────────────────────────────────── -->
        <div class="account-section">
            <p class="section-title">Account Approvals</p>

            <?php if (empty($pending)): ?>
            <p style="font-size:0.9rem;color:var(--clr-text-secondary);">
                No registrations are currently awaiting approval.
            </p>
            <?php else: ?>

            <div class="approvals-list">
                <?php foreach ($pending as $reg): ?>
                <?php
                    $raw_reg_name = $reg['firstname'] . ' ' . $reg['lastname'];
                    $reg_name  = htmlspecialchars($raw_reg_name);
                    $reg_email = htmlspecialchars($reg['email']);
                    $submitted = htmlspecialchars(date('j M Y, H:i', strtotime($reg['submitted_at'])));
                    $avatar    = trim((string)($reg['starter_avatar'] ?? ''));
                ?>
                <div class="approval-row" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:14px 0;border-bottom:1px solid var(--clr-border-light);flex-wrap:wrap;">
                    <div>
                        <div style="font-weight:500;color:var(--clr-text-primary);"><?= $reg_name ?></div>
                        <div style="font-size:0.82rem;color:var(--clr-text-secondary);"><?= $reg_email ?></div>
                        <?php if ($avatar !== ''): ?>
                        <div style="font-size:0.78rem;color:var(--clr-text-muted);">Starter avatar: <?= htmlspecialchars($avatar) ?></div>
                        <?php endif; ?>
                        <div style="font-size:0.78rem;color:var(--clr-text-muted);">Submitted <?= $submitted ?></div>
                    </div>
                    <div style="display:flex;gap:8px;flex-shrink:0;">
                        <form method="post" action="admin.php?section=approvals" id="approveForm<?= (int)$reg['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="approve_registration">
                            <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                            <button type="button" class="btn-primary"
                                    onclick="openConfirmModal('Approve registration?', <?= htmlspecialchars(json_encode('Create an account for ' . $raw_reg_name . '?'), ENT_QUOTES) ?>, 'approveForm<?= (int)$reg['id'] ?>', 'Approve', 'btn-primary-pill')">Approve</button>
                        </form>
                        <form method="post" action="admin.php?section=approvals" id="rejectForm<?= (int)$reg['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="reject_registration">
                            <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                            <button type="button" class="btn-primary"
                                    onclick="openConfirmModal('Reject registration?', <?= htmlspecialchars(json_encode('Reject the registration for ' . $raw_reg_name . '?'), ENT_QUOTES) ?>, 'rejectForm<?= (int)$reg['id'] ?>', 'Reject', 'btn-primary-pill-destructive')">Reject</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

        <?php elseif ($section === 'online'): ?>

        <!-- ── Who's Online ────────────────────────────────────────────── -->
        <div class="account-section">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:8px;">
                <p class="section-title" style="margin-bottom:0;">
                    Who's Online
                    <span id="onlineCount" style="font-weight:400;color:var(--clr-text-secondary);font-size:0.85rem;"></span>
                </p>
                <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.82rem;color:var(--clr-text-secondary);">
                        Sort
                        <select id="onlineSortBy" onchange="renderOnlineUsers()" style="padding:4px 8px;border-radius:var(--radius-sm);border:1px solid var(--clr-border-light);background:var(--clr-surface);color:var(--clr-text-primary);font-family:var(--font-body);font-size:0.82rem;">
                            <option value="name">Name</option>
                            <option value="region">Region</option>
                        </select>
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.82rem;color:var(--clr-text-secondary);">
                        <input type="checkbox" id="onlineGroupByRegion" onchange="renderOnlineUsers()">
                        Group by region
                    </label>
                </div>
            </div>

            <p style="font-size:0.8rem;color:var(--clr-text-muted);margin-top:-4px;margin-bottom:16px;">
                Presence data may be inaccurate — hypergrid visitors can appear online after
                logging out if the logout signal did not reach this grid. Sessions with no
                activity for over 30 minutes are shown as "Away?" rather than assumed offline.
                Hypergrid visitor names are resolved from their home grid automatically; the
                rare unresolved case is labelled "Unknown Hypergrid User".
            </p>

            <?php if (empty($online_users)): ?>
            <p style="font-size:0.9rem;color:var(--clr-text-secondary);">
                Nobody is currently online.
            </p>
            <?php else: ?>

            <div id="onlineUsersList"></div>
            <script>const ONLINE_USERS = <?= json_encode(array_map(fn(array $u): array => [
                'uuid'         => $u['uuid'],
                'name'         => trim((string)$u['firstname'] . ' ' . (string)$u['lastname']) ?: null,
                'is_hypergrid' => $u['is_hypergrid'],
                'home_grid'    => $u['home_grid'],
                'region'       => $u['region_name'] ?? '(unknown region)',
                'status'       => $u['status'],
                'last_seen'    => $u['last_seen'],
            ], $online_users)) ?>;</script>

            <?php endif; ?>
        </div>

        <?php else: ?>

        <!-- ── Grid News ───────────────────────────────────────────────── -->
        <div class="account-section">
            <p class="section-title">Post a news update</p>

            <form method="post" action="<?= htmlspecialchars($news_action_url) ?>" class="news-post-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="action" value="post_news">
                <textarea name="body" placeholder="Write a news or announcement post for residents… (plain text, no HTML)" required></textarea>
                <button type="submit" class="btn-primary">Post</button>
            </form>
        </div>

        <div class="account-section">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:8px;">
                <p class="section-title" style="margin-bottom:0;">Existing posts</p>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:0.8rem;color:var(--clr-text-secondary);">Show hidden</span>
                    <label class="toggle-wrap" aria-label="Show hidden posts">
                        <input type="checkbox" id="showHiddenToggle"<?= $show_hidden ? ' checked' : '' ?> onchange="setShowHidden(this.checked)">
                        <span class="toggle-track"></span>
                    </label>
                </div>
            </div>
            <p style="font-size:0.8rem;color:var(--clr-text-muted);margin-top:-4px;margin-bottom:16px;">
                Note: only the 20 most recent non-hidden posts are shown on the Login and Splash pages.
                <?= $show_hidden ? 'Hidden posts are included below.' : 'Hidden posts are not shown below — toggle "Show hidden" to include them.' ?>
            </p>

            <?php if (empty($news_posts)): ?>
            <p style="font-size:0.9rem;color:var(--clr-text-secondary);">
                <?= $show_hidden ? 'No news posts yet.' : 'No visible news posts. Toggle "Show hidden" to check for hidden posts.' ?>
            </p>
            <?php else: ?>

            <div class="admin-news-list">
                <?php foreach ($news_posts as $post): ?>
                <?php
                    $author = htmlspecialchars($post['author_name']);
                    $when   = htmlspecialchars(date('j M Y, H:i', strtotime($post['posted_at'])));
                    $body   = linkify(htmlspecialchars($post['body']));
                ?>
                <div class="admin-news-post<?= $post['hidden'] ? ' is-hidden' : '' ?>">
                    <div class="admin-news-meta">
                        <span><?= $author ?></span>
                        <span><?= $when ?></span>
                        <?php if ($post['hidden']): ?>
                        <span class="badge-hidden">Hidden</span>
                        <?php endif; ?>
                    </div>
                    <div class="admin-news-body"><?= $body ?></div>
                    <div class="admin-news-actions">
                        <?php if ($post['hidden']): ?>
                        <form method="post" action="<?= htmlspecialchars($news_action_url) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="unhide_news">
                            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                            <button type="submit" class="btn-secondary-sm">Unhide</button>
                        </form>
                        <?php else: ?>
                        <form method="post" action="<?= htmlspecialchars($news_action_url) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="hide_news">
                            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                            <button type="submit" class="btn-secondary-sm">Hide</button>
                        </form>
                        <?php endif; ?>

                        <?php if (user_level_meets($userlevel, 'Administrator')): ?>
                        <form method="post" action="<?= htmlspecialchars($news_action_url) ?>" id="deleteNewsForm<?= (int)$post['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="delete_news">
                            <input type="hidden" name="id" value="<?= (int)$post['id'] ?>">
                            <button type="button" class="btn-danger-sm"
                                    onclick="openConfirmModal('Delete this post?', 'Permanently delete this news post? This cannot be undone.', 'deleteNewsForm<?= (int)$post['id'] ?>', 'Delete', 'btn-primary-pill-destructive')">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

        <?php endif; ?>

    </div><!-- /.account-panel -->

</main>

<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_confirm_modal(); ?>
<?php render_shared_js(); ?>
<?php if ($section === 'news'): ?>
<script>
function setShowHidden(checked) {
    const url = new URL(window.location.href);
    if (checked) {
        url.searchParams.set('show_hidden', '1');
    } else {
        url.searchParams.delete('show_hidden');
    }
    window.location.href = url.toString();
}
</script>
<?php endif; ?>

<?php if ($section === 'online' && !empty($online_users)): ?>
<script>
// Same "time ago" wording as the per-user presence tooltip (see
// build_presence_display() in includes/helpers.php) — kept separate from
// formatNotifTime() above since that one uses a more compact "5m ago" style
// suited to a small notification panel rather than a full admin table.
function formatOnlineAge(lastSeenTs) {
    const age = Math.floor(Date.now() / 1000) - lastSeenTs;
    if (age < 60) return 'just now';
    if (age < 3600) {
        const m = Math.round(age / 60);
        return m + ' minute' + (m === 1 ? '' : 's') + ' ago';
    }
    if (age < 86400) {
        const h = Math.round(age / 3600);
        return h + ' hour' + (h === 1 ? '' : 's') + ' ago';
    }
    const d = Math.round(age / 86400);
    return d + ' day' + (d === 1 ? '' : 's') + ' ago';
}

function renderOnlineUsers() {
    const sortBy      = document.getElementById('onlineSortBy').value;
    const groupByRegion = document.getElementById('onlineGroupByRegion').checked;
    const container    = document.getElementById('onlineUsersList');
    const countEl       = document.getElementById('onlineCount');

    container.classList.toggle('grouped', groupByRegion);

    countEl.textContent = '(' + ONLINE_USERS.length + (ONLINE_USERS.length === 1 ? ' person' : ' people') + ')';

    // Hypergrid visitors are always kept in their own block at the end of
    // the list (or of each region group), regardless of sort/group mode —
    // even when a name was resolved from their home grid (see
    // get_all_online_users() in includes/profile_data.php). This is a
    // deliberate choice, not a fallback for missing data: visually
    // separating "visitor from elsewhere" from "resident of this grid"
    // matters on its own, and the rare unresolved visitor still needs to
    // land somewhere sensible rather than alphabetically as a literal
    // "Unknown Hypergrid User" string (which would merge multiple
    // unresolved visitors into what looks like one duplicate entry).
    const named = ONLINE_USERS.filter(u => !u.is_hypergrid);
    const hg     = ONLINE_USERS.filter(u => u.is_hypergrid);

    if (groupByRegion) {
        named.sort((a, b) => {
            const r = a.region.localeCompare(b.region);
            return r !== 0 ? r : a.name.localeCompare(b.name);
        });
        hg.sort((a, b) => {
            const r = a.region.localeCompare(b.region);
            if (r !== 0) return r;
            return (a.name || '').localeCompare(b.name || '');
        });
    } else if (sortBy === 'region') {
        named.sort((a, b) => a.region.localeCompare(b.region));
        hg.sort((a, b) => a.region.localeCompare(b.region));
    } else {
        named.sort((a, b) => a.name.localeCompare(b.name));
        // Sort resolved names alphabetically among themselves; unresolved
        // (name === null) visitors fall back to region so ordering stays
        // stable rather than clumping all nulls arbitrarily.
        hg.sort((a, b) => (a.name || '').localeCompare(b.name || '') || a.region.localeCompare(b.region));
    }

    const users = named.concat(hg);

    function userRowHtml(u) {
        const statusLabel = u.status === 'online' ? 'Online' : 'Away?';
        const statusClass = u.status === 'online' ? 'status-online' : 'status-away';
        const tooltip = (u.status === 'online' ? 'Online' : 'Possibly online')
            + ' · last activity ' + formatOnlineAge(u.last_seen)
            + '. Presence data may be inaccurate — hypergrid visitors can appear '
            + 'online after logging out if the logout signal did not reach this grid.';
        const displayName = u.is_hypergrid
            ? (u.name
                ? escapeHtmlOnline(u.name) + (u.home_grid
                    ? ' <span class="online-user-hg-badge" title="Visiting from ' + escapeHtmlOnline(u.home_grid) + '">@' + escapeHtmlOnline(u.home_grid) + '</span>'
                    : ' <span class="online-user-hg-badge" title="UUID: ' + escapeHtmlOnline(u.uuid) + '">hypergrid</span>')
                // Should be unreachable in practice — every UserID with a Presence
                // row but no UserAccounts row got there via a hypergrid login,
                // which is exactly what populates GridUser. If this label ever
                // shows, GridUser genuinely has no row for this UUID — worth a
                // closer look (e.g. an OpenSim version/config that doesn't
                // populate GridUser for HG visitors), not a default to expect.
                : '<span class="online-user-hg-label" title="UUID: ' + escapeHtmlOnline(u.uuid) + ' — no GridUser row found for this UUID">Unknown Hypergrid User</span>')
            : escapeHtmlOnline(u.name);
        return '<div class="online-user-row' + (u.is_hypergrid ? ' is-hypergrid' : '') + '">'
            + '<div class="online-user-name">' + displayName + '</div>'
            + (groupByRegion ? '' : '<div class="online-user-region">' + escapeHtmlOnline(u.region) + '</div>')
            + '<div class="status-badge-wrap" data-tooltip="' + escapeHtmlOnline(tooltip) + '" tabindex="0">'
            + '<span class="status-badge ' + statusClass + '"><span class="status-dot"></span>' + statusLabel + '</span>'
            + '</div>'
            + '</div>';
    }

    if (!groupByRegion) {
        let html = users.map(userRowHtml).join('');
        container.innerHTML = html;
        return;
    }

    // Grouped view: one header PER REGION (never repeated), in the order
    // named users' regions first appear (already alphabetical from the
    // sort above). Each region's hypergrid visitors are appended inside
    // that same region's block, after its named residents — never as a
    // separate trailing header for a region that already appeared earlier.
    // A region with HG visitors but no named residents at all still gets
    // its own single header, appended after every region that does have
    // named residents.
    const regionOrder = [];      // preserves first-seen order of named users' regions
    const regionGroups = {};     // region name -> { named: [...], hg: [...] }

    for (const u of named) {
        if (!regionGroups[u.region]) {
            regionGroups[u.region] = { named: [], hg: [] };
            regionOrder.push(u.region);
        }
        regionGroups[u.region].named.push(u);
    }
    for (const u of hg) {
        if (!regionGroups[u.region]) {
            regionGroups[u.region] = { named: [], hg: [] };
            regionOrder.push(u.region); // HG-only region — appended at the end naturally
        }
        regionGroups[u.region].hg.push(u);
    }

    let html = '';
    for (const region of regionOrder) {
        html += '<div class="online-region-header">' + escapeHtmlOnline(region) + '</div>';
        const group = regionGroups[region];
        html += group.named.map(userRowHtml).join('');
        html += group.hg.map(userRowHtml).join('');
    }
    container.innerHTML = html;
}

function escapeHtmlOnline(s) {
    const div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
}

renderOnlineUsers();
</script>
<?php endif; ?>

</body>
</html>
