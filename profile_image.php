<?php
/**
 * profile_image.php — Asset image proxy with aggressive disk cache
 *
 * Fetches a raw JPEG2000 codestream from the ROBUST asset service and
 * converts it to PNG via php-imagick, then serves it to the browser.
 *
 * Usage:
 *   <img src="profile_image.php?uuid=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
 *
 * Special UUIDs are intercepted before any network request is made:
 *   00000000-0000-0000-0000-000000000000  null / no image set
 *   c228d1cf-4b5d-4ba8-84f4-899a0796aa97  default UV skin texture
 * Both redirect to the SVG placeholder rather than fetching from ROBUST.
 *
 * Caching strategy:
 *   Two-layer cache:
 *
 *   1. Disk cache (server-side, permanent)
 *      Converted PNGs are written to ASSET_CACHE_DIR keyed by UUID.
 *      Because OpenSim asset UUIDs are immutable — a UUID always refers to
 *      the same image forever — cached files never go stale and are kept
 *      indefinitely. When a user changes their profile picture or pick
 *      snapshot in-world, OpenSim assigns a brand new UUID; the old cached
 *      file simply stops being requested. No expiry or invalidation needed.
 *
 *   2. Browser cache (client-side, 30 days)
 *      Once a UUID is in the disk cache, responses carry a long max-age so
 *      the browser doesn't re-request the same image on every page load.
 *      Cache-Control: public is safe here because the URL already contains
 *      the UUID — there is no sensitive information in the image URL itself,
 *      and the endpoint still requires an active session before serving.
 *
 * Cache directory:
 *   Defined by ASSET_CACHE_DIR in config.php.
 *   Default: sys_get_temp_dir() . '/opensim_asset_cache'
 *   The directory is created automatically on first use with mode 0750.
 *   Ensure the web server user has write access to this path.
 *   This directory should NOT be web-accessible (place outside document root
 *   or protect with a deny rule in Nginx/Apache).
 *
 * Requirements:
 *   - php-imagick extension (ImageMagick built with openjpeg/j2k support)
 *   - ROBUST asset service reachable at ASSET_SERVICE_URL (config.php)
 *
 * Security:
 *   - UUID format is validated with a strict regex before use in any URL
 *   - No user input reaches cURL or Imagick unvalidated
 *   - Images are served without authentication — the UUID in the URL is opaque
 *     and Cache-Control: public is intentional (see caching notes above)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ── Cache configuration ───────────────────────────────────────────────────────

// Allow config.php to override the cache directory; fall back to system temp.
if (!defined('ASSET_CACHE_DIR')) {
    define('ASSET_CACHE_DIR', sys_get_temp_dir() . '/opensim_asset_cache');
}

// Browser cache duration in seconds. 30 days is safe because UUID = content.
const BROWSER_CACHE_SECONDS = 2592000;  // 30 days

// ── Special UUIDs ─────────────────────────────────────────────────────────────
const UUID_NULL    = '00000000-0000-0000-0000-000000000000';
const UUID_DEFAULT = 'c228d1cf-4b5d-4ba8-84f4-899a0796aa97';

// ── Validate UUID from query string ───────────────────────────────────────────
$uuid = trim($_GET['uuid'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    serve_placeholder();
    exit;
}

$uuid = strtolower($uuid);

if ($uuid === UUID_NULL || $uuid === UUID_DEFAULT) {
    serve_placeholder();
    exit;
}

// ── Disk cache check ──────────────────────────────────────────────────────────
$cache_file = get_cache_path($uuid);

if ($cache_file !== null && file_exists($cache_file)) {
    // Cache hit — serve directly from disk, no ROBUST or Imagick involved
    serve_cached_png($cache_file);
    exit;
}

// ── Cache miss — fetch from ROBUST asset service ──────────────────────────────
$asset_url = rtrim(ASSET_SERVICE_URL, '/') . '/' . $uuid . '/data';

$ch = curl_init($asset_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FAILONERROR    => false,
]);

$body     = curl_exec($ch);
$http     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($curl_err !== '' || $body === false || $http !== 200) {
    error_log(sprintf(
        'profile_image.php: asset fetch failed for UUID %s — HTTP %d, cURL: %s',
        $uuid, $http, $curl_err
    ));
    serve_placeholder();
    exit;
}

if (strlen($body) === 0) {
    error_log('profile_image.php: empty body from asset service for UUID ' . $uuid);
    serve_placeholder();
    exit;
}

// ── Convert J2K → PNG via Imagick ─────────────────────────────────────────────
if (!extension_loaded('imagick')) {
    error_log('profile_image.php: php-imagick extension not loaded — cannot convert J2K');
    serve_placeholder();
    exit;
}

try {
    $im = new Imagick();
    $im->readImageBlob($body);
    $im->setImageFormat('png');
    $im->stripImage();

    $png = $im->getImageBlob();
    $im->destroy();

    if ($png === false || strlen($png) === 0) {
        throw new ImagickException('getImageBlob returned empty result');
    }

} catch (ImagickException $e) {
    error_log('profile_image.php: Imagick conversion failed for UUID ' . $uuid . ' — ' . $e->getMessage());
    serve_placeholder();
    exit;
}

// ── Write to disk cache ───────────────────────────────────────────────────────
if ($cache_file !== null) {
    // Write to a temp file first, then rename — atomic on Linux, prevents
    // a concurrent request from reading a partially-written file.
    $tmp = $cache_file . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $png) !== false) {
        rename($tmp, $cache_file);
    } else {
        // Cache write failed — not fatal, we still serve the image
        error_log('profile_image.php: failed to write cache file ' . $cache_file);
        @unlink($tmp);
    }
}

// ── Serve the converted PNG ───────────────────────────────────────────────────
serve_png_data($png);
exit;


// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return the full path to the cache file for a given UUID,
 * creating the cache directory if it doesn't exist yet.
 * Returns null if the directory cannot be created or is not writable.
 */
function get_cache_path(string $uuid): ?string
{
    $dir = rtrim(ASSET_CACHE_DIR, '/');

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0750, true)) {
            error_log('profile_image.php: cannot create cache directory ' . $dir);
            return null;
        }
    }

    if (!is_writable($dir)) {
        error_log('profile_image.php: cache directory not writable: ' . $dir);
        return null;
    }

    // UUID is already validated and lowercased — safe to use directly as filename
    return $dir . '/' . $uuid . '.png';
}

/**
 * Serve a PNG from a cached file on disk.
 */
function serve_cached_png(string $path): void
{
    $size = filesize($path);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . BROWSER_CACHE_SECONDS . ', immutable');
    header('X-Cache: HIT');
    if ($size !== false) {
        header('Content-Length: ' . $size);
    }
    readfile($path);
}

/**
 * Serve a PNG from an in-memory blob (freshly converted, not yet cached).
 */
function serve_png_data(string $png): void
{
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=' . BROWSER_CACHE_SECONDS . ', immutable');
    header('X-Cache: MISS');
    header('Content-Length: ' . strlen($png));
    echo $png;
}

/**
 * Serve the SVG avatar placeholder and exit.
 * Used whenever we cannot (or should not) serve a real asset.
 * Placeholders are not disk-cached — they're trivially cheap to generate.
 */
function serve_placeholder(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
         . '<rect width="200" height="200" fill="#e8e0f0"/>'
         . '<circle cx="100" cy="80" r="40" fill="#c4b5d4"/>'
         . '<ellipse cx="100" cy="170" rx="60" ry="45" fill="#c4b5d4"/>'
         . '</svg>';

    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=300');
    echo $svg;
}
