<?php
/**
 * robust_api.php — ROBUST UserManipulation REST client
 *
 * Provides PHP functions that wrap calls to the ROBUST service's
 * UserManipulation REST API. All mutations to OpenSim user accounts
 * must go through this file — never via direct database writes.
 *
 * Reference: http://opensimulator.org/wiki/UserManipulation
 *
 * ── How the API works ────────────────────────────────────────────────────────
 * Despite what the wiki implies about "XMLRPC calls", this API is a plain
 * HTTP REST endpoint. Requests are form-encoded POSTs; a METHOD field in
 * the body selects the operation. Responses are XML.
 *
 *   Endpoint:  POST http://ROBUST_HOST:ROBUST_PORT/accounts
 *   Method:    setaccount   (note: NOT set_account — underscore variant fails)
 *   Identity:  PrincipalID (UUID) — required; firstname/lastname are optional
 *
 *   Success response:  <ServerResponse><result type="List">...</result></ServerResponse>
 *   Failure response:  <ServerResponse><result>Failure</result></ServerResponse>
 *
 * Password changes use a separate endpoint:
 *   Endpoint:  POST http://ROBUST_HOST:ROBUST_PORT/auth/plain
 *   Method:    setpassword
 *   Params:    PRINCIPAL=<uuid>  PASSWORD=<new-password>
 *
 * ROBUST.HG.ini must have in [UserAccountService]:
 *   AllowCreateUser = true
 *   AllowSetAccount = true
 *
 * And for password changes, in [AuthenticationService]:
 *   AllowSetPassword = true
 *
 * ── Return convention ────────────────────────────────────────────────────────
 * All public functions return:
 *   ['success' => true]                     on success
 *   ['success' => false, 'error' => '...']  on failure
 *
 * Error strings are safe to display to the user.
 * Detailed error context is written to the PHP error log.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// ─── Endpoints ────────────────────────────────────────────────────────────────

function robust_accounts_url(): string
{
    return 'http://' . ROBUST_HOST . ':' . ROBUST_PRIVATE_PORT . '/accounts';
}

function robust_auth_url(): string
{
    return 'http://' . ROBUST_HOST . ':' . ROBUST_PRIVATE_PORT . '/auth/plain';
}

// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Updates the email address on a ROBUST user account.
 *
 * Uses METHOD=setaccount with PrincipalID + Email.
 * The account is identified by UUID — firstname/lastname are not required.
 *
 * @param  string $uuid       PrincipalID from UserAccounts
 * @param  string $new_email  The new email address to set
 * @return array  ['success' => true] or ['success' => false, 'error' => '...']
 */
function robust_set_user_email(string $uuid, string $new_email): array
{
    return robust_accounts_post([
        'METHOD'      => 'setaccount',
        'PrincipalID' => $uuid,
        'Email'       => $new_email,
    ]);
}

/**
 * Changes a user's password via the ROBUST authentication service.
 *
 * Uses METHOD=setpassword on the /auth/plain endpoint.
     * The PASSWORD parameter must be the PLAIN TEXT password. ROBUST generates a new
     * random salt internally and stores MD5(MD5(password)+":"+newSalt).
     * Do NOT pre-hash the password before passing to this function.
 *
 * Note: AllowSetPassword = true must be set in [AuthenticationService]
 * in Robust.HG.ini for this call to succeed.
 *
 * @param  string $uuid          PrincipalID from UserAccounts
 * @param  string $password_hash Pre-hashed password (OpenSim double-MD5 format)
 * @return array  ['success' => true] or ['success' => false, 'error' => '...']
 */
function robust_set_user_password(string $uuid, string $password_hash): array
{
    return robust_post(robust_auth_url(), [
        'METHOD'    => 'setpassword',
        'PRINCIPAL' => $uuid,
        'PASSWORD'  => $password_hash,
    ]);
}

/**
 * Creates a new OpenSim user account via the ROBUST UserManipulation REST API.
 *
 * Uses METHOD=createuser on the /accounts endpoint. ROBUST creates both the
 * UserAccounts row and the auth row, generating its own salt and storing
 * MD5(MD5(password)+":"+newSalt) internally — PASSWORD must be plain text,
 * exactly like robust_set_user_password().
 *
 * Parameter names (FirstName/LastName/Password/Email/ScopeID) match
 * UserAccountServicesConnector.CreateUser() in OpenSim core — NOT the
 * shorter First/Last sometimes seen in older wiki examples, which the
 * server-side handler does not recognise (defaults them to empty strings
 * and fails with a bare <result>Failure</result>).
 *
 * If $starter_avatar is non-empty, it is passed through as the Model
 * parameter (matching the "model" argument of the "create user" console
 * command). OpenSim copies the named starter avatar's inventory/appearance
 * to the new account during creation. Leave empty to create a "blank" avatar
 * with no starting inventory/appearance.
 *
 * CONFIRMED WORKING (live test, June 2026): Model is NOT in any published
 * /accounts parameter list (the wiki's UserManipulation page only documents
 * FirstName/LastName/Password/Email/PrincipalID/ScopeID), but the server-side
 * handler accepts and acts on it identically to the console command's model
 * argument — almost certainly because both front doors call into the same
 * underlying CreateUser service method internally. Verified by watching the
 * ROBUST console log during a raw test POST: the same
 * "Created user inventory for ..." / "Establishing new appearance for ..."
 * / per-item "Added item ... to folder ..." / "Attached ..." sequence fired
 * exactly as it does for the console command, and the resulting account
 * logged in and rendered correctly in-viewer (no cloud/grey bake, correct
 * worn items, independent inventory copies — not references back to the
 * template). $starter_avatar must be the template's exact full
 * "Firstname Lastname" (a name string, not a UUID) — same value already
 * used as the STARTER_AVATARS array key. See CLAUDE.md for the full
 * test writeup.
 *
 * Note: this call does NOT return the new account's PrincipalID — the XML
 * response on success is just <result type="List"> with no useful payload
 * (FirstName/LastName/Email/PrincipalID/ScopeID/Created/UserLevel/UserFlags/
 * LocalToGrid/ServiceURLs — confirmed via live test; Model is accepted but
 * never echoed back, so a "success" response alone does not confirm Model
 * was honoured — only the console log / in-viewer check does). Callers that
 * need the new UUID must look it up afterwards via UserAccounts (SELECT
 * only) by FirstName + LastName.
 *
 * Requires AllowCreateUser = true in [UserAccountService] in Robust.HG.ini.
 *
 * @param  string $firstname       New account's first name
 * @param  string $lastname        New account's last name
 * @param  string $password        Plain text password — ROBUST hashes internally
 * @param  string $email           Email address (may be empty string)
 * @param  string $starter_avatar  Starter avatar/model name, or '' for none
 * @return array  ['success' => true] or ['success' => false, 'error' => '...']
 */
function robust_create_user(
    string $firstname,
    string $lastname,
    string $password,
    string $email = '',
    string $starter_avatar = ''
): array {
    $params = [
        'METHOD'    => 'createuser',
        'FirstName' => $firstname,
        'LastName'  => $lastname,
        'Password'  => $password,
        'Email'     => $email,
        'ScopeID'   => '00000000-0000-0000-0000-000000000000',
    ];

    if ($starter_avatar !== '') {
        $params['Model'] = $starter_avatar;
    }

    return robust_accounts_post($params);
}

// ─── Internal transport ───────────────────────────────────────────────────────

/**
 * POSTs to the /accounts endpoint and parses the response.
 */
function robust_accounts_post(array $params): array
{
    return robust_post(robust_accounts_url(), $params);
}

/**
 * Sends a form-encoded POST to a ROBUST endpoint and parses the XML response.
 *
 * Success:  response contains <result type="List">
 * Failure:  response contains <result>Failure</result>
 *
 * @param  string $url     Full endpoint URL
 * @param  array  $params  Form parameters (will be URL-encoded)
 * @return array  ['success' => true] or ['success' => false, 'error' => '...']
 */
function robust_post(string $url, array $params): array
{
    $body = http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_FAILONERROR    => false,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    $method = $params['METHOD'] ?? '?';

    if ($curl_err !== '') {
        error_log("robust_post({$method}): cURL error: {$curl_err}");
        return ['success' => false, 'error' => 'Could not connect to the grid service. Please try again later.'];
    }

    if ($http_code !== 200) {
        error_log("robust_post({$method}): HTTP {$http_code}");
        return ['success' => false, 'error' => 'The grid service returned an unexpected response. Please try again later.'];
    }

    if (!$response) {
        error_log("robust_post({$method}): empty response");
        return ['success' => false, 'error' => 'The grid service returned no response. Please try again later.'];
    }

    return robust_parse_response($method, $response);
}

/**
 * Parses a ROBUST XML response.
 *
 * Success:  <ServerResponse><result type="List">...</result></ServerResponse>
 * Failure:  <ServerResponse><result>Failure</result></ServerResponse>
 *
 * The /auth/plain endpoint uses a different casing:
 *   <ServerResponse><Result>Success</Result></ServerResponse>
 *
 * @param  string $method    Operation name (for error log context only)
 * @param  string $response  Raw XML body
 * @return array
 */
function robust_parse_response(string $method, string $response): array
{
    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($response);
    libxml_use_internal_errors($prev);

    if ($xml === false) {
        error_log("robust_parse_response({$method}): XML parse failed. Body: " . substr($response, 0, 300));
        return ['success' => false, 'error' => 'The grid service returned an unreadable response.'];
    }

    // Check for <result type="List"> — this is the success indicator
    // for /accounts endpoint calls
    $result = $xml->result ?? $xml->Result ?? null;

    if ($result === null) {
        error_log("robust_parse_response({$method}): no <result> element. Body: " . substr($response, 0, 300));
        return ['success' => false, 'error' => 'Unexpected response from grid service.'];
    }

    // Success: result element has type="List" attribute
    $type = (string)($result->attributes()['type'] ?? '');
    if ($type === 'List') {
        return ['success' => true];
    }

    // Success: /auth/plain returns <Result>Success</Result> (capital S, no attribute)
    $val = strtolower(trim((string)$result));
    if ($val === 'success') {
        return ['success' => true];
    }

    // Anything else is failure
    error_log("robust_parse_response({$method}): result='{$val}'. Full body: " . substr($response, 0, 300));
    return ['success' => false, 'error' => 'The grid service was unable to apply this change. Please try again.'];
}
