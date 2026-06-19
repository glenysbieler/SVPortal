<?php
/**
 * includes/remoteadmin.php — RemoteAdmin XMLRPC client
 *
 * Wraps calls to OpenSim simulators' RemoteAdmin interfaces.
 *
 * ── Architecture: one region per simulator ─────────────────────────────────
 * This grid (and the model this portal assumes) runs ONE region per ONE
 * simulator process, with each simulator listening on its own port. There is
 * no single "default simulator" — every call targets a SPECIFIC simulator,
 * identified by the port that simulator's RemoteAdmin interface listens on.
 *
 * The portal already reads each region's `serverPort` from the `regions`
 * table for other purposes (see includes/estates.php). For RemoteAdmin
 * calls, that SAME port is used as the RemoteAdmin port — i.e. this code
 * assumes a region's RemoteAdmin port equals its `serverPort`
 * (`[Network] port` in that simulator's OpenSim.ini, which is also where
 * RemoteAdmin listens by default). If an operator's setup uses a different
 * RemoteAdmin port per simulator, that would need its own lookup — not
 * currently supported.
 *
 * Every public function below therefore takes an `int $port` as its first
 * parameter, supplied by the caller from `$region['serverPort']`.
 *
 * Configuration (config.php):
 *
 *   REMOTEADMIN_ENABLED   — master toggle; set false to disable all RA calls
 *   REMOTEADMIN_HOST      — host/IP shared by ALL simulators (this portal
 *                           assumes database, ROBUST, and every simulator
 *                           run on the same host — typically '127.0.0.1')
 *   REMOTEADMIN_PASSWORD  — RemoteAdmin password, shared across ALL
 *                           simulators (OpenSim.ini access_password — set
 *                           identically on every simulator)
 *
 * Security note:
 *   RemoteAdmin communicates over plain HTTP. This is safe when the portal
 *   and every simulator run on the same host (127.0.0.1 — nothing leaves
 *   the machine). If a future setup runs simulators on other hosts, this
 *   would need a per-region host as well as per-region port, plus TLS
 *   termination (e.g. via Nginx) in front of each RemoteAdmin port.
 *
 * OpenSim.ini requirements (in EACH simulator's [RemoteAdmin] section):
 *
 *   [RemoteAdmin]
 *   enabled = true
 *   access_password = <shared password>
 *   enabled_methods = all         ; or list specific methods, including
 *                                  ; admin_console_command if uptime display
 *                                  ; is wanted
 *
 * Reference: http://opensimulator.org/wiki/RemoteAdmin
 *
 * ── Return convention ────────────────────────────────────────────────────────
 * All public functions return:
 *   ['success' => true, ...]                on success
 *   ['success' => false, 'error' => '...']  on failure
 *
 * Failures are non-fatal by convention — callers should log and continue
 * rather than surfacing RemoteAdmin errors to end users. A connection
 * failure (cURL error / timeout) is the expected, normal result when the
 * target simulator process is offline — callers use this to show an
 * "Offline" status rather than treating it as an application error.
 */

declare(strict_types=1);

// ─── Check config ─────────────────────────────────────────────────────────────

/**
 * Return true if RemoteAdmin is enabled and configured.
 *
 * Note: this does NOT check connectivity to any specific simulator — it
 * only checks that the portal-wide RemoteAdmin settings (host, password,
 * master toggle) are present. A `false` result from an individual call
 * (e.g. remoteadmin_region_query()) usually means THAT simulator is
 * offline, not that RemoteAdmin is misconfigured.
 */
function remoteadmin_enabled(): bool
{
    return defined('REMOTEADMIN_ENABLED') && REMOTEADMIN_ENABLED
        && defined('REMOTEADMIN_HOST')    && REMOTEADMIN_HOST !== ''
        && defined('REMOTEADMIN_PASSWORD')&& REMOTEADMIN_PASSWORD !== '';
}

/**
 * Build the RemoteAdmin endpoint URL for a specific simulator.
 *
 * @param  int $port  The simulator's RemoteAdmin port (= its serverPort,
 *                     i.e. `regions.serverPort` for the region it hosts)
 */
function remoteadmin_url(int $port): string
{
    return 'http://' . REMOTEADMIN_HOST . ':' . $port . '/';
}


// ─── Core XMLRPC caller ───────────────────────────────────────────────────────

/**
 * Send an XMLRPC call to a specific simulator's RemoteAdmin interface.
 *
 * @param  string $method    XMLRPC method name (e.g. 'admin_console_command')
 * @param  array  $params    Associative array of string parameters
 * @param  int    $port      The target simulator's RemoteAdmin port
 * @param  string $url       Override the endpoint URL entirely (optional —
 *                            for testing against a non-standard endpoint;
 *                            normally leave blank and let $port build the URL)
 * @return array{success: bool, error?: string, raw?: string}
 */
function remoteadmin_call(string $method, array $params, int $port, string $url = ''): array
{
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if ($port <= 0 && $url === '') {
        return ['success' => false, 'error' => 'A valid RemoteAdmin port is required.'];
    }

    if ($url === '') {
        $url = remoteadmin_url($port);
    }

    // Always inject the password
    $params['password'] = REMOTEADMIN_PASSWORD;

    // Build XMLRPC struct members
    $members = '';
    foreach ($params as $name => $value) {
        $safe_name  = htmlspecialchars((string)$name,  ENT_XML1, 'UTF-8');
        $safe_value = htmlspecialchars((string)$value, ENT_XML1, 'UTF-8');
        $members .= "<member>"
                  . "<name>{$safe_name}</name>"
                  . "<value><string>{$safe_value}</string></value>"
                  . "</member>";
    }

    $xml = "<?xml version=\"1.0\"?>"
         . "<methodCall>"
         . "<methodName>" . htmlspecialchars($method, ENT_XML1) . "</methodName>"
         . "<params><param><value><struct>"
         . $members
         . "</struct></value></param></params>"
         . "</methodCall>";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml',
            'Content-Length: ' . strlen($xml),
        ],
    ]);

    $response = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err !== '') {
        // Expected/normal when the target simulator process is not running —
        // callers treat this as "region offline", not as an application error.
        error_log("remoteadmin_call [{$method}] port {$port} cURL error: {$curl_err}");
        return ['success' => false, 'error' => 'Could not connect to simulator.'];
    }

    if ($http_code !== 200) {
        error_log("remoteadmin_call [{$method}] port {$port} HTTP {$http_code}: {$response}");
        return ['success' => false, 'error' => "Simulator returned HTTP {$http_code}."];
    }

    return ['success' => true, 'raw' => (string)$response];
}

/**
 * Check whether a RemoteAdmin XMLRPC response is a "method not found" fault
 * (faultCode -32601).
 *
 * The simulator's main XMLRPC dispatcher always responds with HTTP 200,
 * even when RemoteAdmin itself is disabled or a given admin_* method isn't
 * in that simulator's `enabled_methods` list — in that case it returns a
 * well-formed XMLRPC fault with faultCode -32601 ("method not found")
 * rather than a transport error. remoteadmin_call() treats this as
 * `success => true` (it WAS a valid HTTP/XMLRPC exchange), so callers that
 * need to distinguish "the simulator is up but doesn't support this
 * RemoteAdmin method" from "the method actually ran" should check the raw
 * response with this helper.
 *
 * @param  string $raw  The 'raw' XMLRPC response body from remoteadmin_call()
 * @return bool         True if this is a -32601 "method not found" fault
 */
function remoteadmin_is_method_not_found(string $raw): bool
{
    return str_contains($raw, '<name>faultCode</name>')
        && (str_contains($raw, '<int>-32601</int>') || str_contains($raw, '-32601'));
}


// ─── Public API ───────────────────────────────────────────────────────────────

/**
 * Save a region's contents to an OAR (OpenSim Archive) file.
 *
 * Uses the `admin_save_oar` RemoteAdmin method. The file is written by the
 * simulator process to its own filesystem — `filename` is a path on the
 * SIMULATOR host, not the portal host (though on this grid's single-host
 * setup, "simulator host" and "portal host" are the same machine).
 *
 * @param  int    $port         Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $region_name  Name of the region to save
 * @param  string $filename     Destination path/filename on the simulator host
 * @return array{success: bool, error?: string}
 */
function remoteadmin_save_oar(
    int $port,
    string $region_name,
    string $filename
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($region_name) === '' || trim($filename) === '') {
        return ['success' => false, 'error' => 'Region name and filename are required.'];
    }

    $result = remoteadmin_call('admin_save_oar', [
        'region_name' => $region_name,
        'filename'    => $filename,
    ], $port);

    if (!$result['success']) {
        error_log("remoteadmin_save_oar failed for region '{$region_name}' (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}

/**
 * Load a region's contents from an OAR (OpenSim Archive) file.
 *
 * Uses the `admin_load_oar` RemoteAdmin method. The file must already exist
 * on the SIMULATOR host's filesystem at `filename`.
 *
 * @param  int    $port         Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $region_name  Name of the region to load into
 * @param  string $filename     Source path/filename on the simulator host
 * @param  bool   $merge        Merge into existing region content rather than replace (optional)
 * @return array{success: bool, error?: string}
 */
function remoteadmin_load_oar(
    int $port,
    string $region_name,
    string $filename,
    bool $merge = false
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($region_name) === '' || trim($filename) === '') {
        return ['success' => false, 'error' => 'Region name and filename are required.'];
    }

    $result = remoteadmin_call('admin_load_oar', [
        'region_name' => $region_name,
        'filename'    => $filename,
        'merge'       => $merge ? 'true' : 'false',
    ], $port);

    if (!$result['success']) {
        error_log("remoteadmin_load_oar failed for region '{$region_name}' (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}

/**
 * Save an avatar's inventory to an IAR (Inventory Archive) file.
 *
 * Uses the `admin_save_iar` RemoteAdmin method. The file is written by the
 * simulator process to its own filesystem — `filename` is a path on the
 * SIMULATOR host. Since any simulator can reach the shared user/inventory
 * services, this can be sent to any region's RemoteAdmin port.
 *
 * @param  int    $port            Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $first_name      Avatar's first name
 * @param  string $last_name       Avatar's last name
 * @param  string $inventory_path  Inventory folder path to save (e.g. '/')
 * @param  string $user_password   Avatar's account password (required by RemoteAdmin)
 * @param  string $filename        Destination path/filename on the simulator host
 * @return array{success: bool, error?: string}
 */
function remoteadmin_save_iar(
    int $port,
    string $first_name,
    string $last_name,
    string $inventory_path,
    string $user_password,
    string $filename
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($first_name) === '' || trim($last_name) === '' || trim($filename) === '') {
        return ['success' => false, 'error' => 'First name, last name and filename are required.'];
    }

    $result = remoteadmin_call('admin_save_iar', [
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'inv_path'       => $inventory_path !== '' ? $inventory_path : '/',
        'user_password'  => $user_password,
        'filename'       => $filename,
    ], $port);

    if (!$result['success']) {
        error_log("remoteadmin_save_iar failed for {$first_name} {$last_name} (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}

/**
 * Load an avatar's inventory from an IAR (Inventory Archive) file.
 *
 * Uses the `admin_load_iar` RemoteAdmin method. The file must already exist
 * on the SIMULATOR host's filesystem at `filename`.
 *
 * @param  int    $port            Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $first_name      Avatar's first name
 * @param  string $last_name       Avatar's last name
 * @param  string $inventory_path  Inventory folder path to load into (e.g. '/')
 * @param  string $user_password   Avatar's account password (required by RemoteAdmin)
 * @param  string $filename        Source path/filename on the simulator host
 * @return array{success: bool, error?: string}
 */
function remoteadmin_load_iar(
    int $port,
    string $first_name,
    string $last_name,
    string $inventory_path,
    string $user_password,
    string $filename
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($first_name) === '' || trim($last_name) === '' || trim($filename) === '') {
        return ['success' => false, 'error' => 'First name, last name and filename are required.'];
    }

    $result = remoteadmin_call('admin_load_iar', [
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'inv_path'       => $inventory_path !== '' ? $inventory_path : '/',
        'user_password'  => $user_password,
        'filename'       => $filename,
    ], $port);

    if (!$result['success']) {
        error_log("remoteadmin_load_iar failed for {$first_name} {$last_name} (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}


// ─── Region lifecycle ──────────────────────────────────────────────────────────

/**
 * Query a region's current status (uptime, agent count, etc.).
 *
 * Uses the `admin_region_query` RemoteAdmin method.
 *
 * @param  int    $port         Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $region_name  Name of the region to query (optional — since
 *                               there is only one region per simulator, this
 *                               is normally unnecessary, but RemoteAdmin
 *                               accepts it)
 * @return array{success: bool, error?: string, raw?: string}
 */
function remoteadmin_region_query(
    int $port,
    string $region_name = ''
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    $params = [];
    if ($region_name !== '') {
        $params['region_name'] = $region_name;
    }

    $result = remoteadmin_call('admin_region_query', $params, $port);

    if (!$result['success']) {
        error_log("remoteadmin_region_query failed for region '{$region_name}' (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}

/**
 * Run a console command on a region's simulator and return its output.
 *
 * Uses the `admin_console_command` RemoteAdmin method. This is the
 * mechanism used to retrieve `show uptime` output for the region detail
 * panel's "Region Uptime" field.
 *
 * NOTE: `admin_console_command` is not always included in a default
 * `enabled_methods` list — each simulator's OpenSim.ini [RemoteAdmin]
 * section must explicitly allow it (or use `enabled_methods = all`).
 *
 * @param  int    $port     Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $command  Console command to run (e.g. 'show uptime')
 * @return array{success: bool, error?: string, raw?: string}
 */
function remoteadmin_console_command(
    int $port,
    string $command
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($command) === '') {
        return ['success' => false, 'error' => 'Command is required.'];
    }

    $result = remoteadmin_call('admin_console_command', [
        'command' => $command,
    ], $port);

    if (!$result['success']) {
        error_log("remoteadmin_console_command failed for '{$command}' (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}

/**
 * Restart a region.
 *
 * Uses the `admin_restart` RemoteAdmin method — OpenSim's dedicated region
 * restart mechanism. `admin_shutdown_region` was tried first (the simulator
 * process exiting + a process supervisor like monit relaunching it), but in
 * practice `admin_shutdown_region` returns a -32601 "method not found" fault
 * even with `enabled_methods = all` — it isn't in RemoteAdmin's dispatch
 * table on the OpenSim versions this grid runs. `admin_restart` IS, and is
 * the documented/intended restart call (see
 * opensim_webportal_background.md — "Confirmed useful RemoteAdmin commands").
 *
 * `admin_restart` takes a `restart` parameter: a comma-separated list of
 * integers (seconds from now). OpenSim broadcasts a warning message to
 * in-world users at each value except the last, and performs the actual
 * restart at the final (largest) value. We send a single value — i.e. the
 * region restarts $delay seconds from now with one warning broadcast
 * immediately.
 *
 * Suitable for estate managers — `admin_restart` is OpenSim's own graceful
 * restart, so there's no dependency on an external process supervisor.
 *
 * ── Graceful handling of simulators without RemoteAdmin enabled ────────────
 * Not every simulator on the grid has RemoteAdmin enabled yet. If the target
 * simulator's dispatcher is up but doesn't recognise `admin_restart`
 * (RemoteAdmin disabled, or the method isn't in that simulator's
 * `enabled_methods`), it responds with a well-formed -32601 "method not
 * found" fault — still `success => true` at the transport level. This is
 * detected here and reported back as `success => false` with
 * `not_enabled => true`, so callers can show a clear "not available for
 * this region yet" message rather than implying the restart happened.
 *
 * @param  int    $port         Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $region_name  Name of the region to restart
 * @param  string $message      Message broadcast to users before restart (currently
 *                               unused by admin_restart — kept for API
 *                               compatibility/future use)
 * @param  int    $delay        Seconds from now at which the region restarts.
 *                               Sent to RemoteAdmin as a single-element
 *                               'alerts' list (admin_restart reads
 *                               requestData["alerts"], a comma-separated
 *                               list of alert times in seconds — NOT a
 *                               'restart' parameter, despite the method
 *                               name). (optional, default 30)
 * @return array{success: bool, error?: string, not_enabled?: bool}
 */
function remoteadmin_restart_region(
    int $port,
    string $region_name,
    string $message = 'The region is restarting.',
    int $delay = 30
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($region_name) === '') {
        return ['success' => false, 'error' => 'Region name is required.'];
    }

    $result = remoteadmin_call('admin_restart', [
        'alerts' => (string)max(0, $delay),
    ], $port);

    if ($result['success'] && remoteadmin_is_method_not_found($result['raw'] ?? '')) {
        error_log("remoteadmin_restart_region: RemoteAdmin not enabled for region '{$region_name}' (port {$port}). Raw response: " . ($result['raw'] ?? ''));
        return [
            'success'     => false,
            'error'       => 'RemoteAdmin is not enabled on this region\'s simulator yet.',
            'not_enabled' => true,
        ];
    }

    if (!$result['success']) {
        error_log("remoteadmin_restart_region failed for region '{$region_name}' (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}

/**
 * Broadcast a message to everyone currently in a region.
 *
 * Uses the `admin_broadcast` RemoteAdmin method — part of the same dispatch
 * table as `admin_restart`/`admin_region_query` (registered in OpenSim's
 * RemoteAdminPlugin alongside them), so it goes over the same XMLRPC
 * transport, to the same per-simulator port, as every other function in
 * this file. No REST equivalent exists or is needed.
 *
 * Takes a single `message` parameter beyond the password — OpenSim displays
 * it in-world as an alert to every avatar currently in that region. Unlike
 * `admin_restart`, there is no delay/alerts list: the message is shown
 * immediately.
 *
 * ── Process-scoped, same as restart/stats ───────────────────────────────────
 * Like `admin_restart` and `jsonSimStats`, this RemoteAdmin call is bound to
 * the simulator's HTTP listener — i.e. scoped to the PROCESS, not to an
 * individual region. On a multi-region-per-simulator topology, only the
 * first/primary region in that process will actually receive the broadcast;
 * other regions hosted in the same process are unaffected. See
 * `SINGLE_REGION_PER_SIMULATOR` in Things_to_do.md.
 *
 * ── Graceful handling of simulators without RemoteAdmin enabled ────────────
 * Same -32601 "method not found" detection as remoteadmin_restart_region() —
 * if the target simulator's dispatcher is up but RemoteAdmin isn't enabled
 * (or `admin_broadcast` isn't in that simulator's `enabled_methods`), this is
 * reported back as `success => false` with `not_enabled => true`.
 *
 * @param  int    $port         Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $region_name  Name of the region (used only for logging)
 * @param  string $message      Message text to broadcast in-world
 * @return array{success: bool, error?: string, not_enabled?: bool}
 */
function remoteadmin_broadcast(
    int $port,
    string $region_name,
    string $message
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($message) === '') {
        return ['success' => false, 'error' => 'Message is required.'];
    }

    $result = remoteadmin_call('admin_broadcast', [
        'message' => $message,
    ], $port);

    if ($result['success'] && remoteadmin_is_method_not_found($result['raw'] ?? '')) {
        error_log("remoteadmin_broadcast: RemoteAdmin not enabled for region '{$region_name}' (port {$port}). Raw response: " . ($result['raw'] ?? ''));
        return [
            'success'     => false,
            'error'       => 'RemoteAdmin is not enabled on this region\'s simulator yet.',
            'not_enabled' => true,
        ];
    }

    if (!$result['success']) {
        error_log("remoteadmin_broadcast failed for region '{$region_name}' (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}

/**
 * Shut down a region without restarting it.
 *
 * Uses the `admin_shutdown_region` RemoteAdmin method. Unlike
 * remoteadmin_restart_region(), this offers no guarantee the region will
 * come back — if the process supervisor (monit) is configured to always
 * restart the simulator process, this will likely just look like a restart
 * with extra delay. Bringing a region back up "for good" requires either
 * disabling the supervisor's watch for that simulator, or the supervisor
 * itself recognising a deliberate stop.
 *
 * Callers should gate access to this function behind a high UserLevel
 * (grid admin) and be aware of how monit is configured for the target
 * simulator before exposing the corresponding UI control.
 *
 * @param  int    $port         Target simulator's RemoteAdmin port (= serverPort)
 * @param  string $region_name  Name of the region to shut down
 * @param  string $message      Message broadcast to users before shutdown (optional)
 * @param  int    $delay        Seconds to wait before shutdown (optional, default 30)
 * @return array{success: bool, error?: string}
 */
function remoteadmin_shutdown_region(
    int $port,
    string $region_name,
    string $message = 'The region is shutting down.',
    int $delay = 30
): array {
    if (!remoteadmin_enabled()) {
        return ['success' => false, 'error' => 'RemoteAdmin is not enabled.'];
    }

    if (trim($region_name) === '') {
        return ['success' => false, 'error' => 'Region name is required.'];
    }

    $result = remoteadmin_call('admin_shutdown_region', [
        'region_name' => $region_name,
        'message'     => $message,
        'delay'       => (string)max(0, $delay),
    ], $port);

    if (!$result['success']) {
        error_log("remoteadmin_shutdown_region failed for region '{$region_name}' (port {$port}): " . ($result['error'] ?? ''));
    }

    return $result;
}
