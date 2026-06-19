<?php
/**
 * includes/estates.php — Estate / region data helpers
 *
 * Provides the data layer for "My Estates" (regions.php) and, in future,
 * the Administrator "All Estates" tool. All queries are SELECT-only against
 * OpenSim-owned tables (estate_settings, estate_managers, estate_map,
 * regions) — no writes happen here.
 *
 * ── Access model ────────────────────────────────────────────────────────────
 *
 * Estate ownership/management in OpenSim is an assignment, NOT tied to
 * UserLevel — a UserLevel-0 resident can be an estate owner or manager.
 * "My Estates" visibility (drawer item + page access) is therefore based
 * entirely on whether the user's UUID appears as:
 *
 *   - estate_settings.EstateOwner = uuid   (Owner), or
 *   - estate_managers.uuid = uuid          (Manager)
 *
 * for at least one estate. An estate with EstateOwner = the null UUID
 * (00000000-0000-0000-0000-000000000000) is never matched as an "owner"
 * estate for anyone.
 *
 * ── Reuse for future Administrator "All Estates" tool ─────────────────────
 *
 * get_estate_block_data() (singular, one EstateID -> block data) is the
 * unit the admin tool will reuse to render "every estate on the grid"
 * regardless of ownership. get_estates_for_user() simply filters down to
 * the estates relevant to one user and groups them by role. get_all_estates()
 * is the "every estate, no filtering" counterpart used by the admin tool
 * itself (all_estates.php).
 *
 * ── Estate ownership transfer (Administrator tier only) ───────────────────
 *
 * OpenSim does not give Administrators ("Grid Gods") implicit estate access —
 * estate_settings.EstateOwner / estate_managers are the only access records,
 * and UserLevel doesn't factor in. The "Change Owner" tool on all_estates.php
 * lets a user meeting the 'Administrator' tier directly overwrite
 * estate_settings.EstateOwner (either to themselves, or to any resident they
 * pick) — see user_can_change_estate_owner() below and estate_tools.php's
 * change_owner action. No XMLRPC/RemoteAdmin/console method exists for
 * estate ownership transfer on this grid, so a direct UPDATE is the only
 * available mechanism — same reasoning as ENABLE_ESTATE_MANAGER_EDIT and
 * ENABLE_CHANGE_MATURITY. Gated behind ENABLE_ESTATE_OWNER_TRANSFER
 * (config.php) and, deliberately, the 'Administrator' tier ONLY — never
 * estate owner/manager, since by definition this changes who that is.
 */

declare(strict_types=1);

const NULL_UUID = '00000000-0000-0000-0000-000000000000';

/**
 * Does this user have estate owner/manager access to ANY estate?
 * Used to decide whether to show the "My Estates" drawer item and
 * whether to allow access to regions.php at all.
 *
 * @param  PDO    $db   Database connection
 * @param  string $uuid User's PrincipalID
 * @return bool
 */
function user_has_estate_access(PDO $db, string $uuid): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM estate_settings WHERE EstateOwner = ? AND EstateOwner <> ? LIMIT 1'
    );
    $stmt->execute([$uuid, NULL_UUID]);
    if ($stmt->fetchColumn() !== false) {
        return true;
    }

    $stmt = $db->prepare('SELECT 1 FROM estate_managers WHERE uuid = ? LIMIT 1');
    $stmt->execute([$uuid]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Does this user own or manage the estate that a SPECIFIC region belongs to?
 *
 * Used to gate per-region AJAX endpoints (e.g. region_status.php) so a user
 * can only query regions within estates they have access to — mirroring the
 * access model for regions.php itself, but scoped to one region.
 *
 * @param  PDO    $db          Database connection
 * @param  string $uuid        User's PrincipalID
 * @param  string $region_uuid regions.uuid of the region being checked
 * @return bool
 */
function user_has_estate_access_to_region(PDO $db, string $uuid, string $region_uuid): bool
{
    $stmt = $db->prepare(
        'SELECT 1
         FROM   estate_map em
         JOIN   estate_settings es ON es.EstateID = em.EstateID
         WHERE  em.RegionID = ?
           AND  (
                 (es.EstateOwner = ? AND es.EstateOwner <> ?)
                 OR EXISTS (
                     SELECT 1 FROM estate_managers emg
                     WHERE emg.EstateID = es.EstateID AND emg.uuid = ?
                 )
           )
         LIMIT 1'
    );
    $stmt->execute([$region_uuid, $uuid, NULL_UUID, $uuid]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Build the "block" data for a single estate: its settings plus all
 * regions mapped to it (via estate_map -> regions).
 *
 * This is the reusable unit for both "My Estates" (regions.php) and the
 * future Administrator "All Estates" tool — the admin tool will call this
 * for every EstateID on the grid rather than just the user's own.
 *
 * @param  PDO $db        Database connection
 * @param  int $estate_id EstateID from estate_settings
 * @return array{
 *     estate_id: int,
 *     name: string,
 *     owner_uuid: string,
 *     regions: array<int, array{
 *         uuid: string,
 *         name: string,
 *         map_texture: string,
 *         locX: int,
 *         locY: int,
 *         sizeX: int,
 *         sizeY: int,
 *         serverPort: int,
 *         maturity: int
 *     }>
 * }|null  Null if the estate does not exist.
 */
function get_estate_block_data(PDO $db, int $estate_id): ?array
{
    $stmt = $db->prepare(
        'SELECT EstateID, EstateName, EstateOwner FROM estate_settings WHERE EstateID = ?'
    );
    $stmt->execute([$estate_id]);
    $estate = $stmt->fetch();

    if ($estate === false) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT r.uuid, r.regionName, r.regionMapTexture, r.locX, r.locY, r.sizeX, r.sizeY,
                r.serverPort, rs.maturity
         FROM   estate_map em
         JOIN   regions r ON r.uuid = em.RegionID
         LEFT JOIN regionsettings rs ON rs.regionUUID = r.uuid
         WHERE  em.EstateID = ?
         ORDER BY r.regionName'
    );
    $stmt->execute([$estate_id]);

    $regions = [];
    foreach ($stmt as $row) {
        $regions[] = [
            'uuid'        => $row['uuid'],
            'name'        => $row['regionName'],
            'map_texture' => $row['regionMapTexture'] ?: NULL_UUID,
            'locX'        => (int)$row['locX'],
            'locY'        => (int)$row['locY'],
            'sizeX'       => (int)$row['sizeX'],
            'sizeY'       => (int)$row['sizeY'],
            'serverPort'  => (int)$row['serverPort'],
            // regionsettings.maturity (0/1/2) — NOT regions.access. See
            // region_maturity_label()'s docblock in includes/helpers.php for
            // why this distinction matters. LEFT JOIN + null coalesce to 0
            // (General) guards against a region with no regionsettings row
            // yet (shouldn't normally happen, but fails safe rather than
            // throwing if it does).
            'maturity'    => (int)($row['maturity'] ?? 0),
        ];
    }

    return [
        'estate_id'  => (int)$estate['EstateID'],
        'name'       => (string)($estate['EstateName'] ?? ('Estate ' . $estate['EstateID'])),
        'owner_uuid' => (string)$estate['EstateOwner'],
        'regions'    => $regions,
    ];
}

/**
 * Get all estates this user has access to, grouped and ordered for
 * "My Estates" display: estates the user OWNS first, then estates the
 * user MANAGES (but does not own), each in EstateName order.
 *
 * Each entry in the returned arrays is the result of get_estate_block_data(),
 * with an added 'role' key: 'owner' or 'manager'.
 *
 * @param  PDO    $db   Database connection
 * @param  string $uuid User's PrincipalID
 * @return array<int, array>  Estate blocks, owner estates first then manager estates
 */
function get_estates_for_user(PDO $db, string $uuid): array
{
    $owner_ids   = [];
    $manager_ids = [];

    // Estates owned by this user
    $stmt = $db->prepare(
        'SELECT EstateID FROM estate_settings
         WHERE EstateOwner = ? AND EstateOwner <> ?
         ORDER BY EstateName'
    );
    $stmt->execute([$uuid, NULL_UUID]);
    foreach ($stmt as $row) {
        $owner_ids[] = (int)$row['EstateID'];
    }

    // Estates this user manages (excluding ones already listed as owner)
    $stmt = $db->prepare(
        'SELECT em.EstateID
         FROM   estate_managers em
         JOIN   estate_settings es ON es.EstateID = em.EstateID
         WHERE  em.uuid = ?
         ORDER BY es.EstateName'
    );
    $stmt->execute([$uuid]);
    foreach ($stmt as $row) {
        $eid = (int)$row['EstateID'];
        if (!in_array($eid, $owner_ids, true)) {
            $manager_ids[] = $eid;
        }
    }

    $result = [];

    foreach ($owner_ids as $eid) {
        $block = get_estate_block_data($db, $eid);
        if ($block !== null) {
            $block['role'] = 'owner';
            $result[]      = $block;
        }
    }

    foreach ($manager_ids as $eid) {
        $block = get_estate_block_data($db, $eid);
        if ($block !== null) {
            $block['role'] = 'manager';
            $result[]      = $block;
        }
    }

    return $result;
}

/**
 * Get every estate on the grid, regardless of ownership/management — for
 * the Administrator "All Estates" tool (all_estates.php). Unlike
 * get_estates_for_user(), this is NOT scoped to any particular user's
 * estate access — callers MUST gate access to this function's results
 * behind meeting the 'Administrator' tier (see USERLEVEL_LABELS /
 * user_level_meets() in config.php), since OpenSim does not give
 * Administrators implicit estate access of its own; the portal's
 * Administrator tier is what grants visibility here, not anything in
 * estate_settings/estate_managers itself.
 *
 * Each entry is the result of get_estate_block_data(), in EstateName order.
 * No 'role' key is added (unlike get_estates_for_user()) since "role" is
 * meaningless here — the admin may own/manage none, some, or all of the
 * estates returned.
 *
 * @param  PDO $db  Database connection
 * @return array<int, array>  Every estate block on the grid, ordered by EstateName
 */
function get_all_estates(PDO $db): array
{
    $stmt = $db->query('SELECT EstateID FROM estate_settings ORDER BY EstateName');

    $result = [];
    foreach ($stmt as $row) {
        $block = get_estate_block_data($db, (int)$row['EstateID']);
        if ($block !== null) {
            $result[] = $block;
        }
    }

    return $result;
}

/**
 * Get every region on the grid, for the Administrator Console page's region
 * selector (console.php). Unlike get_estates_for_user(), this is NOT scoped
 * to any particular user's estate access — callers MUST gate access to this
 * function's results behind meeting the 'Administrator' tier (see
 * USERLEVEL_LABELS / user_level_meets() in config.php), since the
 * Console page grants full, unrestricted simulator console access to
 * whichever region is selected.
 *
 * @param  PDO $db  Database connection
 * @return array<int, array{uuid: string, name: string, serverPort: int}>
 *         Ordered by region name.
 */
function get_all_regions_for_console(PDO $db): array
{
    $stmt = $db->query('SELECT uuid, regionName, serverPort FROM regions ORDER BY regionName');

    $regions = [];
    foreach ($stmt as $row) {
        $regions[] = [
            'uuid'       => $row['uuid'],
            'name'       => $row['regionName'],
            'serverPort' => (int)$row['serverPort'],
        ];
    }

    return $regions;
}

// ─── Estate Tools: manager list + ownership checks ─────────────────────────
//
// Supports the "Estate Tools" modal on regions.php — viewing the estate's
// owner + manager roster is open to anyone with owner/manager access to
// THAT estate (user_is_estate_owner_or_manager() below); adding/removing
// managers is restricted to the estate owner only (user_is_estate_owner()).
// The actual writes to estate_managers happen in estate_tools.php, which is
// the documented ALLOW_OS_DATABASE_WRITES exception for this feature — see
// config.php ("OpenSim database write exceptions") and CLAUDE.md Hard Rule 1.
// No XMLRPC/RemoteAdmin/console method exists for estate manager assignment
// on this grid (confirmed against RestConsole.md / ConsoleAccess.md /
// remoteadmin.php — RemoteAdmin only exposes region-level operations), so a
// direct write is the only available mechanism, same reasoning as
// change_maturity.php for regionsettings.maturity.

/**
 * Is this user the OWNER (not just a manager) of the given estate?
 *
 * Strict owner-only check — used to gate add/remove-manager actions, which
 * managers must NOT be able to perform on themselves or on each other.
 * NULL_UUID owners never match (an estate with no real owner has no one
 * who can pass this check, by design — mirrors user_has_estate_access()).
 *
 * @param  PDO    $db        Database connection
 * @param  string $uuid      User's PrincipalID
 * @param  int    $estate_id EstateID from estate_settings
 * @return bool
 */
function user_is_estate_owner(PDO $db, string $uuid, int $estate_id): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM estate_settings
         WHERE EstateID = ? AND EstateOwner = ? AND EstateOwner <> ?
         LIMIT 1'
    );
    $stmt->execute([$estate_id, $uuid, NULL_UUID]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Does this user own OR manage the given estate?
 *
 * Used to gate visibility of the Estate Tools modal's contents — any owner
 * or manager of an estate may VIEW its details and manager roster, but only
 * the owner may edit it (see user_is_estate_owner() above).
 *
 * @param  PDO    $db        Database connection
 * @param  string $uuid      User's PrincipalID
 * @param  int    $estate_id EstateID from estate_settings
 * @return bool
 */
function user_is_estate_owner_or_manager(PDO $db, string $uuid, int $estate_id): bool
{
    if (user_is_estate_owner($db, $uuid, $estate_id)) {
        return true;
    }

    $stmt = $db->prepare('SELECT 1 FROM estate_managers WHERE EstateID = ? AND uuid = ? LIMIT 1');
    $stmt->execute([$estate_id, $uuid]);
    return $stmt->fetchColumn() !== false;
}

/**
 * Get the current manager roster for an estate, with display names resolved.
 *
 * Returns an empty array if the estate has no managers (a perfectly normal
 * state — many estates only have an owner). Ordered by first+last name so
 * the list reads alphabetically rather than in arbitrary insertion order.
 *
 * Rows with no matching UserAccounts entry (shouldn't normally happen, but
 * an estate_managers row could in theory outlive the account it points to)
 * are skipped rather than shown with a blank name.
 *
 * @param  PDO $db        Database connection
 * @param  int $estate_id EstateID from estate_settings
 * @return array<int, array{uuid: string, name: string}>
 */
function get_estate_managers(PDO $db, int $estate_id): array
{
    $stmt = $db->prepare(
        'SELECT em.uuid AS uuid, ua.FirstName AS firstname, ua.LastName AS lastname
         FROM   estate_managers em
         JOIN   UserAccounts ua ON ua.PrincipalID = em.uuid
         WHERE  em.EstateID = ?
         ORDER BY ua.FirstName ASC, ua.LastName ASC'
    );
    $stmt->execute([$estate_id]);

    $managers = [];
    foreach ($stmt as $row) {
        $managers[] = [
            'uuid' => $row['uuid'],
            'name' => trim($row['firstname'] . ' ' . $row['lastname']),
        ];
    }

    return $managers;
}

