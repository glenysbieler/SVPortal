<?php
/**
 * account.php — Account Details page
 *
 * Displays the logged-in user's account information:
 *   - First name, last name, UUID (read-only)
 *   - Email address (with change form)
 *   - Member since date
 *   - UserLevel badge — ONLY shown for users above the lowest-defined tier
 *     (see user_is_lowest_tier() in config.php)
 *   - Grid God badge  — shown for users with UserLevel >= GRID_GOD_LEVEL
 *     (config.php; OpenSim's in-world god threshold, independent of the
 *     portal's named tiers). If the account also has a non-empty UserTitle,
 *     it is displayed alongside the badge as the person's grid title.
 *
 * UserLevel visibility policy:
 *   Users resolving to the lowest-defined tier (see USERLEVEL_LABELS in
 *   config.php — typically 'Resident' at UserLevel 0) see no level
 *   indicator at all. Everyone above that sees a labelled badge from
 *   get_user_tier_label().
 *
 * Email change:
 *   Two modes depending on EMAIL_ENABLED in config.php:
 *
 *   EMAIL_ENABLED = true  — confirm-then-set flow. A token is stored in
 *     portal_email_tokens and a verification link is emailed to the new
 *     address. The change is applied by email_verify.php when the link
 *     is clicked. A notification is then sent to the old address.
 *
 *   EMAIL_ENABLED = false — silent immediate change via ROBUST XMLRPC.
 *     No confirmation is sent. Less secure but the only option when the
 *     grid cannot send email (e.g. domestic broadband with no SMTP relay).
 *
 * Public profile opt-in is on profile.php, not here.
 *
 * Data source: UserAccounts table (read-only SELECT — no direct DB writes).
 * All mutations must go through the ROBUST XMLRPC API.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/robust_api.php';
require_once __DIR__ . '/includes/estates.php';

if (EMAIL_ENABLED) {
    require_once __DIR__ . '/includes/mailer.php';
}

session_start_secure();
require_login();

// ─── Data ─────────────────────────────────────────────────────────────────────
$session_user = get_session_user();
$profile      = get_user_profile($session_user['uuid']);

$full_name    = htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']);
$uuid         = htmlspecialchars($profile['uuid']);
$email        = htmlspecialchars($profile['email'] ?? '');
$userlevel    = (int)$profile['userlevel'];
$member_since = date('F j, Y', (int)$profile['created']);

// ─── UserLevel display ────────────────────────────────────────────────────────
$show_level  = !user_is_lowest_tier($userlevel);
$level_label = null;
$level_class = 'level-trusted';

if ($show_level) {
    $level_label = get_user_tier_label($userlevel);
    if (user_level_meets($userlevel, 'Administrator')) { $level_class = 'level-admin'; }
    elseif (user_level_meets($userlevel, 'Grid Staff')) { $level_class = 'level-staff'; }
    else                                                { $level_class = 'level-trusted'; }
}

// ─── Grid God status ──────────────────────────────────────────────────────────
// GRID_GOD_LEVEL (config.php) is OpenSim's in-world "God" UserLevel threshold —
// an engine concept independent of the portal's named tiers above.
// We also surface UserTitle here — it's only meaningful for elevated accounts
// and is set by grid operators to describe the person's role (e.g. "Grid Liaison").
$is_grid_god  = ($userlevel >= GRID_GOD_LEVEL);
$user_title   = trim($profile['usertitle'] ?? '');

// ─── Email change POST handler ────────────────────────────────────────────────
$email_message    = null;
$email_form_value = $email;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_email') {

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['_csrf_token'] ?? '', $_POST['csrf_token'])) {
        $email_message = ['type' => 'error', 'text' => 'Security token mismatch. Please refresh and try again.'];

    } else {
        $new_email = trim($_POST['new_email'] ?? '');

        if ($new_email === '') {
            $email_message = ['type' => 'error', 'text' => 'Please enter an email address.'];
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $email_message = ['type' => 'error', 'text' => 'Please enter a valid email address.'];
        } elseif (strlen($new_email) > 254) {
            $email_message = ['type' => 'error', 'text' => 'Email address is too long.'];
        } elseif (strtolower($new_email) === strtolower($profile['email'] ?? '')) {
            $email_message = ['type' => 'error', 'text' => 'That is already your current email address.'];
        } else {

            if (EMAIL_ENABLED) {
                // ── Email enabled: confirm-then-set flow ──────────────────────
                // Store a token and email a verification link to the new address.
                // The change is NOT applied until the link is clicked.
                $email_message = handle_email_change_with_verification(
                    uuid:       $profile['uuid'],
                    firstname:  $profile['firstname'],
                    lastname:   $profile['lastname'],
                    old_email:  $profile['email'] ?? '',
                    new_email:  $new_email
                );
            } else {
                // ── Email disabled: apply immediately via ROBUST ───────────────
                // No confirmation can be sent. Less secure but the only option
                // when this grid has no working email transport.
                $api_result = robust_set_user_email(
                    $profile['uuid'],
                    $new_email
                );
                if ($api_result['success']) {
                    $email            = htmlspecialchars($new_email);
                    $email_form_value = $email;
                    $email_message    = ['type' => 'success',
                                         'text' => 'Your email address has been updated.'];
                } else {
                    $email_message = ['type' => 'error',
                                      'text' => $api_result['error'] ?? 'The change could not be applied. Please try again.'];
                }
            }
        }
    }
}

/**
 * Handles the confirm-then-set email change flow when EMAIL_ENABLED is true.
 *
 * Generates a one-time token, stores it in portal_email_tokens (replacing any
 * existing pending token for this user), and sends a verification link to the
 * new address.
 *
 * @return array ['type' => 'success'|'error'|'info', 'text' => '...']
 */
function handle_email_change_with_verification(
    string $uuid,
    string $firstname,
    string $lastname,
    string $old_email,
    string $new_email
): array {
    try {
        $db    = get_db();
        $token = bin2hex(random_bytes(32));  // 64 hex characters

        // Remove any existing pending token for this user (one at a time)
        $db->prepare("DELETE FROM portal_email_tokens WHERE uuid = :uuid")
           ->execute([':uuid' => $uuid]);

        // Store the new token — expires in 24 hours
        $db->prepare("
            INSERT INTO portal_email_tokens (uuid, old_email, new_email, token, expires_at)
            VALUES (:uuid, :old_email, :new_email, :token, DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ")->execute([
            ':uuid'      => $uuid,
            ':old_email' => $old_email,
            ':new_email' => $new_email,
            ':token'     => $token,
        ]);

    } catch (PDOException $e) {
        error_log('handle_email_change_with_verification: DB error: ' . $e->getMessage());
        return ['type' => 'error', 'text' => 'A database error occurred. Please try again.'];
    }

    // Send verification email to the new address
    $verify_url  = PORTAL_BASE_URL . '/email_verify.php?token=' . $token;
    $grid        = htmlspecialchars(GRID_NAME, ENT_QUOTES, 'UTF-8');
    $safe_url    = htmlspecialchars($verify_url, ENT_QUOTES, 'UTF-8');
    $display     = htmlspecialchars("{$firstname} {$lastname}", ENT_QUOTES, 'UTF-8');
    $safe_new    = htmlspecialchars($new_email, ENT_QUOTES, 'UTF-8');

    $html = build_email_html("
        <p>Hello {$display},</p>
        <p>You recently requested to change the email address on your
           <strong>{$grid}</strong> account to <strong>{$safe_new}</strong>.</p>
        <p>To confirm this change, please click the button below.
           This link will expire in <strong>24 hours</strong>.</p>
        <p style=\"text-align:center;margin:28px 0;\">
          <a href=\"{$safe_url}\"
             style=\"background:#6b46c1;color:#ffffff;text-decoration:none;
                    padding:12px 28px;border-radius:6px;font-size:15px;
                    display:inline-block;\">
            Confirm email change
          </a>
        </p>
        <p style=\"font-size:13px;color:#6b7280;\">
          If you did not request this change, you can ignore this email.
          Your email address will not be changed unless you click the link above.
        </p>
        <p style=\"font-size:12px;color:#9ca3af;word-break:break-all;\">
          Or copy this link: {$safe_url}
        </p>
    ");

    $text = "Hello {$firstname} {$lastname},\n\n"
          . "You requested to change your email address on {$grid} to: {$new_email}\n\n"
          . "To confirm this change, visit the following link (valid for 24 hours):\n"
          . $verify_url . "\n\n"
          . "If you did not request this change, you can ignore this email.\n";

    $sent = send_mail(
        to:        $new_email,
        subject:   '[' . GRID_NAME . '] Confirm your new email address',
        body_html: $html,
        body_text: $text,
        to_name:   "{$firstname} {$lastname}"
    );

    if (!$sent) {
        return ['type' => 'error',
                'text' => 'The verification email could not be sent. Please try again or contact a grid administrator.'];
    }

    return ['type' => 'info',
            'text' => "A verification link has been sent to {$new_email}. "
                    . 'Please check your email and click the link to confirm the change. '
                    . 'The link will expire in 24 hours.'];
}

// ─── Nav / presence state (via helper) ───────────────────────────────────────
[
    'status_class'   => $status_class,
    'status_label'   => $status_label,
    'status_tooltip' => $status_tooltip,
] = build_presence_display($session_user);

$prefs      = get_portal_prefs();
$bg_enabled = $prefs['bg'];

// ─── Estate access (for "My Estates" drawer item) ─────────────────────────────
$has_estate_access = user_has_estate_access(get_db(), $session_user['uuid']);

// ─── CSRF token for form ──────────────────────────────────────────────────────
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
    <title>Account — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
    <script>const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;</script>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('account', [], $userlevel, $has_estate_access); ?>


<!-- ══════════════════════════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════════════════════════ -->
<main class="page-wrap" id="main-content">

    <div class="account-panel" role="region" aria-label="Account details">

        <!-- Panel header -->
        <div class="account-panel-header">
            <h1 class="panel-heading">My Account</h1>
            <span class="panel-subhead">Your grid account details</span>
        </div>

        <!-- ── Section 1: Identity (read-only) ──────────────────────────── -->
        <div class="account-section">
            <p class="section-title">Identity</p>
            <dl class="info-grid">
                <dt class="info-label">Name</dt>
                <dd class="info-value"><?= $full_name ?></dd>

                <dt class="info-label">UUID</dt>
                <dd class="info-value mono" title="Your unique grid identifier"><?= $uuid ?></dd>

                <dt class="info-label">Member since</dt>
                <dd class="info-value"><?= htmlspecialchars($member_since) ?></dd>

                <?php if ($show_level && $level_label !== null): ?>
                <dt class="info-label">Access level</dt>
                <dd class="info-value">
                    <span class="level-badge <?= htmlspecialchars($level_class) ?>">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none"
                             stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                        </svg>
                        <?= htmlspecialchars($level_label) ?>
                    </span>
                </dd>
                <?php endif; ?>

                <?php if ($is_grid_god): ?>
                <dt class="info-label">Grid status</dt>
                <dd class="info-value">
                    <span class="god-badge">
                        <!-- Lightning bolt icon -->
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="currentColor"
                             stroke="none" aria-hidden="true">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
                        </svg>
                        Grid God
                    </span>
                    <?php if ($user_title !== ''): ?>
                    <span class="god-title"><?= htmlspecialchars($user_title) ?></span>
                    <?php endif; ?>
                </dd>
                <?php endif; ?>
            </dl>
        </div>

        <!-- ── Section 2: Email ──────────────────────────────────────────── -->
        <div class="account-section">
            <p class="section-title">Email Address</p>

            <?php if ($email_message): ?>
            <div class="alert alert-<?= htmlspecialchars($email_message['type']) ?>" role="alert">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <?php if ($email_message['type'] === 'success'): ?>
                        <polyline points="20 6 9 17 4 12"/>
                    <?php elseif ($email_message['type'] === 'error'): ?>
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    <?php else: ?>
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    <?php endif; ?>
                </svg>
                <?= htmlspecialchars($email_message['text']) ?>
            </div>
            <?php endif; ?>

            <p style="font-size:0.84rem;color:var(--clr-text-secondary);margin-bottom:14px;">
                <?php if ($email !== ''): ?>
                    Current address: <strong><?= $email_form_value ?></strong>
                <?php else: ?>
                    No email address is set on this account.
                <?php endif; ?>
            </p>

            <?php if (!EMAIL_ENABLED): ?>
            <p style="font-size:0.8rem;color:var(--clr-text-muted);
                      background:var(--clr-surface-2);border-radius:var(--radius-md);
                      padding:9px 13px;margin-bottom:14px;line-height:1.5;">
                Email is not configured on this grid. Changes will be applied
                immediately without a confirmation step.
            </p>
            <?php endif; ?>

            <div class="email-form-wrap">
                <form method="post" action="account.php" novalidate id="emailForm">
                    <input type="hidden" name="action" value="change_email">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <div class="form-row">
                        <div class="form-field">
                            <label class="form-label" for="new_email">
                                <?= $email !== '' ? 'New email address' : 'Email address' ?>
                            </label>
                            <input
                                type="email"
                                id="new_email"
                                name="new_email"
                                class="form-input"
                                placeholder="you@example.com"
                                maxlength="254"
                                autocomplete="email"
                                spellcheck="false"
                            >
                        </div>
                        <button type="submit" class="btn-primary" id="emailSubmitBtn">
                            <?= $email !== '' ? 'Update' : 'Save' ?>
                        </button>
                    </div>
                    <p id="emailValidationMsg"
                       style="font-size:0.78rem;color:#a3243d;margin-top:7px;display:none;"
                       role="alert" aria-live="polite"></p>
                </form>
            </div>
        </div>

    </div><!-- /.account-panel -->

</main>


<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_shared_js(); ?>

<script>
/* ── Email form client-side validation ───────────────────────────── */
document.getElementById('emailForm').addEventListener('submit', function(e) {
    const input  = document.getElementById('new_email');
    const msgEl  = document.getElementById('emailValidationMsg');
    const val    = input.value.trim();

    msgEl.style.display = 'none';
    msgEl.textContent   = '';

    if (val === '') {
        e.preventDefault();
        msgEl.textContent   = 'Please enter an email address.';
        msgEl.style.display = 'block';
        input.focus();
        return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) {
        e.preventDefault();
        msgEl.textContent   = 'Please enter a valid email address.';
        msgEl.style.display = 'block';
        input.focus();
        return;
    }
    document.getElementById('emailSubmitBtn').disabled = true;
});
</script>

</body>
</html>
