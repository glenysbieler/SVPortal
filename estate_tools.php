<?php
/**
 * estate_tools.php — Estate Tools modal data + manager add/remove endpoint,
 * plus the "Change Owner" tool (Administrator tier)
 *
 * Backs the "Estate Tools" modal shared by regions.php (My Estates) and
 * all_estates.php (All Estates — Administrator tool), plus the
 * "Change Owner" modal unique to all_estates.php. Six actions, selected by
 * the `action` parameter:
 *
 *   GET  ?action=details&estate_id=<id>&csrf=<token>
 *     Returns the estate's name, owner (uuid + resolved name), the acting
 *     user's role for this estate ('owner'|'manager'), and the current
 *     manager roster (uuid + resolved name, alphabetical). Available to
 *     any owner or manager of the estate — see user_can_view_estate_tools()
 *     in includes/helpers.php.
 *
 *   GET  ?action=search_residents&estate_id=<id>&q=<term>&csrf=<token>
 *     Returns up to MAX_SEARCH_RESULTS local residents whose name matches
 *     the search term, for the "Add manager" typeahead. Unlike
 *     includes/public_profile_data.php's search (which only returns users
 *     who opted into public profiles), this searches ALL local residents —
 *     an estate owner needs to be able to add anyone as a manager, not just
 *     those with a public profile. Already-the-owner and already-a-manager
 *     results are excluded, so the dropdown only ever shows people who could
 *     actually be added. Owner-only (same gate as add/remove below) — there
 *     is no use for this action if you can't act on the result.
 *
 *   POST action=add_manager    (estate_id, uuid, csrf)
 *     Adds a resident as an estate manager. Owner-only.
 *
 *   POST action=remove_manager (estate_id, uuid, csrf)
 *     Removes a resident from the estate's manager list. Owner-only.
 *
 *   GET  ?action=search_residents_for_owner&estate_id=<id>&q=<term>&csrf=<token>
 *     Same search as search_residents above, but excludes only the CURRENT
 *     owner (not existing managers — a manager can legitimately become the
 *     new owner). Feeds the "Give Ownership to Someone Else" typeahead on
 *     the Change Owner modal (all_estates.php). Administrator-tier only —
 *     see user_can_change_estate_owner() in includes/helpers.php.
 *
 *   POST action=change_owner   (estate_id, csrf, AND EITHER uuid=<target uuid>
 *                                OR take_ownership=1)
 *     Sets estate_settings.EstateOwner for the given estate. Either:
 *       - uuid=<target>      — "Give Ownership to Someone Else": sets the
 *                               named resident as owner, or
 *       - take_ownership=1   — "Take Ownership": sets the ACTING user
 *                               (session_user) as owner.
 *     Exactly one of the two must be supplied. Administrator-tier only —
 *     deliberately NOT available to the estate's own current owner/managers,
 *     since this action redefines who that is. See
 *     user_can_change_estate_owner() / ENABLE_ESTATE_OWNER_TRANSFER below.
 *
 * Response shapes (JSON):
 *   details:                    { ok: true, estate: {...}, role: "owner"|"manager",
 *                                  canManage: bool, managers: [...] }
 *   search_residents:           { ok: true, results: [{ uuid, fullname }, ...] }
 *   add_manager:                { ok: true, managers: [...] }
 *   remove_manager:             { ok: true, managers: [...] }
 *   search_residents_for_owner: { ok: true, results: [{ uuid, fullname }, ...] }
 *   change_owner:                { ok: true, ownerUuid: "...", ownerName: "..." }
 *   any action on failure:       { ok: false, error: "..." }
 *
 * ── Access ───────────────────────────────────────────────────────────────
 * All actions require an active session. `details` and `search_residents`
 * additionally require user_can_view_estate_tools() (owner, manager, or
 * meeting the 'Administrator' tier); add_manager/remove_manager additionally
 * require user_can_manage_estate_managers() (owner, or meeting the
 * 'Administrator' tier — NOT managers, even of their own estate — see that
 * function's docblock in includes/helpers.php for why). `search_residents_for_owner`
 * and `change_owner` require user_can_change_estate_owner() — meeting the
 * 'Administrator' tier ONLY, never owner/manager (see that function's
 * docblock in includes/helpers.php). All actions also confirm the estate
 * itself exists before doing anything else.
 *
 * ── Write boundary exception (documented) ───────────────────────────────────
 * add_manager/remove_manager perform a direct INSERT/DELETE on
 * estate_managers, gated behind os_write_feature_enabled('ENABLE_ESTATE_MANAGER_EDIT').
 * change_owner performs a direct UPDATE on estate_settings.EstateOwner,
 * gated behind os_write_feature_enabled('ENABLE_ESTATE_OWNER_TRANSFER').
 * No XMLRPC/RemoteAdmin/console method exists for estate manager assignment
 * or ownership transfer on this grid — see config.php's
 * ENABLE_ESTATE_MANAGER_EDIT / ENABLE_ESTATE_OWNER_TRANSFER blocks and
 * includes/estates.php's docblock for the full reasoning (same pattern as
 * change_maturity.php for regionsettings.maturity). `details`,
 * `search_residents`, and `search_residents_for_owner` are read-only and
 * are NOT gated behind either flag — only the write actions are.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/estates.php';
require_once __DIR__ . '/includes/helpers.php';

// ─── Always return JSON ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ─── Require login ──────────────────────────────────────────────────────────
session_start_secure();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

$session_user = get_session_user();
$userlevel    = (int)($session_user['userlevel'] ?? 0);

// ─── Dispatch ─────────────────────────────────────────────────────────────────
$is_get_action = in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true);
$action        = trim($is_get_action ? ($_GET['action'] ?? '') : ($_POST['action'] ?? ''));

match ($action) {
    'details'                    => handle_details(),
    'search_residents'           => handle_search_residents(),
    'add_manager'                => handle_add_manager(),
    'remove_manager'             => handle_remove_manager(),
    'search_residents_for_owner' => handle_search_residents_for_owner(),
    'change_owner'               => handle_change_owner(),
    default                      => bad_request('Missing or invalid action.'),
};

// ─── details ──────────────────────────────────────────────────────────────────
function handle_details(): void
{
    global $session_user, $userlevel;

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        method_not_allowed();
    }
    check_csrf($_GET['csrf'] ?? '');

    $estate_id = parse_estate_id($_GET['estate_id'] ?? '');

    try {
        $db = get_db();

        $estate = get_estate_block_data($db, $estate_id);
        if ($estate === null) {
            not_found('Estate not found.');
        }

        if (!user_can_view_estate_tools($db, $session_user['uuid'], $userlevel, $estate_id)) {
            forbidden('You do not have access to this estate.');
        }

        $owner_name = resolve_resident_name($db, $estate['owner_uuid']);
        $is_owner    = user_is_estate_owner($db, $session_user['uuid'], $estate_id);

        echo json_encode([
            'ok'             => true,
            'estate'         => [
                'estate_id'  => $estate_id,
                'name'       => $estate['name'],
                'owner_uuid' => $estate['owner_uuid'],
                'owner_name' => $owner_name,
            ],
            'role'           => $is_owner ? 'owner' : 'manager',
            // Add/remove manager — matches user_can_manage_estate_managers()'s
            // tier exactly (owner, or meeting the 'Grid Staff' tier).
            'canManage'      => user_can_manage_estate_managers($db, $session_user['uuid'], $userlevel, $estate_id),
            // Change Owner (Take Ownership / Give Ownership) — Administrator
            // tier ONLY, never owner/manager. See user_can_change_estate_owner()'s
            // docblock (includes/helpers.php) for why this is deliberately
            // stricter than canManage above. Also requires the feature flag
            // to be enabled — see ENABLE_ESTATE_OWNER_TRANSFER, config.php —
            // so the UI doesn't invite an action the backend will refuse.
            'canChangeOwner' => user_can_change_estate_owner($userlevel)
                                && os_write_feature_enabled('ENABLE_ESTATE_OWNER_TRANSFER'),
            'managers'       => get_estate_managers($db, $estate_id),
        ]);

    } catch (Throwable $e) {
        server_error('estate_tools.php details error for estate ' . $estate_id, $e);
    }
}

// ─── search_residents ─────────────────────────────────────────────────────────
function handle_search_residents(): void
{
    global $session_user, $userlevel;

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        method_not_allowed();
    }
    check_csrf($_GET['csrf'] ?? '');

    $estate_id = parse_estate_id($_GET['estate_id'] ?? '');
    $q         = trim($_GET['q'] ?? '');

    if (strlen($q) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    // Same sanitisation as includes/public_profile_data.php's search — only
    // characters that could appear in an OpenSim name.
    if (!preg_match('/^[\p{L}\p{N}\s\'\-\.]+$/u', $q)) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    try {
        $db = get_db();

        $estate = get_estate_block_data($db, $estate_id);
        if ($estate === null) {
            not_found('Estate not found.');
        }

        // Owner-only — see file docblock. Reuses the same gate as
        // add_manager/remove_manager since this action only exists to feed
        // those two.
        if (!user_can_manage_estate_managers($db, $session_user['uuid'], $userlevel, $estate_id)) {
            forbidden('You do not have access to manage this estate.');
        }

        $existing_manager_uuids = array_column(get_estate_managers($db, $estate_id), 'uuid');
        $exclude_uuids           = array_merge([$estate['owner_uuid']], $existing_manager_uuids);

        $term = '%' . $q . '%';

        $stmt = $db->prepare("
            SELECT PrincipalID AS uuid, FirstName AS firstname, LastName AS lastname
            FROM   UserAccounts
            WHERE  UserLevel >= 0
              AND  (
                    FirstName LIKE :t1
                    OR LastName LIKE :t2
                    OR CONCAT(FirstName, ' ', LastName) LIKE :t3
              )
            ORDER BY FirstName ASC, LastName ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':t1',  $term, PDO::PARAM_STR);
        $stmt->bindValue(':t2',  $term, PDO::PARAM_STR);
        $stmt->bindValue(':t3',  $term, PDO::PARAM_STR);
        $stmt->bindValue(':lim', 20,    PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt as $row) {
            if (in_array($row['uuid'], $exclude_uuids, true)) {
                continue;
            }
            $results[] = [
                'uuid'     => $row['uuid'],
                'fullname' => trim($row['firstname'] . ' ' . $row['lastname']),
            ];
        }

        echo json_encode(['ok' => true, 'results' => $results]);

    } catch (Throwable $e) {
        server_error('estate_tools.php search_residents error for estate ' . $estate_id, $e);
    }
}

// ─── add_manager ──────────────────────────────────────────────────────────────
function handle_add_manager(): void
{
    global $session_user, $userlevel;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        method_not_allowed();
    }
    check_csrf($_POST['csrf'] ?? '');

    if (!os_write_feature_enabled('ENABLE_ESTATE_MANAGER_EDIT')) {
        forbidden('Editing estate managers is not enabled on this portal.');
    }

    $estate_id   = parse_estate_id($_POST['estate_id'] ?? '');
    $target_uuid = parse_resident_uuid($_POST['uuid'] ?? '');

    try {
        $db = get_db();

        $estate = get_estate_block_data($db, $estate_id);
        if ($estate === null) {
            not_found('Estate not found.');
        }

        if (!user_can_manage_estate_managers($db, $session_user['uuid'], $userlevel, $estate_id)) {
            forbidden('You do not have access to manage this estate.');
        }

        if ($target_uuid === $estate['owner_uuid']) {
            bad_request('The estate owner cannot also be added as a manager.');
        }

        // Confirm the target is a real, active local resident before adding —
        // estate_managers has no foreign key, so a typo'd or stale UUID would
        // otherwise insert silently with no name ever resolving for it.
        $stmt = $db->prepare('SELECT 1 FROM UserAccounts WHERE PrincipalID = ? AND UserLevel >= 0 LIMIT 1');
        $stmt->execute([$target_uuid]);
        if ($stmt->fetchColumn() === false) {
            bad_request('That resident could not be found.');
        }

        // Avoid duplicate rows — estate_managers has no unique constraint,
        // so check first rather than relying on the database to reject it.
        $stmt = $db->prepare('SELECT 1 FROM estate_managers WHERE EstateID = ? AND uuid = ? LIMIT 1');
        $stmt->execute([$estate_id, $target_uuid]);
        $already_manager = $stmt->fetchColumn() !== false;

        if (!$already_manager) {
            $stmt = $db->prepare('INSERT INTO estate_managers (EstateID, uuid) VALUES (?, ?)');
            $stmt->execute([$estate_id, $target_uuid]);

            log_estate_manager_action($db, $session_user['uuid'], 'estate_manager_added', $estate_id, $estate['name'], $target_uuid);
        }

        echo json_encode(['ok' => true, 'managers' => get_estate_managers($db, $estate_id)]);

    } catch (Throwable $e) {
        server_error('estate_tools.php add_manager error for estate ' . $estate_id, $e);
    }
}

// ─── remove_manager ────────────────────────────────────────────────────────────
function handle_remove_manager(): void
{
    global $session_user, $userlevel;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        method_not_allowed();
    }
    check_csrf($_POST['csrf'] ?? '');

    if (!os_write_feature_enabled('ENABLE_ESTATE_MANAGER_EDIT')) {
        forbidden('Editing estate managers is not enabled on this portal.');
    }

    $estate_id   = parse_estate_id($_POST['estate_id'] ?? '');
    $target_uuid = parse_resident_uuid($_POST['uuid'] ?? '');

    try {
        $db = get_db();

        $estate = get_estate_block_data($db, $estate_id);
        if ($estate === null) {
            not_found('Estate not found.');
        }

        if (!user_can_manage_estate_managers($db, $session_user['uuid'], $userlevel, $estate_id)) {
            forbidden('You do not have access to manage this estate.');
        }

        // No-op (rather than an error) if the target isn't actually a
        // manager of this estate — the roster returned afterwards reflects
        // the true current state regardless, so the caller's UI stays in
        // sync either way.
        $stmt = $db->prepare('DELETE FROM estate_managers WHERE EstateID = ? AND uuid = ?');
        $stmt->execute([$estate_id, $target_uuid]);

        if ($stmt->rowCount() > 0) {
            log_estate_manager_action($db, $session_user['uuid'], 'estate_manager_removed', $estate_id, $estate['name'], $target_uuid);
        }

        echo json_encode(['ok' => true, 'managers' => get_estate_managers($db, $estate_id)]);

    } catch (Throwable $e) {
        server_error('estate_tools.php remove_manager error for estate ' . $estate_id, $e);
    }
}

// ─── search_residents_for_owner ────────────────────────────────────────────────
function handle_search_residents_for_owner(): void
{
    global $session_user, $userlevel;

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        method_not_allowed();
    }
    check_csrf($_GET['csrf'] ?? '');

    $estate_id = parse_estate_id($_GET['estate_id'] ?? '');
    $q         = trim($_GET['q'] ?? '');

    if (strlen($q) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    // Same sanitisation as handle_search_residents() above — only characters
    // that could appear in an OpenSim name.
    if (!preg_match('/^[\p{L}\p{N}\s\'\-\.]+$/u', $q)) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    try {
        $db = get_db();

        $estate = get_estate_block_data($db, $estate_id);
        if ($estate === null) {
            not_found('Estate not found.');
        }

        if (!user_can_change_estate_owner($userlevel)) {
            forbidden('You do not have access to change this estate\'s owner.');
        }

        // Unlike handle_search_residents() (excludes the owner AND every
        // existing manager), this only excludes the CURRENT owner — a
        // manager, or anyone else, can legitimately become the new owner.
        $term = '%' . $q . '%';

        $stmt = $db->prepare("
            SELECT PrincipalID AS uuid, FirstName AS firstname, LastName AS lastname
            FROM   UserAccounts
            WHERE  UserLevel >= 0
              AND  (
                    FirstName LIKE :t1
                    OR LastName LIKE :t2
                    OR CONCAT(FirstName, ' ', LastName) LIKE :t3
              )
            ORDER BY FirstName ASC, LastName ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':t1',  $term, PDO::PARAM_STR);
        $stmt->bindValue(':t2',  $term, PDO::PARAM_STR);
        $stmt->bindValue(':t3',  $term, PDO::PARAM_STR);
        $stmt->bindValue(':lim', 20,    PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt as $row) {
            if ($row['uuid'] === $estate['owner_uuid']) {
                continue;
            }
            $results[] = [
                'uuid'     => $row['uuid'],
                'fullname' => trim($row['firstname'] . ' ' . $row['lastname']),
            ];
        }

        echo json_encode(['ok' => true, 'results' => $results]);

    } catch (Throwable $e) {
        server_error('estate_tools.php search_residents_for_owner error for estate ' . $estate_id, $e);
    }
}

// ─── change_owner ───────────────────────────────────────────────────────────────
function handle_change_owner(): void
{
    global $session_user, $userlevel;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        method_not_allowed();
    }
    check_csrf($_POST['csrf'] ?? '');

    if (!os_write_feature_enabled('ENABLE_ESTATE_OWNER_TRANSFER')) {
        forbidden('Changing estate ownership is not enabled on this portal.');
    }

    $estate_id      = parse_estate_id($_POST['estate_id'] ?? '');
    $take_ownership = ($_POST['take_ownership'] ?? '') === '1';

    // Exactly one of "take ownership" (acting user becomes owner) or a
    // target uuid (someone else becomes owner) must be supplied — this is
    // a single action with two distinct outcomes ("Take Ownership" /
    // "Give Ownership" on the Change Owner modal), not two separate
    // actions, since both perform the identical EstateOwner UPDATE.
    if ($take_ownership) {
        $new_owner_uuid = $session_user['uuid'];
    } else {
        $new_owner_uuid = parse_resident_uuid($_POST['uuid'] ?? '');
    }

    try {
        $db = get_db();

        $estate = get_estate_block_data($db, $estate_id);
        if ($estate === null) {
            not_found('Estate not found.');
        }

        // Administrator tier ONLY — see user_can_change_estate_owner()'s
        // docblock in includes/helpers.php for why this deliberately does
        // NOT also accept the estate's current owner/managers, unlike
        // user_can_manage_estate_managers() above.
        if (!user_can_change_estate_owner($userlevel)) {
            forbidden('You do not have access to change this estate\'s owner.');
        }

        if ($new_owner_uuid === $estate['owner_uuid']) {
            bad_request('That resident is already the owner of this estate.');
        }

        // Confirm the target is a real, active local resident before
        // assigning ownership — estate_settings.EstateOwner has no foreign
        // key, so a stale/invalid UUID would otherwise be written silently
        // with no name ever resolving for it. (When take_ownership=1, this
        // is always true for the acting session user, but checked
        // unconditionally anyway rather than special-casing that path.)
        $stmt = $db->prepare('SELECT 1 FROM UserAccounts WHERE PrincipalID = ? AND UserLevel >= 0 LIMIT 1');
        $stmt->execute([$new_owner_uuid]);
        if ($stmt->fetchColumn() === false) {
            bad_request('That resident could not be found.');
        }

        $stmt = $db->prepare('UPDATE estate_settings SET EstateOwner = ? WHERE EstateID = ?');
        $stmt->execute([$new_owner_uuid, $estate_id]);

        log_estate_manager_action(
            $db,
            $session_user['uuid'],
            $take_ownership ? 'estate_owner_taken' : 'estate_owner_changed',
            $estate_id,
            $estate['name'],
            $new_owner_uuid
        );

        echo json_encode([
            'ok'        => true,
            'ownerUuid' => $new_owner_uuid,
            'ownerName' => resolve_resident_name($db, $new_owner_uuid),
        ]);

    } catch (Throwable $e) {
        server_error('estate_tools.php change_owner error for estate ' . $estate_id, $e);
    }
}

// ─── Shared helpers ─────────────────────────────────────────────────────────────

/**
 * Resolve a UUID to a display name via UserAccounts. Returns 'Unowned' for
 * the null UUID (matching regions.php's existing convention), or the raw
 * UUID string if no matching account is found (shouldn't normally happen).
 */
function resolve_resident_name(PDO $db, string $uuid): string
{
    if ($uuid === NULL_UUID) {
        return 'Unowned';
    }
    $stmt = $db->prepare('SELECT FirstName, LastName FROM UserAccounts WHERE PrincipalID = ? LIMIT 1');
    $stmt->execute([$uuid]);
    $row = $stmt->fetch();
    if ($row === false) {
        return $uuid;
    }
    return trim($row['FirstName'] . ' ' . $row['LastName']);
}

/**
 * Write a portal_log entry for an estate manager add/remove, OR an estate
 * owner change (the $action string distinguishes which — e.g.
 * 'estate_manager_added', 'estate_owner_changed'). Name kept as-is for
 * minimal diff against the original manager add/remove implementation;
 * the function itself is generic (actor, action, estate, target) and has
 * no manager-specific assumptions. Non-critical — failures (e.g. table not
 * yet created) are swallowed silently, matching change_maturity.php's
 * existing pattern.
 */
function log_estate_manager_action(PDO $db, string $actor_uuid, string $action, int $estate_id, string $estate_name, string $target_uuid): void
{
    try {
        $stmt = $db->prepare(
            "INSERT INTO portal_log (actor_uuid, action, details, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $details = json_encode([
            'estate_id'    => $estate_id,
            'estate_name'  => $estate_name,
            'target_uuid'  => $target_uuid,
        ]);
        $stmt->execute([$actor_uuid, $action, $details]);
    } catch (Throwable) {
        // portal_log table not yet created — skip silently
    }
}

function check_csrf(string $supplied): void
{
    $session_csrf = $_SESSION['_csrf_token'] ?? '';
    if ($supplied === '' || !hash_equals($session_csrf, $supplied)) {
        forbidden('Security token mismatch.');
    }
}

function parse_estate_id(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '' || !ctype_digit($raw)) {
        bad_request('Invalid or missing estate ID.');
    }
    return (int)$raw;
}

function parse_resident_uuid(string $raw): string
{
    $raw = trim($raw);
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $raw)) {
        bad_request('Invalid or missing resident UUID.');
    }
    return $raw;
}

function bad_request(string $msg): never
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function forbidden(string $msg): never
{
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function not_found(string $msg): never
{
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function method_not_allowed(): never
{
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

function server_error(string $log_prefix, Throwable $e): never
{
    error_log($log_prefix . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again.']);
    exit;
}
