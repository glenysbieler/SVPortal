<?php
/**
 * includes/rest_console.php — OpenSimulator REST Console client
 *
 * Talks to a simulator's (or ROBUST's) REST console interface, as enabled
 * by `-console=rest`. See RestConsole.md for background and design notes.
 *
 * ── Protocol summary (http://opensimulator.org/wiki/RestConsole) ────────────
 *
 *   POST /StartSession/      USER, PASS         -> <ConsoleSession><SessionID>...
 *   POST /ReadResponses/<SessionID>/             -> <ConsoleSession><Line ...>...
 *   POST /SessionCommand/    ID, COMMAND         -> <ConsoleSession><Result>...
 *   POST /CloseSession/       ID                  -> <ConsoleSession><Result>...
 *
 * `ReadResponses` is a long-poll — the simulator holds the connection open
 * for up to ~30 seconds if there's nothing new, then returns an empty/failed
 * response.
 *
 * ── Usage pattern ────────────────────────────────────────────────────────
 * The portal's console UI uses restconsole_run_once() exclusively: each
 * console command does a full StartSession -> SessionCommand ->
 * ReadResponses -> CloseSession cycle in one go, and nothing is left open
 * between commands. An earlier interactive design that kept a session open
 * and polled ReadResponses in a loop was found to trigger a recurring
 * NullReferenceException in the simulator's poll-service log for as long as
 * the session stayed open — see console_oneshot.php's docblock for details.
 * The lower-level functions below (restconsole_start_session() etc.) remain
 * as the building blocks restconsole_run_once() composes.
 *
 * ── ConsolePort convention ───────────────────────────────────────────────────
 * Per RestConsole.md, the REST console rides on the simulator's existing
 * `http_listener_port` (= `regions.serverPort`) when `ConsolePort` is left at
 * its default of 0 — same convention as RemoteAdmin. No separate port lookup
 * is required; callers pass the region's serverPort (or ROBUST's private
 * port for the Robust console).
 *
 * ── Credentials ───────────────────────────────────────────────────────────
 * CONSOLE_USER / CONSOLE_PASS are shared across all regions and ROBUST (set
 * once in SubVersion-Defaults.ini's [Network] section — see RestConsole.md).
 * Never sent to the browser; only used server-side here.
 *
 * ── Return convention ────────────────────────────────────────────────────────
 * All public functions return:
 *   ['success' => true, ...]                 on success
 *   ['success' => false, 'error' => '...']   on failure
 *
 * A connection failure (cURL error / timeout) is the expected, normal result
 * when the target simulator process is offline.
 */

declare(strict_types=1);

// ─── Check config ───────────────────────────────────────────────────────────

/**
 * Return true if the REST console feature is enabled and configured.
 *
 * Does NOT check connectivity to any specific simulator — only that the
 * portal-wide settings (master toggle, host, credentials) are present.
 */
function restconsole_enabled(): bool
{
    return defined('ENABLE_REST_CONSOLE') && ENABLE_REST_CONSOLE
        && defined('CONSOLE_HOST') && CONSOLE_HOST !== ''
        && defined('CONSOLE_USER') && CONSOLE_USER !== ''
        && defined('CONSOLE_PASS') && CONSOLE_PASS !== '';
}

/**
 * Build the REST console base URL for a specific host/port.
 *
 * @param  int    $port  The target's HTTP listener port (region serverPort,
 *                        or ROBUST's private port for the Robust console)
 * @param  string $host  Optional host override; defaults to CONSOLE_HOST
 */
function restconsole_url(int $port, string $host = ''): string
{
    if ($host === '') {
        $host = CONSOLE_HOST;
    }
    return 'http://' . $host . ':' . $port . '/';
}


// ─── Core HTTP POST helper ────────────────────────────────────────────────────

/**
 * POST form-encoded data to a REST console endpoint and return the raw body.
 *
 * @param  string $url      Full endpoint URL (including trailing path)
 * @param  array  $params    Form parameters
 * @param  int    $timeout   cURL timeout in seconds
 * @return array{success: bool, error?: string, raw?: string}
 */
function restconsole_post(string $url, array $params, int $timeout = 8): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_err !== '') {
        return ['success' => false, 'error' => 'Could not connect to console: ' . $curl_err];
    }

    if ($http_code !== 200) {
        return ['success' => false, 'error' => "Console returned HTTP {$http_code}."];
    }

    return ['success' => true, 'raw' => (string)$response];
}

/**
 * Parse a <ConsoleSession>...</ConsoleSession> XML response.
 *
 * @return SimpleXMLElement|null  Null on parse failure
 */
function restconsole_parse_xml(string $raw): ?SimpleXMLElement
{
    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($raw);
    libxml_use_internal_errors($prev);

    return $xml === false ? null : $xml;
}


// ─── Public API ────────────────────────────────────────────────────────────

/**
 * Start a new REST console session.
 *
 * @param  int    $port  Target HTTP listener port
 * @param  string $host  Optional host override
 * @return array{success: bool, error?: string, session_id?: string, prompt?: string}
 */
function restconsole_start_session(int $port, string $host = ''): array
{
    if (!restconsole_enabled()) {
        return ['success' => false, 'error' => 'The REST console is not enabled on this portal.'];
    }

    if ($port <= 0) {
        return ['success' => false, 'error' => 'A valid console port is required.'];
    }

    $result = restconsole_post(restconsole_url($port, $host) . 'StartSession/', [
        'USER' => CONSOLE_USER,
        'PASS' => CONSOLE_PASS,
    ]);

    if (!$result['success']) {
        return $result;
    }

    $xml = restconsole_parse_xml($result['raw']);
    if ($xml === null || !isset($xml->SessionID) || (string)$xml->SessionID === '') {
        return ['success' => false, 'error' => 'Console did not return a session — check ConsoleUser/ConsolePass and that -console=rest is enabled.'];
    }

    return [
        'success'    => true,
        'session_id' => (string)$xml->SessionID,
        'prompt'     => isset($xml->Prompt) ? (string)$xml->Prompt : '',
    ];
}

/**
 * Send a command on an existing session. Does not itself return command
 * output — call restconsole_read_responses() afterwards to retrieve it.
 *
 * @param  int    $port       Target HTTP listener port
 * @param  string $session_id Session ID from restconsole_start_session()
 * @param  string $command    Console command text
 * @param  string $host       Optional host override
 * @return array{success: bool, error?: string, result?: string, session_expired?: bool}
 */
function restconsole_send_command(int $port, string $session_id, string $command, string $host = ''): array
{
    if (!restconsole_enabled()) {
        return ['success' => false, 'error' => 'The REST console is not enabled on this portal.'];
    }

    $result = restconsole_post(restconsole_url($port, $host) . 'SessionCommand/', [
        'ID'      => $session_id,
        'COMMAND' => $command,
    ]);

    if (!$result['success']) {
        return $result;
    }

    $xml = restconsole_parse_xml($result['raw']);
    if ($xml === null) {
        return ['success' => false, 'error' => 'Console returned an unreadable response.'];
    }

    $session_result = isset($xml->Result) ? (string)$xml->Result : '';

    // An expired/unknown session typically comes back as a non-"OK" Result
    // (e.g. "Session not found") — surface this distinctly so the caller can
    // transparently re-open a session and resend, per RestConsole.md.
    if ($session_result !== '' && $session_result !== 'OK') {
        return [
            'success'         => false,
            'error'           => $session_result,
            'session_expired' => true,
        ];
    }

    return ['success' => true, 'result' => $session_result];
}

/**
 * Read any new output lines from a session's scrollback buffer.
 *
 * @param  int    $port       Target HTTP listener port
 * @param  string $session_id Session ID from restconsole_start_session()
 * @param  string $host       Optional host override
 * @param  int    $timeout    cURL timeout in seconds (default 45 — see notes
 *                             below for the interactive console's polling
 *                             use; one-shot callers may pass a shorter value
 *                             since they expect output promptly or not at all)
 * @return array{success: bool, error?: string, lines?: array<int, array{number:int,level:string,prompt:bool,command:bool,input:bool,text:string}>, session_expired?: bool}
 */
function restconsole_read_responses(int $port, string $session_id, string $host = '', int $timeout = 45): array
{
    if (!restconsole_enabled()) {
        return ['success' => false, 'error' => 'The REST console is not enabled on this portal.'];
    }

    // This is a genuine long-poll on the simulator side — it holds the
    // connection open for up to ~30s waiting for new output, then returns
    // (possibly with an error/empty body) if nothing arrived. We now poll
    // SEQUENTIALLY (one ReadResponses in flight at a time, the frontend
    // waits for this to resolve before issuing the next), so we let it run
    // close to its full duration rather than abandoning it early.
    //
    // Abandoning ReadResponses requests early (e.g. a short client timeout
    // combined with a fixed polling interval) leaves orphaned long-poll
    // workers piling up in OpenSim's PollServiceRequestManager — observed
    // to cause a "NullReferenceException ... PoolWorkerJob(Object o)"
    // storm and to starve other requests (console commands, RemoteAdmin)
    // to the same simulator. Letting each poll complete naturally avoids
    // this entirely.
    //
    // Default 45s — comfortably ABOVE OpenSim's ~30s long-poll ceiling, so
    // cURL never closes the connection while OpenSim is still holding it
    // open. Requires PHP-FPM's `max_execution_time` (and
    // `request_terminate_timeout` if non-zero) to be >= this value —
    // confirmed raised to 60s / 0 (unlimited) on this install.
    $result = restconsole_post(restconsole_url($port, $host) . 'ReadResponses/' . rawurlencode($session_id) . '/', [
        'ID' => $session_id,
    ], $timeout);

    if (!$result['success']) {
        // A timeout here is the NORMAL "no new output" case for a long-poll
        // endpoint — treat it as success with no lines rather than an error,
        // so the frontend doesn't show a connection error every empty poll.
        return ['success' => true, 'lines' => []];
    }

    $xml = restconsole_parse_xml($result['raw']);
    if ($xml === null) {
        return ['success' => true, 'lines' => []];
    }

    $lines = [];
    if (isset($xml->Line)) {
        foreach ($xml->Line as $line) {
            $attrs = $line->attributes();
            $lines[] = [
                'number'  => (int)($attrs['Number'] ?? 0),
                'level'   => (string)($attrs['Level'] ?? ''),
                'prompt'  => ((string)($attrs['Prompt'] ?? 'false')) === 'true',
                'command' => ((string)($attrs['Command'] ?? 'false')) === 'true',
                'input'   => ((string)($attrs['Input'] ?? 'false')) === 'true',
                'text'    => (string)$line,
            ];
        }
    }

    return ['success' => true, 'lines' => $lines];
}

/**
 * Close a session. Best-effort — failures are logged but not surfaced, since
 * the session will simply time out server-side anyway.
 *
 * @param  int    $port       Target HTTP listener port
 * @param  string $session_id Session ID from restconsole_start_session()
 * @param  string $host       Optional host override
 */
function restconsole_close_session(int $port, string $session_id, string $host = ''): void
{
    if (!restconsole_enabled() || $session_id === '') {
        return;
    }

    $result = restconsole_post(restconsole_url($port, $host) . 'CloseSession/', [
        'ID' => $session_id,
    ], 4);

    if (!$result['success']) {
        error_log('restconsole_close_session: ' . ($result['error'] ?? 'unknown error'));
    }
}


// ─── One-shot command execution ──────────────────────────────────────────────

/**
 * Run a single console command to completion: open a session, send the
 * command, collect its output, and close the session — all in one call.
 *
 * Intended for the "Quick Commands" UI (preset buttons like "Show Uptime",
 * "Show Users") as an alternative to the interactive console modal. Unlike
 * the interactive console, this does NOT leave a session open and does not
 * rely on repeated polling — it issues at most a handful of short
 * ReadResponses calls in quick succession to collect output that may arrive
 * in stages, then closes the session immediately.
 *
 * This minimises exposure to whatever is causing the
 * "NullReferenceException ... PoolWorkerJob(Object o)" log spam observed
 * with the interactive console's long-poll loop — each click triggers at
 * most a few short ReadResponses calls rather than an indefinitely open
 * polling session.
 *
 * @param  int    $port    Target HTTP listener port
 * @param  string $command Console command to run
 * @param  string $host    Optional host override
 * @return array{success: bool, error?: string, lines?: array<int, array{level:string,prompt:bool,command:bool,input:bool,text:string}>}
 */
function restconsole_run_once(int $port, string $command, string $host = ''): array
{
    if (!restconsole_enabled()) {
        return ['success' => false, 'error' => 'The REST console is not enabled on this portal.'];
    }

    $session = restconsole_start_session($port, $host);
    if (!$session['success']) {
        return ['success' => false, 'error' => $session['error'] ?? 'Could not open console session.'];
    }
    $session_id = $session['session_id'];

    // Drain the initial scrollback burst (banner/prompt) so it doesn't get
    // mixed in with this command's output.
    restconsole_read_responses($port, $session_id, $host);

    $send = restconsole_send_command($port, $session_id, $command, $host);
    if (!$send['success']) {
        restconsole_close_session($port, $session_id, $host);
        return ['success' => false, 'error' => $send['error'] ?? 'Could not send command.'];
    }

    // Give the simulator a brief moment to process and buffer the command's
    // output before reading it back.
    usleep(300_000); // 0.3s

    // A SINGLE ReadResponses call — using the same timeout as the
    // interactive console (comfortably above OpenSim's ~30s long-poll
    // ceiling) so this call is never abandoned client-side either. Output
    // for "show users" / "show region" etc. is typically available
    // immediately, so this normally returns within a second; if the
    // simulator has nothing buffered yet it will hold the long-poll open
    // until output arrives or its own ~30s ceiling is reached.
    $read = restconsole_read_responses($port, $session_id, $host);
    $lines = $read['success'] ? ($read['lines'] ?? []) : [];

    restconsole_close_session($port, $session_id, $host);

    return ['success' => true, 'lines' => $lines];
}


// ─── Output colourisation ────────────────────────────────────────────────────

/**
 * Wrap a single console output line in a span with a CSS class based on its
 * recognised log level / prefix, for client-side colourisation.
 *
 * Mirrors the approach proposed in ConsoleAccess.md for the log viewer:
 * known OpenSim log-level tokens (DEBUG/INFO/WARN/ERROR/FATAL) and other
 * recognisable prefixes (e.g. [ARCHIVER]) get a CSS class; anything else
 * falls back to plain text. Never blocks output from displaying.
 *
 * The returned string is HTML — the caller embeds it directly into the
 * output pane. Input is htmlspecialchars'd here.
 *
 * @param  array{level:string,prompt:bool,command:bool,input:bool,text:string} $line
 * @return string  HTML for one line (without trailing newline)
 */
function restconsole_render_line(array $line): string
{
    $text = htmlspecialchars($line['text'], ENT_QUOTES);

    $class = 'console-line';

    if ($line['input']) {
        $class .= ' console-line-input';
    } elseif ($line['prompt']) {
        $class .= ' console-line-prompt';
    } else {
        // Match a leading log-level token, e.g. "12:34:56 - INFO - ..." or
        // "INFO: ...". Case-insensitive; falls back to plain text if no
        // recognised token is found.
        if (preg_match('/\b(DEBUG|INFO|WARN(?:ING)?|ERROR|FATAL)\b/i', $line['text'], $m)) {
            $level = strtoupper($m[1]);
            $level = $level === 'WARNING' ? 'WARN' : $level;
            $class .= ' console-line-' . strtolower($level);
        }
    }

    return '<span class="' . $class . '">' . $text . '</span>';
}
