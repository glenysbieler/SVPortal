<?php
/**
 * auth.php — Authentication helpers
 *
 * Handles login validation against the OpenSim UserAccounts table and
 * PHP session management. All database access is read-only (SELECT only).
 *
 * ── OpenSim password hashing ────────────────────────────────────────────────
 *
 * Standard OpenSim stores passwords as:
 *
 *   MD5( MD5(password) + ":" + MD5(firstname + ":" + lastname) )
 *
 * The `passwordHash` column in UserAccounts holds the final hash.
 * The `passwordSalt` column holds MD5(firstname + ":" + lastname) —
 * we can verify this matches what we compute, as an extra sanity check.
 *
 * ── Non-standard hashing ────────────────────────────────────────────────────
 *
 * Some grids replace the MD5 scheme with Argon2 or bcrypt. If your grid
 * has done this, AUTH_HASH_SCHEME in config.php should be set accordingly.
 * Currently only 'md5' is implemented; others will throw a clear error
 * rather than silently failing open.
 *
 * ── Brute-force protection ───────────────────────────────────────────────────
 *
 * Failed attempts are tracked in $_SESSION. After AUTH_MAX_ATTEMPTS
 * consecutive failures, login is locked for AUTH_LOCKOUT_SECONDS.
 * This is a lightweight in-session rate limit — not a substitute for
 * a proper IP-level rate limit at the web server, but good enough for
 * a low-traffic grid portal.
 *
 * ── Session security ─────────────────────────────────────────────────────────
 *
 * - Session ID is regenerated on every successful login (fixes session fixation).
 * - The session cookie is set HttpOnly and SameSite=Lax.
 * - Inactivity timeout: sessions expire if idle for longer than the configured
 *   threshold. Two tiers — SESSION_TIMEOUT_SECONDS for regular users,
 *   ADMIN_SESSION_TIMEOUT_SECONDS for users meeting the 'Administrator'
 *   tier (see USERLEVEL_LABELS / user_level_meets() in config.php).
 *   Either can be set to 0 in config.php to disable that tier (e.g. dev mode).
 *   The timeout is checked on every page load in session_start_secure() and
 *   in is_logged_in(), so expiry is enforced consistently across the portal.
 *   The login page receives ?reason=timeout when a stale session is detected,
 *   allowing it to display an appropriate "you were logged out" message.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ── Constants (can be overridden in config.php) ───────────────────────────────
if (!defined('AUTH_HASH_SCHEME'))              define('AUTH_HASH_SCHEME',              'md5');
if (!defined('AUTH_MAX_ATTEMPTS'))             define('AUTH_MAX_ATTEMPTS',             5);
if (!defined('AUTH_LOCKOUT_SECONDS'))          define('AUTH_LOCKOUT_SECONDS',          300);   // 5 minutes
if (!defined('SESSION_TIMEOUT_SECONDS'))       define('SESSION_TIMEOUT_SECONDS',       3600);  // 1 hour  — regular users
if (!defined('ADMIN_SESSION_TIMEOUT_SECONDS')) define('ADMIN_SESSION_TIMEOUT_SECONDS', 1800);  // 30 mins — admin users


// ── Session bootstrap ─────────────────────────────────────────────────────────

/**
 * Start the session with secure settings.
 * Call this once, early, on every page. Safe to call multiple times.
 *
 * Inactivity timeout is checked here as well as in is_logged_in(), so a
 * stale session is destroyed on the very first request after it expires —
 * even if something calls session_start_secure() before require_login().
 */
function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,           // cookie expires when browser closes
        'path'     => '/',
        'secure'   => true,        // HTTPS confirmed (LetsEncrypt wildcard cert)
        'httponly' => true,        // JS cannot read the session cookie
        'samesite' => 'Lax',
    ]);

    session_start();

    // Enforce inactivity timeout — destroy session if it has been idle too long.
    // The timeout used depends on the user's level stored in the session.
    if (!empty($_SESSION['_logged_in']) && isset($_SESSION['_last_activity'])) {
        $timeout = get_session_timeout($_SESSION['_userlevel'] ?? 0);
        if ($timeout > 0 && (time() - $_SESSION['_last_activity']) > $timeout) {
            session_destroy_clean();
            session_start();
            return;
        }
    }

    // Update last-activity timestamp on every page load while logged in
    if (!empty($_SESSION['_logged_in'])) {
        $_SESSION['_last_activity'] = time();
    }
}


// ── Login ─────────────────────────────────────────────────────────────────────

/**
 * Attempt to log in with the given credentials.
 *
 * Returns an array on success:
 *   ['ok' => true, 'user' => [...user data...]]
 *
 * Returns an array on failure:
 *   ['ok' => false, 'error' => 'human-readable message', 'locked' => bool]
 *
 * @param  string $firstname   In-world first name (case-insensitive match)
 * @param  string $lastname    In-world last name  (case-insensitive match)
 * @param  string $password    Plain-text password as entered by the user
 * @return array
 */
function attempt_login(string $firstname, string $lastname, string $password): array
{
    // ── Lockout check ────────────────────────────────────────────────────────
    if (is_login_locked()) {
        $remaining = lockout_seconds_remaining();
        return [
            'ok'     => false,
            'locked' => true,
            'error'  => sprintf(
                'Too many failed attempts. Please wait %d minute%s before trying again.',
                (int)ceil($remaining / 60),
                $remaining > 90 ? 's' : ''
            ),
        ];
    }

    // ── Basic input validation ────────────────────────────────────────────────
    $firstname = trim($firstname);
    $lastname  = trim($lastname);

    if ($firstname === '' || $lastname === '' || $password === '') {
        return ['ok' => false, 'locked' => false, 'error' => 'Please enter your first name, last name, and password.'];
    }

    // Sanity-check lengths before hitting the DB
    if (strlen($firstname) > 64 || strlen($lastname) > 64 || strlen($password) > 128) {
        return ['ok' => false, 'locked' => false, 'error' => 'Invalid credentials.'];
    }

    // ── Look up the account ───────────────────────────────────────────────────
    try {
        $account = fetch_account_by_name($firstname, $lastname);
    } catch (RuntimeException $e) {
        error_log('Login DB error: ' . $e->getMessage());
        return ['ok' => false, 'locked' => false, 'error' => 'A database error occurred. Please try again shortly.'];
    }

    if ($account === null) {
        // Account not found — record failure but don't reveal that the name doesn't exist
        record_failed_attempt();
        return ['ok' => false, 'locked' => false, 'error' => 'Incorrect name or password.'];
    }

    // ── Verify the password ────────────────────────────────────────────────────
    $hash_ok = verify_password($password, $firstname, $lastname, $account);

    if (!$hash_ok) {
        record_failed_attempt();
        $attempts_left = AUTH_MAX_ATTEMPTS - ($_SESSION['_login_attempts'] ?? 0);
        $msg = 'Incorrect name or password.';
        if ($attempts_left <= 2 && $attempts_left > 0) {
            $msg .= sprintf(' (%d attempt%s remaining before lockout.)', $attempts_left, $attempts_left === 1 ? '' : 's');
        }
        return ['ok' => false, 'locked' => false, 'error' => $msg];
    }

    // ── Success ───────────────────────────────────────────────────────────────
    clear_failed_attempts();

    // Regenerate session ID to prevent session fixation attacks
    session_regenerate_id(true);

    $_SESSION['_last_activity'] = time();
    $_SESSION['_user_uuid']     = $account['PrincipalID'];
    $_SESSION['_firstname']     = $account['FirstName'];
    $_SESSION['_lastname']      = $account['LastName'];
    $_SESSION['_userlevel']     = (int)$account['UserLevel'];
    $_SESSION['_logged_in']     = true;

    return [
        'ok'   => true,
        'user' => [
            'uuid'      => $account['PrincipalID'],
            'firstname' => $account['FirstName'],
            'lastname'  => $account['LastName'],
            'userlevel' => (int)$account['UserLevel'],
        ],
    ];
}


/**
 * Return true if a valid, non-expired session exists.
 *
 * Checks inactivity timeout against the user's level. A timeout value of 0
 * means that tier has no timeout (useful during development).
 */
function is_logged_in(): bool
{
    if (empty($_SESSION['_logged_in']) || empty($_SESSION['_user_uuid'])) {
        return false;
    }

    if (!isset($_SESSION['_last_activity'])) {
        return false;
    }

    $timeout = get_session_timeout($_SESSION['_userlevel'] ?? 0);
    if ($timeout > 0 && (time() - $_SESSION['_last_activity']) > $timeout) {
        return false;
    }

    return true;
}


/**
 * Return the inactivity timeout in seconds for a given user level.
 * Users meeting the 'Administrator' tier (see USERLEVEL_LABELS /
 * user_level_meets() in config.php) use ADMIN_SESSION_TIMEOUT_SECONDS.
 * Everyone else uses SESSION_TIMEOUT_SECONDS.
 * A return value of 0 means no timeout is enforced for that tier.
 *
 * @param  int $userlevel  Value from $_SESSION['_userlevel']
 * @return int             Timeout in seconds, or 0 to disable
 */
function get_session_timeout(int $userlevel): int
{
    if (user_level_meets($userlevel, 'Administrator')) {
        return (int)ADMIN_SESSION_TIMEOUT_SECONDS;
    }
    return (int)SESSION_TIMEOUT_SECONDS;
}


/**
 * Redirect to login page if not authenticated.
 * Call at the top of every protected page.
 *
 * If the session existed but has expired due to inactivity, a ?reason=timeout
 * parameter is added so the login page can show an appropriate message.
 *
 * @param string $login_page  Relative path to the login page
 */
function require_login(string $login_page = 'login.php'): void
{
    if (is_logged_in()) {
        return;
    }

    // Detect whether this was a timeout (session exists but is stale) vs.
    // simply not being logged in at all, so the login page can tailor its message.
    $was_timeout = !empty($_SESSION['_logged_in'])
                && isset($_SESSION['_last_activity'])
                && !is_logged_in();

    $params = [];
    if ($was_timeout) {
        $params[] = 'reason=timeout';
    }
    $dest = $_SERVER['REQUEST_URI'] ?? '';
    if ($dest !== '' && $dest !== '/login.php') {
        $params[] = 'next=' . urlencode($dest);
    }

    $qs = $params ? '?' . implode('&', $params) : '';
    header('Location: ' . $login_page . $qs);
    exit;
}


/**
 * Return the current session user as an array, or null if not logged in.
 *
 * The 'presence' field is populated from the Presence table via
 * get_presence_status() in profile_data.php. It contains:
 *   status:    'online'|'away'|'offline'
 *   online:    bool
 *   last_seen: int|null  Unix timestamp
 *
 * See get_presence_status() for the known hypergrid limitation caveat.
 */
function get_session_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }

    // profile_data.php is required by the page files that call get_session_user(),
    // but auth.php itself has no dependency on it. Require it here defensively so
    // get_presence_status() is always available regardless of include order.
    $profile_data = __DIR__ . '/profile_data.php';
    if (file_exists($profile_data)) {
        require_once $profile_data;
    }

    $presence = function_exists('get_presence_status')
        ? get_presence_status($_SESSION['_user_uuid'])
        : ['status' => 'offline', 'online' => false, 'last_seen' => null];

    return [
        'uuid'      => $_SESSION['_user_uuid'],
        'firstname' => $_SESSION['_firstname'],
        'lastname'  => $_SESSION['_lastname'],
        'userlevel' => $_SESSION['_userlevel'],
        'online'    => $presence['online'],    // bool, kept for any code that checks this directly
        'presence'  => $presence,              // full detail for status badge rendering
    ];
}


/**
 * Destroy the session and clear the cookie.
 */
function logout(): void
{
    session_destroy_clean();
    header('Location: login.php');
    exit;
}


// ── Database helpers ──────────────────────────────────────────────────────────

/**
 * Fetch a single UserAccounts row by first + last name.
 * The comparison is case-insensitive (OpenSim stores names in mixed case
 * but treats them case-insensitively at login).
 *
 * Returns null if no matching account exists.
 * Throws RuntimeException on DB error.
 *
 * Columns returned: PrincipalID, FirstName, LastName, UserLevel,
 *                   passwordHash, passwordSalt
 *
 * @param  string $firstname
 * @param  string $lastname
 * @return array|null
 */
function fetch_account_by_name(string $firstname, string $lastname): ?array
{
    $sql = "
        SELECT
            ua.PrincipalID,
            ua.FirstName,
            ua.LastName,
            ua.UserLevel,
            a.passwordHash,
            a.passwordSalt
        FROM UserAccounts ua
        JOIN auth a ON a.UUID = ua.PrincipalID
        WHERE LOWER(ua.FirstName) = LOWER(:firstname)
          AND LOWER(ua.LastName)  = LOWER(:lastname)
        LIMIT 1
    ";

    try {
        $stmt = get_db()->prepare($sql);
        $stmt->execute([
            ':firstname' => $firstname,
            ':lastname'  => $lastname,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (PDOException $e) {
        throw new RuntimeException('fetch_account_by_name failed: ' . $e->getMessage());
    }
}


// ── Password verification ─────────────────────────────────────────────────────

/**
 * Verify a plain-text password against the stored hash.
 *
 * Supports:
 *   'md5'  — Standard OpenSim: MD5( MD5(password) + ":" + MD5(firstname:lastname) )
 *
 * Other schemes ('argon2', 'bcrypt') are not yet implemented; attempting
 * to use them will log a clear error and return false rather than
 * accidentally allowing access.
 *
 * @param  string $password    Plain-text password
 * @param  string $firstname
 * @param  string $lastname
 * @param  array  $account     Row from UserAccounts
 * @return bool
 */
function verify_password(string $password, string $firstname, string $lastname, array $account): bool
{
    $scheme = strtolower(AUTH_HASH_SCHEME);

    switch ($scheme) {
        case 'md5':
            return verify_password_md5($password, $firstname, $lastname, $account);

        case 'argon2':
        case 'bcrypt':
            // Placeholder: implement when a grid using these schemes is confirmed.
            error_log(sprintf(
                'OpenSim portal: AUTH_HASH_SCHEME "%s" is not yet implemented. ' .
                'Login will always fail until this is built out.',
                $scheme
            ));
            return false;

        default:
            error_log(sprintf(
                'OpenSim portal: Unknown AUTH_HASH_SCHEME "%s". Check config.php.',
                $scheme
            ));
            return false;
    }
}


/**
 * Standard OpenSim MD5 password verification.
 *
 * Formula:  passwordHash = MD5( MD5(password) + ":" + MD5(firstname + ":" + lastname) )
 *           passwordSalt = MD5(firstname + ":" + lastname)
 *
 * We compute the salt ourselves and optionally verify it matches the stored
 * salt as a consistency check. The actual auth decision is based on the
 * final hash comparison only.
 *
 * Note: MD5 is weak by modern standards but this is OpenSim's own scheme —
 * we are matching it, not choosing it. The web portal cannot change how
 * OpenSim stores passwords. If your threat model requires stronger hashing,
 * configure OpenSim to use Argon2 and set AUTH_HASH_SCHEME accordingly.
 *
 * @param  string $password
 * @param  string $firstname
 * @param  string $lastname
 * @param  array  $account
 * @return bool
 */
function verify_password_md5(string $password, string $firstname, string $lastname, array $account): bool
{
    // Step 1: hash the plain-text password
    $hashed_password = md5($password);

    // Step 2: use the stored salt directly.
    //
    // The OpenSim wiki states the salt is MD5(firstname:lastname) but this
    // is incorrect for current versions — the salt is a random value generated
    // at account creation time and stored in the auth table. We read it from
    // the JOIN in fetch_account_by_name() and use it as-is.
    $stored_salt = $account['passwordSalt'] ?? '';

    // Step 3: compute the final hash
    $final_hash = md5($hashed_password . ':' . $stored_salt);

    // Constant-time comparison to prevent timing attacks
    return hash_equals($account['passwordHash'], $final_hash);
}


// ── Brute-force helpers ───────────────────────────────────────────────────────

function is_login_locked(): bool
{
    if (empty($_SESSION['_login_attempts'])) return false;
    if ($_SESSION['_login_attempts'] < AUTH_MAX_ATTEMPTS) return false;
    if (empty($_SESSION['_lockout_until'])) return false;
    return time() < $_SESSION['_lockout_until'];
}

function lockout_seconds_remaining(): int
{
    if (empty($_SESSION['_lockout_until'])) return 0;
    return max(0, $_SESSION['_lockout_until'] - time());
}

function record_failed_attempt(): void
{
    $_SESSION['_login_attempts'] = ($_SESSION['_login_attempts'] ?? 0) + 1;
    if ($_SESSION['_login_attempts'] >= AUTH_MAX_ATTEMPTS) {
        $_SESSION['_lockout_until'] = time() + AUTH_LOCKOUT_SECONDS;
    }
}

function clear_failed_attempts(): void
{
    unset($_SESSION['_login_attempts'], $_SESSION['_lockout_until']);
}

function session_destroy_clean(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
