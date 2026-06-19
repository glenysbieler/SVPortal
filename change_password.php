<?php
/**
 * change_password.php — Change Password page
 *
 * Allows the logged-in user to change their grid account password.
 *
 * Flow:
 *   1. User enters their current password (verified server-side against
 *      the OpenSim auth table before any change is attempted).
 *   2. User enters a new password and confirms it.
 *   3. The new password is validated against the policy in config.php via
 *      validate_password() from includes/password_policy.php.
 *   4. On success the new password is sent to ROBUST via METHOD=setpassword
 *      on the /auth/plain endpoint, passing MD5(new_password) as the hash.
 *      ROBUST re-applies the stored salt internally.
 *
 * Password hashing note:
 *   The hash passed to setpassword is MD5(password) only — the pre-salted
 *   single-MD5. ROBUST will compute the final stored hash as:
 *     MD5( MD5(password) + ":" + stored_salt )
 *   This is consistent with how ROBUST stores and verifies passwords.
 *
 * Current-password verification uses fetch_account_by_name() + verify_password()
 * from auth.php — the same logic used by the login page.
 *
 * Requires AllowSetPassword = true in [AuthenticationService] in Robust.HG.ini.
 *
 * Data source: UserAccounts + auth tables (SELECT only for verification).
 * All mutations go through ROBUST REST API — never direct DB writes.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/profile_data.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/robust_api.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/estates.php';

session_start_secure();
require_login();

// ─── Data ─────────────────────────────────────────────────────────────────────
$session_user = get_session_user();
$profile      = get_user_profile($session_user['uuid']);

$full_name = htmlspecialchars($profile['firstname'] . ' ' . $profile['lastname']);

// ─── Presence badge ───────────────────────────────────────────────────────────
[
    'status_class'   => $status_class,
    'status_label'   => $status_label,
    'status_tooltip' => $status_tooltip,
] = build_presence_display($session_user);

// ─── Background toggle ────────────────────────────────────────────────────────
$prefs      = get_portal_prefs();
$bg_enabled = $prefs['bg'];

// ─── CSRF token ───────────────────────────────────────────────────────────────
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token           = $_SESSION['_csrf_token'];
$unread_notifications = get_unread_notification_count($session_user['uuid']);

// ─── POST handler ─────────────────────────────────────────────────────────────
$pw_message      = null;
$pw_policy_errors = [];   // policy failure list to surface in the alert

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['_csrf_token'] ?? '', $_POST['csrf_token'])) {
        $pw_message = ['type' => 'error', 'text' => 'Security token mismatch. Please refresh and try again.'];

    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password']     ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // ── Basic field checks ────────────────────────────────────────────────
        if ($current_password === '') {
            $pw_message = ['type' => 'error', 'text' => 'Please enter your current password.'];

        } elseif ($new_password === '') {
            $pw_message = ['type' => 'error', 'text' => 'Please enter a new password.'];

        } elseif ($new_password !== $confirm_password) {
            $pw_message = ['type' => 'error', 'text' => 'New passwords do not match.'];

        } elseif ($new_password === $current_password) {
            $pw_message = ['type' => 'error', 'text' => 'New password must be different from your current password.'];

        } else {
            // ── Policy check ──────────────────────────────────────────────────
            $policy = validate_password($new_password);

            if (!$policy['valid']) {
                $pw_policy_errors = $policy['errors'];
                $pw_message = ['type' => 'error', 'text' => 'Your new password does not meet the requirements:'];

            } else {
                // ── Verify current password ───────────────────────────────────
                try {
                    $account_row = fetch_account_by_name($profile['firstname'], $profile['lastname']);

                    if ($account_row === null) {
                        error_log('change_password.php: fetch_account_by_name returned null for logged-in user ' . $profile['uuid']);
                        $pw_message = ['type' => 'error', 'text' => 'Account data could not be loaded. Please try again.'];

                    } elseif (!verify_password($current_password, $profile['firstname'], $profile['lastname'], $account_row)) {
                        $pw_message = ['type' => 'error', 'text' => 'Your current password is incorrect.'];

                    } else {
                        // ROBUST SetPassword expects plain text — it generates a new salt and hashes internally.

                        $api_result = robust_set_user_password($profile['uuid'], $new_password);

                        if ($api_result['success']) {
                            $pw_message = ['type' => 'success', 'text' => 'Your password has been changed successfully.'];
                        } else {
                            $pw_message = [
                                'type' => 'error',
                                'text' => $api_result['error'] ?? 'The change could not be applied. Please try again.',
                            ];
                        }
                    }
                } catch (RuntimeException $e) {
                    error_log('change_password.php: DB error: ' . $e->getMessage());
                    $pw_message = ['type' => 'error', 'text' => 'A database error occurred. Please try again.'];
                }
            }
        }
    }
}

// ─── Pass policy config to JS ─────────────────────────────────────────────────
$js_policy = json_encode(get_password_policy_js_config(), JSON_THROW_ON_ERROR);

// ─── Estate access (for "My Estates" drawer item) ─────────────────────────────
$has_estate_access = user_has_estate_access(get_db(), $session_user['uuid']);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
    <script>const PORTAL_CSRF = <?= json_encode($csrf_token) ?>;</script>
</head>
<body<?= $bg_enabled ? '' : ' class="no-bg"' ?>>

<?php render_bg_layer(); ?>
<?php render_navbar($full_name, $status_class, $status_label, $status_tooltip, $unread_notifications); ?>
<?php render_drawer('change_password', [], (int)($session_user['userlevel'] ?? 0), $has_estate_access); ?>


<!-- ══════════════════════════════════════════════════════════════════════
     PAGE CONTENT
═══════════════════════════════════════════════════════════════════════ -->
<main class="page-wrap" id="main-content">

    <div class="account-panel" role="region" aria-label="Change password">

        <!-- Panel header -->
        <div class="account-panel-header">
            <h1 class="panel-heading">Change Password</h1>
            <span class="panel-subhead">Update your grid account password</span>
        </div>

        <!-- ── Change password form ──────────────────────────────────────── -->
        <div class="account-section">
            <p class="section-title">New Password</p>

            <?php if ($pw_message): ?>
            <div class="alert alert-<?= htmlspecialchars($pw_message['type']) ?>" role="alert">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <?php if ($pw_message['type'] === 'success'): ?>
                        <polyline points="20 6 9 17 4 12"/>
                    <?php else: ?>
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    <?php endif; ?>
                </svg>
                <div>
                    <?= htmlspecialchars($pw_message['text']) ?>
                    <?php if (!empty($pw_policy_errors)): ?>
                    <ul class="alert-error-list">
                        <?php foreach ($pw_policy_errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Static requirements summary — always visible -->
            <?php
            $reqs = ['At least ' . PW_MIN_LENGTH . ' characters'];
            if (PW_REQUIRE_UPPER)  $reqs[] = 'one uppercase letter (A–Z)';
            if (PW_REQUIRE_LOWER)  $reqs[] = 'one lowercase letter (a–z)';
            if (PW_REQUIRE_NUMBER) $reqs[] = 'one number (0–9)';
            if (PW_REQUIRE_SYMBOL) $reqs[] = 'one symbol (e.g. !@#$%)';
            ?>
            <p class="requirements-intro">
                Password must contain: <?= htmlspecialchars(implode(', ', $reqs)) ?>.
            </p>

            <form method="post" action="change_password.php" novalidate id="pwForm"
                  autocomplete="off">
                <input type="hidden" name="action"     value="change_password">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div class="form-stack">

                    <!-- Current password -->
                    <div class="form-field">
                        <label class="form-label" for="current_password">Current password</label>
                        <input
                            type="password"
                            id="current_password"
                            name="current_password"
                            class="form-input"
                            autocomplete="current-password"
                            spellcheck="false"
                        >
                    </div>

                    <!-- New password + live requirements checklist -->
                    <div class="form-field">
                        <label class="form-label" for="new_password">New password</label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            class="form-input"
                            autocomplete="new-password"
                            spellcheck="false"
                        >
                        <!-- Requirements checklist — populated by JS from PW_POLICY -->
                        <div class="pw-rules" id="pwRules" aria-live="polite"></div>
                    </div>

                    <!-- Confirm new password -->
                    <div class="form-field">
                        <label class="form-label" for="confirm_password">Confirm new password</label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-input"
                            autocomplete="new-password"
                            spellcheck="false"
                        >
                        <p id="confirmMsg" role="alert" aria-live="polite"></p>
                    </div>

                    <button type="submit" class="btn-primary" id="pwSubmitBtn">
                        Change password
                    </button>

                </div><!-- /.form-stack -->

                <p id="pwValidationMsg"
                   style="font-size:0.78rem;color:#a3243d;margin-top:10px;display:none;"
                   role="alert" aria-live="polite"></p>
            </form>
        </div>

    </div><!-- /.account-panel -->

</main>


<?php render_theme_modal($bg_enabled); ?>
<?php render_logout_modal(); ?>
<?php render_shared_js(); ?>

<script>
/* ── Password policy (mirrored from config.php via PHP) ────────────── */
const PW_POLICY = <?= $js_policy ?>;

/* ── Build the requirements checklist ─────────────────────────────── */
(function () {
    const rulesEl = document.getElementById('pwRules');

    // Define rules in display order.
    // Each rule: { id, label, test(pw) }
    const rules = [];

    rules.push({
        id:    'length',
        label: 'At least ' + PW_POLICY.minLength + ' characters',
        test:  pw => pw.length >= PW_POLICY.minLength,
    });
    if (PW_POLICY.requireUpper) rules.push({
        id:    'upper',
        label: 'One uppercase letter (A–Z)',
        test:  pw => /[A-Z]/.test(pw),
    });
    if (PW_POLICY.requireLower) rules.push({
        id:    'lower',
        label: 'One lowercase letter (a–z)',
        test:  pw => /[a-z]/.test(pw),
    });
    if (PW_POLICY.requireNumber) rules.push({
        id:    'number',
        label: 'One number (0–9)',
        test:  pw => /[0-9]/.test(pw),
    });
    if (PW_POLICY.requireSymbol) rules.push({
        id:    'symbol',
        label: 'One symbol (e.g. !@#$%^&*)',
        test:  pw => /[^A-Za-z0-9]/.test(pw),
    });

    // Render rule elements
    const checkSvg = '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" '
                   + 'stroke="#fff" stroke-width="3.5" aria-hidden="true">'
                   + '<polyline points="20 6 9 17 4 12"/></svg>';

    rules.forEach(rule => {
        const el = document.createElement('div');
        el.className   = 'pw-rule';
        el.id          = 'rule-' + rule.id;
        el.innerHTML   = '<span class="rule-icon">' + checkSvg + '</span>'
                       + '<span class="rule-text">' + rule.label + '</span>';
        rulesEl.appendChild(el);
    });

    // Update on input
    const newInput     = document.getElementById('new_password');
    const confirmInput = document.getElementById('confirm_password');
    const confirmMsg   = document.getElementById('confirmMsg');

    function updateRules() {
        const pw = newInput.value;
        rules.forEach(rule => {
            const el = document.getElementById('rule-' + rule.id);
            if (rule.test(pw)) {
                el.classList.add('met');
                el.querySelector('.rule-text').textContent = rule.label;
            } else {
                el.classList.remove('met');
                el.querySelector('.rule-text').textContent = rule.label;
            }
        });
        if (confirmInput.value !== '') checkConfirm();
    }

    function checkConfirm() {
        const match = newInput.value === confirmInput.value;
        confirmMsg.style.display = match ? 'none' : 'block';
        confirmMsg.textContent   = match ? '' : 'Passwords do not match.';
    }

    newInput.addEventListener('input', updateRules);
    confirmInput.addEventListener('input', checkConfirm);

    // Show checklist only once the user starts typing
    newInput.addEventListener('input', function () {
        rulesEl.style.display = this.value === '' ? 'none' : 'flex';
    });
    rulesEl.style.display = 'none';
})();


/* ── Form submit — final client-side gate ──────────────────────────── */
document.getElementById('pwForm').addEventListener('submit', function (e) {
    const current = document.getElementById('current_password').value;
    const newPw   = document.getElementById('new_password').value;
    const confirm = document.getElementById('confirm_password').value;
    const msgEl   = document.getElementById('pwValidationMsg');

    msgEl.style.display = 'none';
    msgEl.textContent   = '';

    function fail(msg, focusId) {
        e.preventDefault();
        msgEl.textContent   = msg;
        msgEl.style.display = 'block';
        document.getElementById(focusId).focus();
    }

    if (current.trim() === '')
        return fail('Please enter your current password.', 'current_password');

    // Mirror policy checks so the button never submits a non-compliant password
    if (newPw.length < PW_POLICY.minLength)
        return fail('New password must be at least ' + PW_POLICY.minLength + ' characters.', 'new_password');
    if (PW_POLICY.requireUpper && !/[A-Z]/.test(newPw))
        return fail('New password must contain at least one uppercase letter.', 'new_password');
    if (PW_POLICY.requireLower && !/[a-z]/.test(newPw))
        return fail('New password must contain at least one lowercase letter.', 'new_password');
    if (PW_POLICY.requireNumber && !/[0-9]/.test(newPw))
        return fail('New password must contain at least one number.', 'new_password');
    if (PW_POLICY.requireSymbol && !/[^A-Za-z0-9]/.test(newPw))
        return fail('New password must contain at least one symbol.', 'new_password');

    if (newPw !== confirm)
        return fail('New passwords do not match.', 'confirm_password');

    document.getElementById('pwSubmitBtn').disabled = true;
});
</script>

</body>
</html>
