<?php
/**
 * register.php — Public account registration page
 *
 * Public-facing signup form. Gated behind FEATURE_REGISTRATION in config.php.
 *
 * Collects: first name, last name, email, password (+ confirm), and
 * optionally a starter avatar (only shown if STARTER_AVATARS is non-empty).
 *
 * Submissions are inserted into the portal-owned pending_registrations table
 * — NO OpenSim account is created at this stage. An admin meeting the
 * 'Grid Staff' tier (see USERLEVEL_LABELS in config.php) must approve the
 * request via admin.php before the account is created (see
 * robust_create_user() in includes/robust_api.php).
 *
 * If EMAIL_ENABLED is true and ADMIN_NOTIFY_EMAILS is non-empty, a
 * notification email is sent to each address on submission.
 *
 * Data source: pending_registrations (INSERT only — portal-owned table).
 * No writes to any OpenSim-owned table occur here.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/theme_loader.php';
require_once __DIR__ . '/includes/password_policy.php';
require_once __DIR__ . '/includes/register_avatars.php';

if (EMAIL_ENABLED) {
    require_once __DIR__ . '/includes/mailer.php';
}

session_start_secure();

// Registration not enabled on this grid — show a friendly notice rather than 404.
if (!defined('FEATURE_REGISTRATION') || !FEATURE_REGISTRATION) {
    http_response_code(404);
    $bg_enabled = ($_COOKIE['portal_bg'] ?? '1') !== '0';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration unavailable — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
</head>
<body class="login-page">
<main class="login-main">
    <div class="login-card" style="text-align:center;max-width:480px;">
        <h1 class="panel-heading" style="margin-bottom:12px;">Registration is not available</h1>
        <p style="color:var(--clr-text-secondary);margin-bottom:20px;">
            New account registration is not currently open on
            <?= htmlspecialchars(GRID_DISPLAY_NAME ?? GRID_NAME) ?>.
        </p>
        <a href="login.php" class="btn-primary">
            Back to sign in
        </a>
    </div>
</main>
</body>
</html>
    <?php
    exit;
}

// Already logged in — registration is for new accounts only.
if (is_logged_in()) {
    header('Location: profile.php');
    exit;
}

// ── CSRF token ─────────────────────────────────────────────────────────────
if (empty($_SESSION['_csrf_token'])) {
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['_csrf_token'];

// ── Form state ───────────────────────────────────────────────────────────────
$error          = '';
$success        = false;
$firstname      = '';
$lastname       = '';
$email          = '';
$starter_avatar = '';
$policy_errors  = [];

// Tracks which step to show on redisplay after a server-side validation
// failure. Defaults to step 1 (CSRF/name/email/password errors all belong
// there). Flipped to true only once every step-1 field check has already
// passed — at that point ANY further error (invalid avatar key, duplicate
// name, DB failure) happened on behalf of a person who had already reached
// and submitted step 2, so redisplay should return them there rather than
// bouncing them back to a step with nothing wrong on it.
$show_step2 = false;

// ── Handle POST submission ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST['_token']) || !hash_equals($_SESSION['_csrf_token'] ?? '', $_POST['_token'])) {
        $error = 'Invalid form submission. Please refresh the page and try again.';
    } else {
        $firstname      = trim($_POST['firstname'] ?? '');
        $lastname       = trim($_POST['lastname']  ?? '');
        $email          = trim($_POST['email']     ?? '');
        $password       = $_POST['password']         ?? '';
        $confirm        = $_POST['confirm_password'] ?? '';
        $starter_avatar = trim($_POST['starter_avatar'] ?? '');

        // ── Basic field validation ────────────────────────────────────────────
        if ($firstname === '' || $lastname === '') {
            $error = 'Please enter both a first and last name.';
        } elseif (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 \'\-\.]*[A-Za-z0-9])?$/', $firstname)
               || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9 \'\-\.]*[A-Za-z0-9])?$/', $lastname)) {
            $error = 'Names may only contain letters, numbers, spaces, apostrophes, hyphens, and periods.';
        } elseif (strlen($firstname) > 64 || strlen($lastname) > 64) {
            $error = 'Names must be 64 characters or fewer.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($email) > 254) {
            $error = 'Email address is too long.';
        } elseif ($password === '') {
            $error = 'Please choose a password.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            // Every step-1 field check has passed — from here on, this
            // person was necessarily on step 2 when they hit Submit.
            $show_step2 = true;

            // ── Password policy ───────────────────────────────────────────────
            $policy = validate_password($password);
            if (!$policy['valid']) {
                $policy_errors = $policy['errors'];
                $error = 'Your password does not meet the requirements:';
            } elseif (defined('STARTER_AVATARS') && !empty(STARTER_AVATARS) && $starter_avatar !== ''
                   && !array_key_exists($starter_avatar, STARTER_AVATARS)) {
                // array_key_exists (not isset) — a configured avatar whose label
                // override is null must still validate successfully.
                $error = 'Please choose a valid starter avatar.';
            } else {
                // ── Duplicate checks ──────────────────────────────────────────
                try {
                    $db = get_db();

                    // Already a grid account with this name?
                    $stmt = $db->prepare(
                        'SELECT 1 FROM UserAccounts WHERE LOWER(FirstName) = LOWER(:f) AND LOWER(LastName) = LOWER(:l) LIMIT 1'
                    );
                    $stmt->execute([':f' => $firstname, ':l' => $lastname]);
                    $name_taken = (bool)$stmt->fetchColumn();

                    // Already a pending registration with this name?
                    $stmt = $db->prepare(
                        "SELECT 1 FROM pending_registrations
                         WHERE LOWER(firstname) = LOWER(:f) AND LOWER(lastname) = LOWER(:l)
                           AND status = 'pending' LIMIT 1"
                    );
                    $stmt->execute([':f' => $firstname, ':l' => $lastname]);
                    $name_pending = (bool)$stmt->fetchColumn();

                    if ($name_taken) {
                        $error = 'An account with that name already exists. Please choose a different name, or sign in if this is your account.';
                    } elseif ($name_pending) {
                        $error = 'A registration with that name is already pending approval.';
                    } else {
                        // ── Insert pending registration ─────────────────────────
                        $stmt = $db->prepare(
                            'INSERT INTO pending_registrations
                                (firstname, lastname, email, password, starter_avatar, submitted_at, status)
                             VALUES (:f, :l, :e, :p, :a, NOW(), \'pending\')'
                        );
                        $stmt->execute([
                            ':f' => $firstname,
                            ':l' => $lastname,
                            ':e' => $email,
                            ':p' => $password,
                            ':a' => $starter_avatar,
                        ]);

                        // ── Notify admins ────────────────────────────────────────
                        if (EMAIL_ENABLED && defined('ADMIN_NOTIFY_EMAILS') && !empty(ADMIN_NOTIFY_EMAILS)) {
                            notify_admins_of_registration($firstname, $lastname, $email);
                        }

                        $success = true;
                    }
                } catch (Throwable $e) {
                    error_log('register.php: ' . $e->getMessage());
                    $error = 'A database error occurred. Please try again shortly.';
                }
            }
        }
    }
}

/**
 * Sends a "new registration pending" notice to each configured admin address.
 */
function notify_admins_of_registration(string $firstname, string $lastname, string $email): void
{
    $grid      = htmlspecialchars(GRID_NAME, ENT_QUOTES, 'UTF-8');
    $safe_name = htmlspecialchars("{$firstname} {$lastname}", ENT_QUOTES, 'UTF-8');
    $safe_mail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $portal    = htmlspecialchars(PORTAL_BASE_URL, ENT_QUOTES, 'UTF-8');
    $admin_url = $portal . '/admin.php';

    $html = build_email_html("
        <p>A new account registration is awaiting approval on <strong>{$grid}</strong>.</p>
        <p><strong>Name:</strong> {$safe_name}<br>
           <strong>Email:</strong> {$safe_mail}</p>
        <p><a href=\"{$admin_url}\" style=\"color:#6b46c1;\">Review pending registrations</a></p>
    ");

    $text = "A new account registration is awaiting approval on {$grid}.\n\n"
          . "Name:  {$firstname} {$lastname}\n"
          . "Email: {$email}\n\n"
          . "Review pending registrations: {$portal}/admin.php\n";

    foreach (ADMIN_NOTIFY_EMAILS as $to) {
        send_mail(
            to:        $to,
            subject:   '[' . GRID_NAME . '] New registration awaiting approval',
            body_html: $html,
            body_text: $text
        );
    }
}

// ── Page setup ─────────────────────────────────────────────────────────────
$js_policy = json_encode(get_password_policy_js_config(), JSON_THROW_ON_ERROR);

// Resolved starter avatar picker options (label + live profile picture URL).
// Empty array if STARTER_AVATARS is unset/empty — the avatar-selection step
// is omitted from the form entirely in that case (single-step registration,
// matching today's behaviour for grids that don't use this feature).
$starter_avatar_options = get_starter_avatar_options();
$has_starter_avatars     = !empty($starter_avatar_options);

// ── Random splash background (same as login.php / splash.php) ──────────────
// Sourced from the default theme's /bgimages/ folder (falls back to
// themes/default/bgimages/ automatically if a custom DEFAULT_THEME has none).
$splash_bg_url = null;
if (defined('SHOW_LOGIN_BACKGROUND') && SHOW_LOGIN_BACKGROUND) {
    $splash_bg_url = theme_bg_image_url(defined('DEFAULT_THEME') ? DEFAULT_THEME : 'default');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — <?= htmlspecialchars(GRID_NAME) ?></title>
    <?php render_shared_css(); ?>
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
    <div class="login-card register-card<?= $has_starter_avatars && !$success ? ' register-card-wide' : '' ?>">

        <div class="login-logo">
            <img src="<?= htmlspecialchars(theme_image_url('mainlogo.png')) ?>" alt="<?= htmlspecialchars(GRID_NAME) ?>">
        </div>

        <h1 class="panel-heading" style="text-align:center;margin-bottom:4px;">Create an account</h1>
        <p class="login-subtitle" style="text-align:center;">
            Register for an avatar on <?= htmlspecialchars(GRID_DISPLAY_NAME ?? GRID_NAME) ?>
        </p>

        <?php if ($success): ?>
        <div class="alert alert-success" role="status" aria-live="polite" style="margin-top:18px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <div>
                Thanks! Your registration has been submitted and is awaiting approval
                by a grid administrator. You'll be able to sign in once it's approved.
            </div>
        </div>

        <p style="text-align:center;margin-top:20px;">
            <a href="login.php" class="btn-primary">
                Back to sign in
            </a>
        </p>

        <?php else: ?>

        <?php if ($error !== ''): ?>
        <div class="alert alert-error" role="alert" aria-live="assertive" style="margin-top:14px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <div>
                <?= htmlspecialchars($error) ?>
                <?php if (!empty($policy_errors)): ?>
                <ul style="margin:6px 0 0 18px;padding:0;">
                    <?php foreach ($policy_errors as $perr): ?>
                    <li><?= htmlspecialchars($perr) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($has_starter_avatars): ?>
        <div class="register-progress-dots" id="registerProgressDots" aria-hidden="true">
            <span class="register-progress-dot<?= $show_step2 ? '' : ' active' ?>" data-step="1"></span>
            <span class="register-progress-dot<?= $show_step2 ? ' active' : '' ?>" data-step="2"></span>
        </div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate id="registerForm">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" id="starter_avatar" name="starter_avatar" value="<?= htmlspecialchars($starter_avatar) ?>">

            <!-- ── Step 1: account details ─────────────────────────────────────── -->
            <div class="register-step" id="registerStep1" data-step="1"<?= ($has_starter_avatars && $show_step2) ? ' hidden' : '' ?>>

                <div class="login-form-row">
                    <div class="form-group">
                        <label for="firstname">First name</label>
                        <input
                            type="text" id="firstname" name="firstname"
                            value="<?= htmlspecialchars($firstname) ?>"
                            autocomplete="given-name" autocapitalize="words"
                            spellcheck="false" maxlength="64" required
                        >
                    </div>
                    <div class="form-group">
                        <label for="lastname">Last name</label>
                        <input
                            type="text" id="lastname" name="lastname"
                            value="<?= htmlspecialchars($lastname) ?>"
                            autocomplete="family-name" autocapitalize="words"
                            spellcheck="false" maxlength="64" required
                        >
                    </div>
                </div>
                <p class="login-hint" style="margin-top:-4px;margin-bottom:14px;">
                    This will be your avatar's full name in-world. Choose carefully — it's difficult to change later.
                </p>

                <div class="form-group">
                    <label for="email">Email address</label>
                    <input
                        type="email" id="email" name="email"
                        class="form-input"
                        value="<?= htmlspecialchars($email) ?>"
                        autocomplete="email" spellcheck="false" maxlength="254" required
                    >
                </div>

                <!-- Static requirements summary — always visible -->
                <?php
                $pw_reqs = ['At least ' . PW_MIN_LENGTH . ' characters'];
                if (PW_REQUIRE_UPPER)  $pw_reqs[] = 'one uppercase letter (A–Z)';
                if (PW_REQUIRE_LOWER)  $pw_reqs[] = 'one lowercase letter (a–z)';
                if (PW_REQUIRE_NUMBER) $pw_reqs[] = 'one number (0–9)';
                if (PW_REQUIRE_SYMBOL) $pw_reqs[] = 'one symbol (e.g. !@#$%)';
                ?>
                <p class="requirements-intro">
                    Password must contain: <?= htmlspecialchars(implode(', ', $pw_reqs)) ?>.
                </p>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input
                            type="password" id="password" name="password"
                            class="form-input"
                            autocomplete="new-password" maxlength="128" required
                        >
                        <button type="button" class="toggle-pw" onclick="togglePassword('password','pw-eye-1')" aria-label="Show or hide password" tabindex="-1">
                            <svg id="pw-eye-1" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <div class="pw-rules" id="pwRules" aria-live="polite"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm password</label>
                    <div class="password-wrap">
                        <input
                            type="password" id="confirm_password" name="confirm_password"
                            class="form-input"
                            autocomplete="new-password" maxlength="128" required
                        >
                        <button type="button" class="toggle-pw" onclick="togglePassword('confirm_password','pw-eye-2')" aria-label="Show or hide password" tabindex="-1">
                            <svg id="pw-eye-2" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <p id="confirmMsg" role="alert" aria-live="polite"></p>
                </div>

                <?php if ($has_starter_avatars): ?>
                <button type="button" class="btn-primary" id="registerNextBtn">
                    Next: Choose your starter avatar
                </button>
                <?php else: ?>
                <button type="submit" class="btn-primary" id="registerSubmitBtn">
                    Submit registration
                </button>
                <?php endif; ?>

                <p id="registerValidationMsg"
                   style="font-size:0.78rem;color:#a3243d;margin-top:10px;display:none;"
                   role="alert" aria-live="polite"></p>
            </div>

            <?php if ($has_starter_avatars): ?>
            <!-- ── Step 2: starter avatar selection ────────────────────────────── -->
            <div class="register-step" id="registerStep2" data-step="2"<?= $show_step2 ? '' : ' hidden' ?>>

                <p class="login-hint" style="margin-top:-4px;margin-bottom:14px;">
                    Pick the avatar you'd like to start as, or skip this step to begin with
                    no starting appearance or inventory.
                </p>

                <div class="avatar-picker" id="avatarPicker" role="radiogroup" aria-label="Starter avatar">
                    <?php foreach ($starter_avatar_options as $option): ?>
                    <button type="button"
                        class="avatar-swatch"
                        data-key="<?= htmlspecialchars($option['key'], ENT_QUOTES) ?>"
                        role="radio"
                        aria-checked="false"
                    >
                        <span class="avatar-swatch-img">
                            <img src="<?= htmlspecialchars($option['image_url']) ?>"
                                 alt="" loading="lazy">
                        </span>
                        <span class="avatar-swatch-name"><?= htmlspecialchars($option['label']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div class="register-step2-actions">
                    <button type="button" class="btn-primary-pill" id="registerBackBtn">
                        Back
                    </button>
                    <button type="submit" class="btn-primary" id="registerSubmitBtn2">
                        Submit registration
                    </button>
                </div>

                <p id="registerStep2Msg" class="register-step2-msg"
                   role="status" aria-live="polite">No avatar selected — that's fine, skip ahead if you'd prefer.</p>
            </div>
            <?php endif; ?>

        </form>

        <p class="login-hint" style="text-align:center;">
            Already have an account? <a href="login.php">Sign in</a>
        </p>

        <?php endif; ?>

    </div>
</main>

<script>
const PW_POLICY = <?= $js_policy ?>;

/* Password show/hide toggle (supports two password fields) */
function togglePassword(inputId, eyeId) {
    const input = document.getElementById(inputId);
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';

    const eye = document.getElementById(eyeId);
    if (isHidden) {
        eye.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>' +
            '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>' +
            '<line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        eye.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}

<?php if (!$success): ?>
/* ── Step 1 field validation — shared by the "Next" button (when a starter
   avatar step follows) and the form's own submit handler (when step 1 is
   the only step, or as a final safety net before the real submission). ── */
function validateStep1() {
    const firstname = document.getElementById('firstname').value.trim();
    const lastname  = document.getElementById('lastname').value.trim();
    const email     = document.getElementById('email').value.trim();
    const pw        = document.getElementById('password').value;
    const confirm   = document.getElementById('confirm_password').value;
    const msgEl     = document.getElementById('registerValidationMsg');

    msgEl.style.display = 'none';
    msgEl.textContent = '';

    function fail(msg, focusId) {
        msgEl.textContent = msg;
        msgEl.style.display = 'block';
        document.getElementById(focusId).focus();
        return false;
    }

    if (firstname === '' || lastname === '')
        return fail('Please enter both a first and last name.', 'firstname');
    if (email === '')
        return fail('Please enter your email address.', 'email');

    if (pw.length < PW_POLICY.minLength)
        return fail('Password must be at least ' + PW_POLICY.minLength + ' characters.', 'password');
    if (PW_POLICY.requireUpper && !/[A-Z]/.test(pw))
        return fail('Password must contain at least one uppercase letter.', 'password');
    if (PW_POLICY.requireLower && !/[a-z]/.test(pw))
        return fail('Password must contain at least one lowercase letter.', 'password');
    if (PW_POLICY.requireNumber && !/[0-9]/.test(pw))
        return fail('Password must contain at least one number.', 'password');
    if (PW_POLICY.requireSymbol && !/[^A-Za-z0-9]/.test(pw))
        return fail('Password must contain at least one symbol.', 'password');

    if (pw !== confirm)
        return fail('Passwords do not match.', 'confirm_password');

    return true;
}

/* ── Password requirements checklist (mirrors change_password.php) ──────── */
(function () {
    const rulesEl = document.getElementById('pwRules');
    const rules = [];

    rules.push({ id: 'length', label: 'At least ' + PW_POLICY.minLength + ' characters', test: pw => pw.length >= PW_POLICY.minLength });
    if (PW_POLICY.requireUpper)  rules.push({ id: 'upper',  label: 'One uppercase letter (A–Z)', test: pw => /[A-Z]/.test(pw) });
    if (PW_POLICY.requireLower)  rules.push({ id: 'lower',  label: 'One lowercase letter (a–z)', test: pw => /[a-z]/.test(pw) });
    if (PW_POLICY.requireNumber) rules.push({ id: 'number', label: 'One number (0–9)', test: pw => /[0-9]/.test(pw) });
    if (PW_POLICY.requireSymbol) rules.push({ id: 'symbol', label: 'One symbol (e.g. !@#$%^&*)', test: pw => /[^A-Za-z0-9]/.test(pw) });

    const checkSvg = '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" '
                   + 'stroke="#fff" stroke-width="3.5" aria-hidden="true">'
                   + '<polyline points="20 6 9 17 4 12"/></svg>';

    rules.forEach(rule => {
        const el = document.createElement('div');
        el.className = 'pw-rule';
        el.id = 'rule-' + rule.id;
        el.innerHTML = '<span class="rule-icon">' + checkSvg + '</span>'
                      + '<span class="rule-text">' + rule.label + '</span>';
        rulesEl.appendChild(el);
    });

    const pwInput      = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const confirmMsg   = document.getElementById('confirmMsg');

    function updateRules() {
        const pw = pwInput.value;
        rules.forEach(rule => {
            const el = document.getElementById('rule-' + rule.id);
            if (rule.test(pw)) el.classList.add('met');
            else el.classList.remove('met');
        });
        if (confirmInput.value !== '') checkConfirm();
    }

    function checkConfirm() {
        const match = pwInput.value === confirmInput.value;
        confirmMsg.style.display = match ? 'none' : 'block';
        confirmMsg.textContent = match ? '' : 'Passwords do not match.';
    }

    pwInput.addEventListener('input', function () {
        updateRules();
        rulesEl.style.display = this.value === '' ? 'none' : 'flex';
    });
    confirmInput.addEventListener('input', checkConfirm);
    rulesEl.style.display = 'none';
})();

<?php if ($has_starter_avatars): ?>
/* ── Two-step navigation (account details -> starter avatar) ────────────── */
(function () {
    const step1   = document.getElementById('registerStep1');
    const step2   = document.getElementById('registerStep2');
    const dots    = document.querySelectorAll('#registerProgressDots .register-progress-dot');
    const nextBtn = document.getElementById('registerNextBtn');
    const backBtn = document.getElementById('registerBackBtn');

    function showStep(n) {
        step1.hidden = (n !== 1);
        step2.hidden = (n !== 2);
        dots.forEach(dot => {
            dot.classList.toggle('active', Number(dot.dataset.step) === n);
        });
        if (n === 1) {
            document.getElementById('firstname').focus();
        }
    }

    nextBtn.addEventListener('click', function () {
        if (validateStep1()) {
            showStep(2);
        }
    });

    backBtn.addEventListener('click', function () {
        showStep(1);
    });
})();

/* ── Starter avatar swatch picker ─────────────────────────────────────────
   Single-select toggle button group writing into the hidden
   starter_avatar field. Clicking the already-selected swatch deselects it
   (registration proceeds with no starter avatar) — there is no separate
   "no preference" tile, matching the "skip this step" framing in the
   step 2 intro text. ── */
(function () {
    const picker  = document.getElementById('avatarPicker');
    const hidden  = document.getElementById('starter_avatar');
    const msgEl   = document.getElementById('registerStep2Msg');
    if (!picker) return;

    const swatches = Array.from(picker.querySelectorAll('.avatar-swatch'));

    function selectKey(key) {
        swatches.forEach(sw => {
            const isSelected = sw.dataset.key === key;
            sw.classList.toggle('active', isSelected);
            sw.setAttribute('aria-checked', isSelected ? 'true' : 'false');
        });
        hidden.value = key;
        if (key === '') {
            msgEl.textContent = "No avatar selected — that's fine, skip ahead if you'd prefer.";
        } else {
            const label = swatches.find(sw => sw.dataset.key === key)
                ?.querySelector('.avatar-swatch-name')?.textContent ?? key;
            msgEl.textContent = 'Starting as: ' + label;
        }
    }

    swatches.forEach(sw => {
        sw.addEventListener('click', function () {
            selectKey(hidden.value === sw.dataset.key ? '' : sw.dataset.key);
        });
    });

    // Restore selection on a failed-submission reload (server echoes the
    // posted starter_avatar back into the hidden field's value attribute).
    if (hidden.value !== '') {
        selectKey(hidden.value);
    }
})();
<?php endif; ?>

/* ── Final client-side gate + submit-button lock ──────────────────────────
   When a starter-avatar step exists, the only <form> submit event fires
   from step 2's button — but step 1's fields still need validating at
   that point, since they're the actual data being submitted. ── */
document.getElementById('registerForm').addEventListener('submit', function (e) {
    if (!validateStep1()) {
        e.preventDefault();
        <?php if ($has_starter_avatars): ?>
        // Validation failed on a field that lives on step 1 — jump back
        // so the person can see what's wrong rather than guessing from
        // step 2.
        document.getElementById('registerStep1').hidden = false;
        document.getElementById('registerStep2').hidden = true;
        document.querySelectorAll('#registerProgressDots .register-progress-dot').forEach(dot => {
            dot.classList.toggle('active', dot.dataset.step === '1');
        });
        <?php endif; ?>
        return;
    }

    const submitBtn = document.getElementById('registerSubmitBtn') || document.getElementById('registerSubmitBtn2');
    if (submitBtn) submitBtn.disabled = true;
});
<?php endif; ?>
</script>

</body>
</html>
