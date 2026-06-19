<?php
/**
 * password_policy.php — Password complexity validation
 *
 * Provides a single validate_password() function used anywhere a new password
 * is accepted: change_password.php and the future registration page.
 *
 * Policy is controlled entirely by constants in config.php (PW_* prefix),
 * so grid operators can adjust the rules without touching this file.
 *
 * ── Return convention ────────────────────────────────────────────────────────
 *
 *   validate_password(string $password): array
 *
 *   On success:  ['valid' => true,  'errors' => []]
 *   On failure:  ['valid' => false, 'errors' => ['Must be at least 10 characters', ...]]
 *
 * Multiple errors are returned at once so the UI can show everything that
 * needs fixing rather than revealing requirements one at a time.
 *
 * ── Client-side mirror ───────────────────────────────────────────────────────
 *
 * get_password_policy_js_config() returns a JSON-safe array of the active
 * policy so the page can embed it and mirror the same checks in JavaScript
 * without duplicating the thresholds. This means the JS checklist always
 * reflects config.php — changing a constant updates both server and client.
 */

declare(strict_types=1);

// config.php must already be loaded by the page before this file is required.


/**
 * Validate a plain-text password against the configured policy.
 *
 * @param  string $password  The candidate password (plain text, not hashed)
 * @return array{valid: bool, errors: string[]}
 */
function validate_password(string $password): array
{
    $errors = [];

    // ── Length ────────────────────────────────────────────────────────────────
    $min = (int) PW_MIN_LENGTH;
    if (mb_strlen($password) < $min) {
        $errors[] = 'Must be at least ' . $min . ' character' . ($min === 1 ? '' : 's');
    }

    // ── Uppercase ─────────────────────────────────────────────────────────────
    if (PW_REQUIRE_UPPER && !preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Must contain at least one uppercase letter (A–Z)';
    }

    // ── Lowercase ─────────────────────────────────────────────────────────────
    if (PW_REQUIRE_LOWER && !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Must contain at least one lowercase letter (a–z)';
    }

    // ── Number ───────────────────────────────────────────────────────────────
    if (PW_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Must contain at least one number (0–9)';
    }

    // ── Symbol ───────────────────────────────────────────────────────────────
    if (PW_REQUIRE_SYMBOL && !preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Must contain at least one symbol (e.g. !@#$%^&*)';
    }

    return [
        'valid'  => empty($errors),
        'errors' => $errors,
    ];
}


/**
 * Returns the active policy as a plain array for embedding in a <script> block.
 *
 * Usage in a page:
 *   <script>
 *     const PW_POLICY = <?= json_encode(get_password_policy_js_config()) ?>;
 *   </script>
 *
 * The JavaScript UI uses this object to mirror the server-side checks, so
 * changing a constant in config.php automatically updates the client checklist.
 *
 * @return array<string, mixed>
 */
function get_password_policy_js_config(): array
{
    return [
        'minLength'     => (int)  PW_MIN_LENGTH,
        'requireUpper'  => (bool) PW_REQUIRE_UPPER,
        'requireLower'  => (bool) PW_REQUIRE_LOWER,
        'requireNumber' => (bool) PW_REQUIRE_NUMBER,
        'requireSymbol' => (bool) PW_REQUIRE_SYMBOL,
    ];
}
