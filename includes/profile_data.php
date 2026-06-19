<?php
/**
 * profile_data.php — Real profile data from the OpenSim database
 *
 * Replaces the mock functions in mock_data.php.
 * All queries are SELECT only — no writes to the database.
 *
 * Functions:
 *   get_user_profile(string $uuid): array
 *   get_user_picks(string $uuid): array
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Returns full profile data for a given UUID.
 *
 * Joins UserAccounts (identity + created date) with userprofile
 * (about text, profile image, etc.) on PrincipalID / useruuid.
 *
 * The LEFT JOIN means we still get account data even if a userprofile
 * row doesn't exist yet (e.g. a brand new account that has never logged
 * in world).
 *
 * @param  string $uuid   PrincipalID from UserAccounts
 * @return array
 */
function get_user_profile(string $uuid): array
{
    $sql = "
        SELECT
            ua.PrincipalID       AS uuid,
            ua.FirstName         AS firstname,
            ua.LastName          AS lastname,
            ua.Email             AS email,
            ua.Created           AS created,
            ua.UserLevel         AS userlevel,
            ua.UserFlags         AS userflags,
            ua.UserTitle         AS usertitle,

            COALESCE(up.profileImage,     '00000000-0000-0000-0000-000000000000') AS profile_image_uuid,
            COALESCE(up.profileAboutText, '')                                     AS about_text,
            COALESCE(up.profileFirstImage,'00000000-0000-0000-0000-000000000000') AS fl_image_uuid,
            COALESCE(up.profileFirstText, '')                                     AS fl_about_text,
            COALESCE(up.profileURL,       '')                                     AS url,
            COALESCE(up.profilePartner,   '00000000-0000-0000-0000-000000000000') AS partner_uuid

        FROM UserAccounts ua
        LEFT JOIN userprofile up ON up.useruuid = ua.PrincipalID
        WHERE ua.PrincipalID = :uuid
        LIMIT 1
    ";

    try {
        $stmt = get_db()->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('get_user_profile failed: ' . $e->getMessage());
        // Return a safe empty profile rather than crashing the page
        return empty_profile($uuid);
    }

    if (!$row) {
        error_log('get_user_profile: no row found for UUID ' . $uuid);
        return empty_profile($uuid);
    }

    return $row;
}

/**
 * Returns the profile picks for a given UUID, ordered by sortorder.
 *
 * Only returns enabled picks (enabled = 'true').
 * posglobal is stored as "x/y/z" — we split it here so the template
 * doesn't need to parse it.
 *
 * @param  string $uuid   creatoruuid in userpicks
 * @return array          array of pick arrays, may be empty
 */
function get_user_picks(string $uuid): array
{
    $sql = "
        SELECT
            pickuuid,
            name,
            description,
            snapshotuuid  AS image_uuid,
            simname       AS sim_name,
            originalname,
            posglobal,
            sortorder,
            toppick
        FROM userpicks
        WHERE creatoruuid = :uuid
          AND enabled     = 'true'
        ORDER BY sortorder ASC, name ASC
    ";

    try {
        $stmt = get_db()->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('get_user_picks failed: ' . $e->getMessage());
        return [];
    }

    foreach ($rows as &$pick) {
        // ── Clean up the pick name ────────────────────────────────────────
        $name = trim($pick['name'] ?? '');
        if ($name === '' || $name === '*') {
            $name = trim($pick['originalname'] ?? '');
        }
        if ($name === '' || $name === '*') {
            $name = '';  // leave blank — caller decides how to display
        }
        $pick['name'] = $name;

        // ── Parse posglobal ───────────────────────────────────────────────
        // OpenSim stores posglobal as "<x, y, z>" — angle brackets with
        // comma-space separators. All three values are large absolute grid
        // coordinates. Convert X and Y to region-local with % 256.
        // Z is height above ground and is already region-local.
        $raw   = trim($pick['posglobal'] ?? '', " \t\n\r\0\x0B<>");
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $pick['pos_x'] = isset($parts[0]) ? (int)round((float)$parts[0]) % 256 : 0;
        $pick['pos_y'] = isset($parts[1]) ? (int)round((float)$parts[1]) % 256 : 0;
        $pick['pos_z'] = isset($parts[2]) ? (int)round((float)$parts[2])        : 0;
        unset($pick['posglobal'], $pick['originalname']);

        // ── Clean description ─────────────────────────────────────────────
        $pick['description'] = trim($pick['description'] ?? '');

        // ── Flag blank picks ──────────────────────────────────────────────
        // A pick is considered blank if it has no name AND no description.
        // The template uses this to hide/show them via the checkbox toggle.
        $pick['is_blank'] = ($pick['name'] === '' && $pick['description'] === '');
    }
    unset($pick);

    // ── Natural sort by name ──────────────────────────────────────────────
    // The user controls order via numbering in the pick name itself
    // (e.g. "1. Sub-Version Suburbs"). Natural sort ensures 1, 2, 10, 11
    // rather than 1, 10, 11, 2. Blank names sort last.
    usort($rows, function (array $a, array $b): int {
        return strnatcasecmp(
            $a['name'] !== '' ? $a['name'] : "\xFF",
            $b['name'] !== '' ? $b['name'] : "\xFF"
        );
    });

    return $rows;
}

/**
 * Returns presence status information for a given UUID.
 *
 * Queries the Presence table for the most recent active session row
 * where RegionID is not the null UUID. Returns an array describing
 * the three possible states:
 *
 *   'online'  — has a Presence row, LastSeen within PRESENCE_AWAY_THRESHOLD
 *   'away'    — has a Presence row, but LastSeen is older than the threshold
 *               (possible ghost session from an unacknowledged hypergrid logout)
 *   'offline' — no Presence row, or RegionID is the null UUID
 *
 * ── Known limitation ────────────────────────────────────────────────────────
 * This is best-effort only. OpenSim's presence tracking is unreliable for
 * hypergrid visitors: when a hypergrid user logs out on a remote grid, the
 * logout signal may never reach this grid's ROBUST service, leaving a stale
 * "online" row in the Presence table. The LastSeen timestamp helps surface
 * likely ghost sessions, but cannot definitively detect them. Always show
 * a disclaimer alongside any online/offline indicator.
 *
 * @param  string $uuid   PrincipalID from UserAccounts
 * @return array {
 *   status:    'online'|'away'|'offline'
 *   online:    bool   (true for both 'online' and 'away')
 *   last_seen: int|null   Unix timestamp of LastSeen, or null if offline
 * }
 */
function get_presence_status(string $uuid): array
{
    // Threshold in seconds after which an online session is considered "away"
    // (likely a ghost session from an unacknowledged hypergrid logout).
    $away_threshold = 1800; // 30 minutes — sessions older than this are shown as 'away'

    $sql = "
        SELECT
            LastSeen,
            UNIX_TIMESTAMP(LastSeen) AS last_seen_ts
        FROM Presence
        WHERE UserID   = :uuid
          AND RegionID != '00000000-0000-0000-0000-000000000000'
        ORDER BY LastSeen DESC
        LIMIT 1
    ";

    try {
        $stmt = get_db()->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        // If the query fails for any reason, default to offline rather than
        // showing incorrect online status.
        error_log('get_presence_status failed: ' . $e->getMessage());
        return ['status' => 'offline', 'online' => false, 'last_seen' => null];
    }

    if (!$row) {
        return ['status' => 'offline', 'online' => false, 'last_seen' => null];
    }

    $last_seen_ts = (int)$row['last_seen_ts'];
    $age_seconds  = time() - $last_seen_ts;

    if ($age_seconds <= $away_threshold) {
        $status = 'online';
    } else {
        $status = 'away';
    }

    return [
        'status'    => $status,
        'online'    => true,
        'last_seen' => $last_seen_ts,
    ];
}

/**
 * Returns every active session grid-wide, joined to the avatar's name and
 * current region. Grid-wide counterpart to get_presence_status() above —
 * same table, same 'online'/'away' threshold, same known limitation.
 *
 * Each row in the Presence table is one session (keyed by SessionID), so a
 * user logged in from two avatars (or, in principle, two simultaneous
 * sessions on the same avatar) produces two separate rows here — this is
 * "who's online", not "which accounts are online".
 *
 * ── Hypergrid visitors ─────────────────────────────────────────────────────
 * A hypergrid visitor gets a real Presence row (confirmed live: a plain
 * UUID, not a URI) but NEVER a UserAccounts row on this grid — per OpenSim's
 * HG design, the destination grid caches the foreign user in memory only
 * ("the remote opensim places an entry for that user in its local user
 * profile cache but not in its user database; the foreign user information
 * is non-persistent").
 *
 * Their name IS recoverable, though — not from UserAccounts, but from
 * GridUser, which (per OpenSim's docs) "also potentially holds information
 * for foreign users travelling when Hypergrid is enabled". On this grid's
 * build, GridUser.UserID for a HG visitor is a composite string:
 *   <uuid>;http://homegrid.example.com:8002/;First Last
 * (confirmed live — see Things_to_do.md / chat history for the captured
 * example). This function does a second targeted lookup against GridUser,
 * by UUID prefix, ONLY for UserIDs that didn't match UserAccounts — so a
 * grid with no HG visitors currently online never pays for the extra
 * query. If GridUser also has no match (visitor arrived in a way that
 * didn't populate this row, or an OpenSim version/config that formats it
 * differently), firstname/lastname fall back to null and the caller
 * should render an explicit "Hypergrid Visitor" placeholder — never sort
 * or group by that placeholder as if it were a real name.
 *
 * The home grid host (parsed from the same string) is also returned, since
 * it's useful context a local UserAccounts row never has.
 *
 * ── Known limitation (same as get_presence_status()) ─────────────────────
 * Hypergrid visitors whose logout signal never reached this grid's ROBUST
 * service leave a stale row behind. The 'away' status (LastSeen older than
 * $away_threshold) is a best-effort flag for likely-stale sessions, not a
 * definitive one — LastSeen only updates at login and at region crossings,
 * so a user who has stayed in one region for a long time can show as
 * 'away' while genuinely still online. Always pair this with the same
 * disclaimer used for individual presence (see build_presence_display()).
 *
 * RegionID is also not cross-checked against whether that region is
 * actually still running (e.g. a simulator crash can leave Presence rows
 * behind for a region that jsonSimStats would now report offline). Callers
 * wanting that guarantee would need to cross-reference region_status.php's
 * check separately; not done here to avoid an unbounded number of HTTP
 * calls for a single page load.
 *
 * @return array<int, array{
 *   uuid:          string,
 *   firstname:     string|null,  // null only if neither UserAccounts nor GridUser resolved a name
 *   lastname:      string|null,  // null only if neither UserAccounts nor GridUser resolved a name
 *   is_hypergrid:  bool,
 *   home_grid:     string|null,  // hypergrid visitors only — host[:port] of their home grid, if parsed
 *   region_uuid:   string,
 *   region_name:   string|null,  // null if the region row no longer exists
 *   status:        'online'|'away',
 *   last_seen:     int           // Unix timestamp
 * }>
 */
function get_all_online_users(): array
{
    // Kept identical to get_presence_status()'s threshold so a user's status
    // here always matches what they'd see on their own profile.
    $away_threshold = 1800; // 30 minutes

    // LEFT JOIN (not JOIN) is deliberate — see "Hypergrid visitors" above.
    // An inner join here is exactly the bug that caused HG visitors to
    // vanish from this list entirely: their UserID has no matching
    // UserAccounts row, so an inner join drops the Presence row outright.
    // ORDER BY pushes unmatched (HG) rows after all named rows, without
    // sorting them as if "null name" were an alphabetical value.
    $sql = "
        SELECT
            p.UserID        AS uuid,
            ua.FirstName     AS firstname,
            ua.LastName      AS lastname,
            p.RegionID       AS region_uuid,
            r.regionName     AS region_name,
            UNIX_TIMESTAMP(p.LastSeen) AS last_seen_ts
        FROM Presence p
        LEFT JOIN UserAccounts ua ON ua.PrincipalID = p.UserID
        LEFT JOIN regions r       ON r.uuid = p.RegionID
        WHERE p.RegionID != '00000000-0000-0000-0000-000000000000'
        ORDER BY (ua.PrincipalID IS NULL), ua.FirstName, ua.LastName
    ";

    try {
        $rows = get_db()->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log('get_all_online_users failed: ' . $e->getMessage());
        return [];
    }

    // ── Second pass: resolve names for unmatched (hypergrid) UUIDs via
    // GridUser. Only runs the extra query if there's actually anyone to
    // resolve, so a grid with no HG visitors online pays nothing extra.
    $unmatched_uuids = [];
    foreach ($rows as $row) {
        if ($row['firstname'] === null && $row['lastname'] === null) {
            $unmatched_uuids[] = $row['uuid'];
        }
    }

    $hg_names = []; // uuid => ['name' => string|null, 'home_grid' => string|null]
    if (!empty($unmatched_uuids)) {
        try {
            $db = get_db();
            $like_clauses = implode(' OR ', array_fill(0, count($unmatched_uuids), 'UserID LIKE ?'));
            $stmt = $db->prepare("SELECT UserID FROM GridUser WHERE {$like_clauses}");
            $stmt->execute(array_map(fn(string $uuid): string => $uuid . '%', $unmatched_uuids));

            foreach ($stmt->fetchAll() as $gu_row) {
                // Format: <uuid>;http://homegrid.example.com:8002/;First Last
                $parts = explode(';', $gu_row['UserID']);
                $uuid  = $parts[0] ?? '';
                if ($uuid === '') {
                    continue;
                }

                $home_grid = null;
                if (!empty($parts[1])) {
                    $parsed_url = parse_url(trim($parts[1]));
                    if (!empty($parsed_url['host'])) {
                        $home_grid = $parsed_url['host'] . (!empty($parsed_url['port']) ? ':' . $parsed_url['port'] : '');
                    }
                }

                $name = isset($parts[2]) ? trim($parts[2]) : null;

                $hg_names[$uuid] = ['name' => $name !== '' ? $name : null, 'home_grid' => $home_grid];
            }
        } catch (PDOException $e) {
            // Non-fatal — fall back to the "Hypergrid Visitor" placeholder
            // for any UUID this lookup didn't resolve.
            error_log('get_all_online_users: GridUser name lookup failed: ' . $e->getMessage());
        }
    }

    $now = time();
    $out = [];
    foreach ($rows as $row) {
        $last_seen_ts = (int)$row['last_seen_ts'];
        $is_hypergrid = $row['firstname'] === null && $row['lastname'] === null;

        $firstname = $row['firstname'];
        $lastname  = $row['lastname'];
        $home_grid = null;

        if ($is_hypergrid && isset($hg_names[$row['uuid']])) {
            $hg_info   = $hg_names[$row['uuid']];
            $home_grid = $hg_info['home_grid'];
            if ($hg_info['name'] !== null) {
                // GridUser stores "First Last" as one string — split on the
                // first space only, so a multi-word last name (rare, but HG
                // visitors can come from grids with looser naming) stays intact.
                $space_pos = strpos($hg_info['name'], ' ');
                if ($space_pos !== false) {
                    $firstname = substr($hg_info['name'], 0, $space_pos);
                    $lastname  = substr($hg_info['name'], $space_pos + 1);
                } else {
                    $firstname = $hg_info['name'];
                    $lastname  = '';
                }
            }
        }

        $out[] = [
            'uuid'         => $row['uuid'],
            'firstname'    => $firstname,
            'lastname'     => $lastname,
            'is_hypergrid' => $is_hypergrid,
            'home_grid'    => $home_grid,
            'region_uuid'  => $row['region_uuid'],
            'region_name'  => $row['region_name'], // may be null — region row not found
            'status'       => ($now - $last_seen_ts) <= $away_threshold ? 'online' : 'away',
            'last_seen'    => $last_seen_ts,
        ];
    }

    return $out;
}

/**
 * Returns the display name of a partner UUID, or null if none / not found.
 *
 * Returns null when:
 *   - partner_uuid is the null UUID (00000000-…)
 *   - the UUID does not match any UserAccounts row
 *
 * @param  string $partner_uuid   profilePartner from userprofile
 * @return string|null
 */
function get_partner_name(string $partner_uuid): ?string
{
    if ($partner_uuid === '00000000-0000-0000-0000-000000000000' || $partner_uuid === '') {
        return null;
    }

    try {
        $stmt = get_db()->prepare(
            'SELECT FirstName, LastName FROM UserAccounts WHERE PrincipalID = ? LIMIT 1'
        );
        $stmt->execute([$partner_uuid]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('get_partner_name failed: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }

    return trim($row['FirstName'] . ' ' . $row['LastName']);
}

/**
 * Returns a URL to link a partner's name to, or null if no safe link target exists.
 *
 * Preference order:
 *   1. friends.php?view=<partner_uuid>  — if partner_uuid is still in viewer's Friends list
 *   2. publicprofile.php?view=<partner_uuid> — if PUBLIC_PROFILES is enabled and the partner
 *      has opted in via portal_prefs.public_profile = 1
 *   3. null — no link (plain text), avoids dead links
 *
 * @param  string $viewer_uuid   UUID of the logged-in user viewing the page
 * @param  string $partner_uuid  profilePartner UUID to link to
 * @return string|null
 */
function get_partner_link_url(string $viewer_uuid, string $partner_uuid): ?string
{
    if ($partner_uuid === '00000000-0000-0000-0000-000000000000' || $partner_uuid === '') {
        return null;
    }

    try {
        $db = get_db();

        // Preference 1: partner is still a local friend
        $stmt = $db->prepare(
            'SELECT 1 FROM Friends WHERE PrincipalID = ? AND Friend = ? LIMIT 1'
        );
        $stmt->execute([$viewer_uuid, $partner_uuid]);
        if ($stmt->fetch()) {
            return 'friends.php?view=' . $partner_uuid;
        }

        // Preference 2: partner has a public profile
        if (defined('PUBLIC_PROFILES') && PUBLIC_PROFILES) {
            $stmt = $db->prepare(
                'SELECT 1 FROM portal_prefs WHERE uuid = ? AND public_profile = 1 LIMIT 1'
            );
            $stmt->execute([$partner_uuid]);
            if ($stmt->fetch()) {
                return 'publicprofile.php?view=' . $partner_uuid;
            }
        }
    } catch (PDOException $e) {
        error_log('get_partner_link_url failed: ' . $e->getMessage());
    }

    return null;
}

/**
 * Returns a safe empty profile array when a DB lookup fails or finds nothing.
 *
 * @param  string $uuid
 * @return array
 */
function empty_profile(string $uuid): array
{
    return [
        'uuid'               => $uuid,
        'firstname'          => 'Unknown',
        'lastname'           => 'User',
        'email'              => '',
        'created'            => 0,
        'userlevel'          => 0,
        'userflags'          => 0,
        'usertitle'          => '',
        'profile_image_uuid' => '00000000-0000-0000-0000-000000000000',
        'about_text'         => '',
        'fl_image_uuid'      => '00000000-0000-0000-0000-000000000000',
        'fl_about_text'      => '',
        'url'                => '',
        'partner_uuid'       => '00000000-0000-0000-0000-000000000000',
    ];
}
