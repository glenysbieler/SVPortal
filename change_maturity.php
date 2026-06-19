<?php
/**
 * change_maturity.php — Region maturity change endpoint
 *
 * Changes a region's maturity rating (General/Moderate/Adult) from the
 * maturity picker modal on the region detail modal (regions.php).
 *
 * ── Which field is correct: regionsettings.maturity, NOT regions.access ────
 *
 * The OpenSim DB has TWO fields that both look like "the" maturity setting:
 *
 *   - regions.access        — OpenSim's SimAccess enum (13/21/42)
 *   - regionsettings.maturity — a simple 0/1/2 index
 *
 * This endpoint was originally built against regions.access (it correlates
 * with maturity and is documented as such on the OpenSim wiki), but live
 * testing against Sub-Version Suburbs (Castle) proved that's the WRONG
 * field to write to: a direct UPDATE on regions.access took effect
 * immediately and matched both the portal display and... right up until
 * the region was restarted, at which point it silently reverted to the old
 * value with no warning and no trace in the OpenSim log. regionsettings.
 * maturity, by contrast, survived a restart unchanged, and is the field the
 * in-world Region/Estate dialog itself writes to.
 *
 * Current understanding (not fully proven, but consistent with everything
 * observed): regionsettings.maturity is the authoritative input, and
 * regions.access is a derived value the simulator recalculates from it —
 * most likely during region registration with ROBUST on startup, which is
 * exactly why a regions.access-only write reverted on the next restart but
 * not before it. This endpoint therefore writes ONLY to regionsettings.
 * maturity. regions.access is left alone — it should sort itself out the
 * next time the region restarts and re-registers, same as it always has.
 *
 * Do NOT switch this back to regions.access without re-confirming against a
 * live region first — see Things_to_do.md for the full investigation.
 *
 * ── Write boundary exception (documented) ───────────────────────────────────
 *
 * UPDATE on regionsettings.maturity. A working alternative DOES exist —
 * the one-shot REST console command `region set maturity 0|1|2` (confirmed
 * via `help region set` showing it as a real, supported parameter once we
 * knew to look for "maturity" rather than assuming `agent-limit` /
 * `max-agent-limit` were the only options) — but a direct DB write was
 * deliberately chosen instead, to avoid making this feature depend on the
 * one-shot REST console being enabled and working for the target region
 * (only confirmed working on one region grid-wide at the time of writing).
 * Gated behind the master switch ALLOW_OS_DATABASE_WRITES and the feature
 * flag ENABLE_CHANGE_MATURITY — see config.php and os_write_feature_enabled()
 * in includes/helpers.php.
 *
 * ── Restart required (NOT triggered here) ───────────────────────────────────
 *
 * This endpoint only writes the new value to regionsettings.maturity. Per
 * the investigation above, regions.access (used by ROBUST/grid services,
 * search, map, etc.) does not pick up the change until the region next
 * restarts and re-registers. This endpoint does NOT call
 * remoteadmin_restart_region() or otherwise trigger a restart; the
 * maturity picker modal carries a static warning instead, and restarting
 * remains a fully separate, deliberate action via the existing "Restart
 * region" button / region_restart.php.
 *
 * Request:
 *   POST change_maturity.php
 *     uuid     = <region UUID>
 *     csrf     = <token>
 *     maturity = "0" | "1" | "2"   (regionsettings.maturity value — see
 *                                   region_maturity_options() in
 *                                   includes/helpers.php)
 *
 * Response (JSON):
 *   { "ok": true, "maturity": 1, "maturityLabel": "Moderate" }
 *   { "ok": false, "error": "..." }
 *
 * ── Access ─────────────────────────────────────────────────────────────────
 * Requires an active session AND user_can_manage_region_maturity() —
 * estate owner, estate manager, OR meeting the 'Grid Staff' tier. See
 * includes/helpers.php. The UserLevel branch is what will let
 * the future Administrator "All Estates" tool reuse this same endpoint
 * unchanged — see Things_to_do.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/estates.php';
require_once __DIR__ . '/includes/helpers.php';

// ─── Always return JSON ─────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ─── Require login ──────────────────────────────────────────────────────────
session_start_secure();

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}

// ─── Method check ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ─── Feature flag (master switch + feature flag) ───────────────────────────
if (!os_write_feature_enabled('ENABLE_CHANGE_MATURITY')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Changing region maturity is not enabled on this portal.']);
    exit;
}

// ─── CSRF check ─────────────────────────────────────────────────────────────
$supplied_csrf = trim($_POST['csrf'] ?? '');
$session_csrf  = $_SESSION['_csrf_token'] ?? '';

if ($supplied_csrf === '' || !hash_equals($session_csrf, $supplied_csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
    exit;
}

// ─── Validate UUID ──────────────────────────────────────────────────────────
$region_uuid = trim($_POST['uuid'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $region_uuid)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing region UUID.']);
    exit;
}

// ─── Validate maturity value ────────────────────────────────────────────────
// Only the three known regionsettings.maturity values (0/1/2) are accepted —
// reject anything else rather than passing arbitrary client input through
// to an UPDATE statement. NOTE: these are NOT regions.access SimAccess
// values (13/21/42) — see file docblock above.
$maturity_options = region_maturity_options();
$maturity_raw      = trim((string)($_POST['maturity'] ?? ''));
$maturity           = ctype_digit($maturity_raw) ? (int)$maturity_raw : null;

if ($maturity === null || !array_key_exists($maturity, $maturity_options)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid maturity value.']);
    exit;
}

try {
    $db           = get_db();
    $session_user = get_session_user();

    // ─── Access check ────────────────────────────────────────────────────
    $can_manage = user_can_manage_region_maturity(
        $db,
        $session_user['uuid'],
        (int)($session_user['userlevel'] ?? 0),
        $region_uuid
    );

    if (!$can_manage) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'You do not have access to this region.']);
        exit;
    }

    // ─── Confirm the region exists, and read its current maturity ────────
    // regionsettings is keyed by regionUUID (== regions.uuid). Confirm the
    // region itself exists via `regions` (for the name, used in the log
    // entry below) and LEFT JOIN regionsettings since a region should
    // always have a settings row, but fail safe rather than assuming.
    $stmt = $db->prepare(
        'SELECT r.regionName, rs.maturity
         FROM   regions r
         LEFT JOIN regionsettings rs ON rs.regionUUID = r.uuid
         WHERE  r.uuid = ?
         LIMIT 1'
    );
    $stmt->execute([$region_uuid]);
    $region = $stmt->fetch();

    if ($region === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Region not found.']);
        exit;
    }

    $current_maturity = $region['maturity'] !== null ? (int)$region['maturity'] : null;

    // No-op if the requested value matches the current one — still a
    // success from the caller's point of view, just nothing to write.
    if ($current_maturity === $maturity) {
        echo json_encode([
            'ok'            => true,
            'maturity'      => $maturity,
            'maturityLabel' => $maturity_options[$maturity],
        ]);
        exit;
    }

    // ─── Write boundary exception: UPDATE on regionsettings.maturity ─────
    // See file docblock above — this is a documented, deliberate exception
    // gated by ALLOW_OS_DATABASE_WRITES. If the region has no regionsettings
    // row at all (current_maturity === null), this UPDATE will affect 0
    // rows — checked via rowCount() below rather than assumed successful,
    // since an earlier version of this endpoint had exactly that class of
    // bug (reported ok:true without confirming a row was actually changed).
    $stmt = $db->prepare('UPDATE regionsettings SET maturity = ? WHERE regionUUID = ?');
    $stmt->execute([$maturity, $region_uuid]);

    if ($stmt->rowCount() === 0) {
        error_log(
            "change_maturity.php: UPDATE affected 0 rows for region UUID {$region_uuid} "
            . '(no matching regionsettings row — region may be missing its settings row entirely).'
        );
        echo json_encode([
            'ok'    => false,
            'error' => 'Could not change region maturity — this region has no settings row to update.',
        ]);
        exit;
    }

    // ─── Log to portal_log if the table exists ───────────────────────────
    // NOTE: the portal_log column is `details` (plural) — an earlier version
    // of this endpoint used `detail` (singular), which doesn't exist in the
    // table. That mismatch threw on every call, was silently swallowed by
    // this same try/catch (by design — portal_log is non-critical), and
    // masked nothing on the write side, but meant no log entry was ever
    // created. Confirmed against the live schema before fixing.
    try {
        $stmt = $db->prepare(
            "INSERT INTO portal_log (actor_uuid, action, details, created_at)
             VALUES (?, 'region_maturity_change', ?, NOW())"
        );
        $details = json_encode([
            'region_uuid'  => $region_uuid,
            'region_name'  => $region['regionName'],
            'old_maturity' => $current_maturity,
            'new_maturity' => $maturity,
        ]);
        $stmt->execute([$session_user['uuid'], $details]);
    } catch (Throwable) {
        // portal_log table not yet created — skip silently
    }

    echo json_encode([
        'ok'            => true,
        'maturity'      => $maturity,
        'maturityLabel' => $maturity_options[$maturity],
    ]);

} catch (Throwable $e) {
    error_log('change_maturity.php error for region UUID ' . $region_uuid . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not change region maturity. Please try again.']);
}
