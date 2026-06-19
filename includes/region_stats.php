<?php
/**
 * includes/region_stats.php — OpenSimulator jsonSimStats client
 *
 * Fetches live per-region statistics (FPS, agent counts, memory, uptime,
 * etc.) from a simulator's built-in `/jsonSimStats` endpoint.
 *
 * ── Why this exists ─────────────────────────────────────────────────────
 * Unlike the REST console (see includes/rest_console.php), `/jsonSimStats`
 * is a plain, unauthenticated HTTP GET on the simulator's normal HTTP
 * listener — no session, no long-poll, no PollServiceRequestManager
 * involvement at all. It's part of OpenSimulator's stats-reporting
 * machinery and is available by DEFAULT on 0.9.3.0 with no `[Monitoring]`
 * configuration needed (confirmed live against this grid).
 *
 * Because it's unauthenticated, anyone who knows a region's host:port can
 * already query this directly — the portal calling it server-side adds no
 * new exposure. Per RestConsole-style conventions this rides the region's
 * existing `serverPort` (regions.serverPort), same as RemoteAdmin/console.
 *
 * ── Caveat ──────────────────────────────────────────────────────────────
 * This is a separate feature from region_status.php's online/offline check
 * and does NOT replace it. `/jsonSimStats` availability across different
 * OpenSim versions/builds/grids has not yet been fully verified — treat a
 * failed fetch as "stats unavailable", not as evidence the region is
 * offline.
 *
 * ── Return convention ────────────────────────────────────────────────────────
 * ['success' => true, 'stats' => [...]]    on success
 * ['success' => false, 'error' => '...']   on failure (connection error,
 *                                            non-200, unparseable JSON)
 */

declare(strict_types=1);

/**
 * Fetch and parse /jsonSimStats from a region's simulator.
 *
 * Unlike RemoteAdmin/REST console (internal-only interfaces this portal
 * assumes share a host with the simulators, see includes/remoteadmin.php),
 * /jsonSimStats is unauthenticated and meant to be externally reachable —
 * so the simulator's own externally-reachable address is used, NOT an
 * assumed "same host as the portal" address. OpenSim already stores this
 * per-region in `regions.serverIP` (confirmed: matches the "External
 * endpoint" IP shown by `show region`), so no extra config or per-region
 * setup is needed — this works correctly even if simulators run on
 * different hosts from the portal or from each other.
 *
 * @param  string $ip    Region's serverIP (regions.serverIP)
 * @param  int    $port  Region's serverPort (regions.serverPort)
 * @return array{success: bool, error?: string, stats?: array<string, mixed>}
 */
function region_stats_fetch(string $ip, int $port): array
{
    if ($ip === '' || $port <= 0) {
        return ['success' => false, 'error' => 'No server address configured for this region.'];
    }

    $url = 'http://' . $ip . ':' . $port . '/jsonSimStats';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err !== '') {
        return ['success' => false, 'error' => 'Could not connect to simulator.'];
    }

    if ($http_code !== 200) {
        return ['success' => false, 'error' => "Simulator returned HTTP {$http_code}."];
    }

    $data = json_decode((string)$response, true);

    if (!is_array($data)) {
        return ['success' => false, 'error' => 'Simulator returned an unreadable stats response.'];
    }

    return ['success' => true, 'stats' => $data];
}

/**
 * Parse a .NET TimeSpan-formatted uptime string (as returned in the
 * "Uptime" field of /jsonSimStats, e.g. "8.10:30:07.6764100" —
 * days.hours:minutes:seconds.fraction) into "X days, Y hours, Z mins".
 *
 * Falls back to returning the raw string unchanged if it doesn't match the
 * expected format (defensive — formats could vary across OpenSim versions).
 *
 * @param  string $raw  Raw "Uptime" value from /jsonSimStats
 * @return string       Human-readable uptime, e.g. "8 days, 10 hours, 30 mins"
 */
function region_stats_format_uptime(string $raw): string
{
    // "D.HH:MM:SS.fffffff" or "HH:MM:SS.fffffff" (no leading days)
    if (!preg_match('/^(?:(\d+)\.)?(\d{1,2}):(\d{2}):(\d{2})(?:\.\d+)?$/', trim($raw), $m)) {
        return $raw;
    }

    $days    = isset($m[1]) && $m[1] !== '' ? (int)$m[1] : 0;
    $hours   = (int)$m[2];
    $minutes = (int)$m[3];

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
    }
    if ($hours > 0 || $days > 0) {
        $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
    $parts[] = $minutes . ' min' . ($minutes === 1 ? '' : 's');

    return implode(', ', $parts);
}
