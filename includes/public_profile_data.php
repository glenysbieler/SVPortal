<?php
/**
 * public_profile_data.php — Unauthenticated public profile data endpoint
 *
 * Two modes, selected by the `action` GET parameter:
 *
 *   action=search&q=<term>
 *     Returns a list of users whose names match the search term,
 *     filtered to only those with portal_prefs.public_profile = 1.
 *     Only local grid users are searched (no hypergrid).
 *     Returns up to MAX_SEARCH_RESULTS results.
 *
 *   action=profile&uuid=<UUID>
 *     Returns the full profile + picks for a single user.
 *     Rejected if that user does not have public_profile = 1.
 *
 * Both modes require PUBLIC_PROFILES = true in config.php.
 * No session or CSRF is required — this endpoint is intentionally public.
 * Sensitive fields (email, userlevel, password hash) are never returned.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_data.php';
require_once __DIR__ . '/assets.php';

header('Content-Type: application/json; charset=utf-8');
// Prevent browsers caching search results (stale results would be confusing)
header('Cache-Control: no-store');

// ─── Guard: PUBLIC_PROFILES must be enabled ───────────────────────────────────
if (!defined('PUBLIC_PROFILES') || !PUBLIC_PROFILES) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Public profiles are not enabled on this grid.']);
    exit;
}

// ─── Dispatch ─────────────────────────────────────────────────────────────────
$action = trim($_GET['action'] ?? '');

match ($action) {
    'search'  => handle_search(),
    'profile' => handle_profile(),
    default   => bad_request('Missing or invalid action.'),
};

// ─── Search handler ───────────────────────────────────────────────────────────
function handle_search(): void
{
    $q = trim($_GET['q'] ?? '');

    if (strlen($q) < 2) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    // Sanitise: only allow characters that could appear in an OpenSim name
    // (letters, digits, spaces, hyphens, apostrophes)
    if (!preg_match('/^[\p{L}\p{N}\s\'\-\.]+$/u', $q)) {
        echo json_encode(['ok' => true, 'results' => []]);
        exit;
    }

    try {
        $db = get_db();

        // Join UserAccounts with portal_prefs to filter to public-only.
        // System accounts (UserLevel < 0) are excluded.
        // Split search term across first+last name with LIKE on each half,
        // OR match against the concatenated full name.
        $term = '%' . $q . '%';

        $stmt = $db->prepare("
            SELECT
                ua.PrincipalID  AS uuid,
                ua.FirstName    AS firstname,
                ua.LastName     AS lastname
            FROM UserAccounts ua
            INNER JOIN portal_prefs pp
                ON pp.uuid = ua.PrincipalID
                AND pp.public_profile = 1
            WHERE
                ua.UserLevel >= 0
                AND (
                    ua.FirstName  LIKE :t1
                    OR ua.LastName   LIKE :t2
                    OR CONCAT(ua.FirstName, ' ', ua.LastName) LIKE :t3
                )
            ORDER BY ua.FirstName ASC, ua.LastName ASC
            LIMIT :lim
        ");

        $stmt->bindValue(':t1',  $term,                        \PDO::PARAM_STR);
        $stmt->bindValue(':t2',  $term,                        \PDO::PARAM_STR);
        $stmt->bindValue(':t3',  $term,                        \PDO::PARAM_STR);
        $stmt->bindValue(':lim', 20,                           \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        $results = array_map(fn($r) => [
            'uuid'     => $r['uuid'],
            'fullname' => trim($r['firstname'] . ' ' . $r['lastname']),
        ], $rows);

        echo json_encode(['ok' => true, 'results' => $results]);

    } catch (\Throwable $e) {
        error_log('public_profile_data search error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Search failed. Please try again.']);
    }
}

// ─── Profile fetch handler ────────────────────────────────────────────────────
function handle_profile(): void
{
    $uuid = trim($_GET['uuid'] ?? '');

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        bad_request('Invalid or missing UUID.');
    }

    try {
        $db = get_db();

        // Verify this user has opted in to public profiles
        $stmt = $db->prepare('SELECT public_profile FROM portal_prefs WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();

        if (!$row || !(bool)(int)$row['public_profile']) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'No public profile was found at this address.']);
            exit;
        }

        // Fetch profile and picks
        $profile = get_user_profile($uuid);
        $picks   = get_user_picks($uuid);

        // Resolve partner name
        $partner_name = null;
        $partner_link = null;
        $partner_uuid = $profile['partner_uuid'] ?? '00000000-0000-0000-0000-000000000000';

        if ($partner_uuid !== '00000000-0000-0000-0000-000000000000') {
            $stmt = $db->prepare('SELECT FirstName, LastName FROM UserAccounts WHERE PrincipalID = ? LIMIT 1');
            $stmt->execute([$partner_uuid]);
            $prow = $stmt->fetch();
            if ($prow) {
                $partner_name = trim($prow['FirstName'] . ' ' . $prow['LastName']);
            }

            // Link to partner's public profile only if they've also opted in
            $stmt = $db->prepare('SELECT 1 FROM portal_prefs WHERE uuid = ? AND public_profile = 1 LIMIT 1');
            $stmt->execute([$partner_uuid]);
            if ($stmt->fetch()) {
                $partner_link = 'publicprofile.php?view=' . $partner_uuid;
            }
        }

        // Safe output — never expose email, userlevel, etc.
        $out_profile = [
            'uuid'         => $profile['uuid'],
            'fullname'     => trim($profile['firstname'] . ' ' . $profile['lastname']),
            'about'        => $profile['about_text'],
            'created'      => (int)$profile['created'],
            'image_url'    => get_profile_image_url($profile['profile_image_uuid']),
            'partner_uuid' => $partner_uuid,
            'partner_name' => $partner_name,
            'partner_link' => $partner_link,
        ];

        $out_picks = array_map(fn(array $p): array => [
            'name'        => $p['name'],
            'description' => $p['description'],
            'image_url'   => get_pick_image_url($p['image_uuid']),
            'sim_name'    => $p['sim_name'] ?? '',
            'pos_x'       => (int)$p['pos_x'],
            'pos_y'       => (int)$p['pos_y'],
            'pos_z'       => (int)$p['pos_z'],
            'is_blank'    => (bool)$p['is_blank'],
        ], $picks);

        echo json_encode([
            'ok'      => true,
            'profile' => $out_profile,
            'picks'   => $out_picks,
        ]);

    } catch (\Throwable $e) {
        error_log('public_profile_data profile error for ' . $uuid . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Could not load profile. Please try again.']);
    }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function bad_request(string $msg): never
{
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
