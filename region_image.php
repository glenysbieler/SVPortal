<?php
/**
 * region_image.php — Region map tile proxy via zoom-1 tile stitching
 *
 * Builds a single thumbnail image for a region by fetching one or more
 * zoom-1 map tiles from the grid's HTTP map tile service
 * (MAP_TILE_SERVICE_URL, see config.php), stitching them into a canvas
 * covering the region's full extent, and resizing the result to a
 * standard 256x256px thumbnail.
 *
 * Usage:
 *   <img src="region_image.php?locX=2046976&locY=2049024&sizeX=1024&sizeY=1024">
 *
 * Optional:
 *   &refresh=1 — force re-fetch of every tile in this region's grid from
 *                the map tile service, overwriting the disk cache, before
 *                compositing. Intended for an explicit "Request new map
 *                image" action (e.g. in the region detail modal) — see
 *                Things_to_do.md.
 *
 * ── Why zoom-1 stitching ────────────────────────────────────────────────────
 * The grid's map tile HTTP service returns real, up-to-date tiles (including
 * objects/buildings) independently of the regionMapTexture asset:
 *
 *   {MAP_TILE_SERVICE_URL}/map-{zoom}-{X}-{Y}-objects.jpg
 *
 * Where X = locX/256, Y = locY/256 (a region's south-west corner in 256m
 * map-tile units). Each returned image is always 256x256px. At zoom 1 each
 * tile covers exactly 256x256m, anchored at its own SW corner.
 *
 * Earlier approaches tried higher integer zoom levels (covering more metres
 * per 256x256px image, then cropping back to the region's extent) but the
 * tile service does NOT support zoom 5+ (confirmed both via this proxy and
 * directly in the grid's own web map) — for regions >2048m this left no
 * working zoom level that covers the whole region in one tile.
 *
 * zoom 1 is confirmed to always work, for any region. So instead: a region
 * of size sizeX x sizeY (assumed to be multiples of 256m, the OpenSim norm)
 * is covered by an (sizeX/256) x (sizeY/256) grid of adjacent zoom-1 tiles,
 * each fetched individually and composited into one canvas, which is then
 * resized down to a 256x256px thumbnail.
 *
 * ── Tile grid layout ─────────────────────────────────────────────────────────
 * tiles_x = sizeX / 256, tiles_y = sizeY / 256 (rounded up — see below)
 * For tile column i (0..tiles_x-1) and row j (0..tiles_y-1) counting from
 * the region's SW corner:
 *   fetch map-1-{tile_x+i}-{tile_y+j}-objects.jpg
 * Map Y increases northward but image Y increases downward, so row j=0
 * (the southernmost row) goes at the BOTTOM of the canvas:
 *   canvas position = (i * 256, (tiles_y - 1 - j) * 256)
 *
 * Non-multiple-of-256 sizes: tiles_x/y are rounded UP (ceil), so the canvas
 * may extend slightly beyond the region on the N/E edges — same trade-off
 * as the OpenSim map viewer itself.
 *
 * ── Special case: no location data ──────────────────────────────────────────
 * If locX/locY/sizeX/sizeY are missing or invalid, a placeholder SVG is
 * served instead of attempting any fetch.
 *
 * ── Caching ───────────────────────────────────────────────────────────────────
 * OpenSim itself can take a long time to regenerate map tiles after a
 * change, so tiles are cached AGGRESSIVELY and effectively permanently:
 *
 *   - Individual zoom-1 tiles (ASSET_CACHE_DIR/map_tiles/, keyed by X-Y) are
 *     cached on disk with NO expiry. Once fetched, a tile is always served
 *     from disk — no repeat network calls, ever — until explicitly
 *     refreshed. These are shared across all regions/requests that touch
 *     the same tile (e.g. adjacent regions' shared border tiles).
 *   - The final composed+resized thumbnail is rebuilt from the (cached)
 *     tiles on every request — cheap, since it's just local file reads +
 *     an Imagick composite/resize, no network involved in the normal case.
 *     It is browser-cached for TILE_BROWSER_CACHE_SECONDS.
 *
 * To pick up real-world changes, the person must explicitly request a
 * refresh (?refresh=1), which re-fetches and overwrites every tile in this
 * region's grid. This is intended to be wired to a "Request new map image"
 * button in the region detail modal (regions.php) — see Things_to_do.md.
 * When refresh=1, the response is NOT browser-cached, so the new image is
 * shown immediately.
 *
 * Requirements:
 *   - php-imagick extension, for compositing/resizing.
 *
 * Security:
 *   - locX/locY/sizeX/sizeY are validated as integers within sane bounds
 *     before being used to build any tile URL.
 *   - Images are served without authentication — map tiles are not
 *     sensitive data.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ── Cache configuration ───────────────────────────────────────────────────────

// Browser cache for the final composed thumbnail in the normal (non-refresh)
// case. The underlying per-tile disk cache has no expiry at all — this only
// governs how often the browser re-requests the (cheap, locally-rebuilt)
// composite image.
const TILE_BROWSER_CACHE_SECONDS = 300;  // 5 minutes

// Tile images returned by the map tile service are always this size, and
// this is also the size of the final composed thumbnail.
const TILE_PIXELS = 256;

// Sanity ceiling on how many zoom-1 tiles we'll fetch/stitch for one region
// (guards against corrupt sizeX/sizeY producing an enormous grid). 16x16 =
// 4096x4096m, comfortably above any region size tested so far.
const MAX_TILES_PER_AXIS = 16;

// ── Read and validate input ───────────────────────────────────────────────────
$locX    = filter_input(INPUT_GET, 'locX',    FILTER_VALIDATE_INT);
$locY    = filter_input(INPUT_GET, 'locY',    FILTER_VALIDATE_INT);
$sizeX   = filter_input(INPUT_GET, 'sizeX',   FILTER_VALIDATE_INT);
$sizeY   = filter_input(INPUT_GET, 'sizeY',   FILTER_VALIDATE_INT);
$refresh = filter_input(INPUT_GET, 'refresh', FILTER_VALIDATE_BOOLEAN);

if ($locX === false || $locX === null || $locX < 0
 || $locY === false || $locY === null || $locY < 0
 || $sizeX === false || $sizeX === null || $sizeX <= 0
 || $sizeY === false || $sizeY === null || $sizeY <= 0) {
    serve_placeholder();
    exit;
}

if (!extension_loaded('imagick')) {
    error_log('region_image.php: php-imagick extension not loaded');
    serve_placeholder();
    exit;
}

// ── Compute tile grid ──────────────────────────────────────────────────────────
$tile_x = intdiv($locX, 256);
$tile_y = intdiv($locY, 256);

$tiles_x = (int)ceil($sizeX / TILE_PIXELS);
$tiles_y = (int)ceil($sizeY / TILE_PIXELS);

$tiles_x = max(1, min(MAX_TILES_PER_AXIS, $tiles_x));
$tiles_y = max(1, min(MAX_TILES_PER_AXIS, $tiles_y));

// ── Fetch and composite tiles ─────────────────────────────────────────────────
try {
    $canvas = new Imagick();
    $canvas->newImage($tiles_x * TILE_PIXELS, $tiles_y * TILE_PIXELS, new ImagickPixel('#1d476f'));
    $canvas->setImageFormat('jpeg');

    for ($j = 0; $j < $tiles_y; $j++) {
        for ($i = 0; $i < $tiles_x; $i++) {
            $body = fetch_tile($tile_x + $i, $tile_y + $j, (bool)$refresh);

            if ($body === null) {
                // Leave this cell as the canvas background colour.
                continue;
            }

            $tile_img = new Imagick();
            $tile_img->readImageBlob($body);

            // Row j=0 (southernmost) goes at the bottom of the canvas.
            $dest_x = $i * TILE_PIXELS;
            $dest_y = ($tiles_y - 1 - $j) * TILE_PIXELS;

            $canvas->compositeImage($tile_img, Imagick::COMPOSITE_OVER, $dest_x, $dest_y);
            $tile_img->destroy();
        }
    }

    if ($tiles_x > 1 || $tiles_y > 1) {
        $canvas->resizeImage(TILE_PIXELS, TILE_PIXELS, Imagick::FILTER_LANCZOS, 1);
    }

    $canvas->setImageCompressionQuality(85);
    $canvas->stripImage();

    $jpeg = $canvas->getImageBlob();
    $canvas->destroy();

    if ($jpeg === false || strlen($jpeg) === 0) {
        throw new ImagickException('getImageBlob returned empty result');
    }
} catch (ImagickException $e) {
    error_log('region_image.php: Imagick composite/resize failed — ' . $e->getMessage());
    serve_placeholder();
    exit;
}

serve_jpeg_data($jpeg, (bool)$refresh);
exit;


// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fetch a single zoom-1 map tile (256x256m, anchored at its own SW corner).
 *
 * Caching is effectively permanent: if a cached copy exists and $force is
 * false, it is always used — no network call, regardless of age. If $force
 * is true (refresh=1), the tile is always re-fetched from the map tile
 * service and the cache file overwritten.
 *
 * Returns the raw JPEG bytes, or null if the tile could not be fetched
 * (network error, non-200, empty body) AND no cached copy exists — callers
 * should treat null as "no data for this cell" and leave it as background
 * colour rather than failing the whole image.
 */
function fetch_tile(int $x, int $y, bool $force = false): ?string
{
    $cache_file = get_tile_cache_path($x, $y);

    if (!$force && $cache_file !== null && file_exists($cache_file)) {
        $data = file_get_contents($cache_file);
        if ($data !== false && strlen($data) > 0) {
            return $data;
        }
    }

    $tile_url = rtrim(MAP_TILE_SERVICE_URL, '/') . '/map-1-' . $x . '-' . $y . '-objects.jpg';

    $ch = curl_init($tile_url);
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

    if ($curl_err !== '' || $body === false || $http !== 200 || strlen($body) === 0) {
        error_log(sprintf(
            'region_image.php: tile fetch failed for %s — HTTP %d, cURL: %s',
            $tile_url, $http, $curl_err
        ));

        // On a forced refresh, fall back to whatever's already cached
        // (better than punching a hole in the image) rather than null.
        if ($cache_file !== null && file_exists($cache_file)) {
            $data = file_get_contents($cache_file);
            if ($data !== false && strlen($data) > 0) {
                return $data;
            }
        }

        return null;
    }

    if ($cache_file !== null) {
        $tmp = $cache_file . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $body) !== false) {
            rename($tmp, $cache_file);
        } else {
            @unlink($tmp);
        }
    }

    return $body;
}

/**
 * Return the disk cache path for a given zoom-1 tile X/Y, creating the
 * cache directory if needed. Returns null if the directory cannot be
 * created or is not writable (caller falls back to fetching without
 * caching).
 */
function get_tile_cache_path(int $x, int $y): ?string
{
    if (!defined('ASSET_CACHE_DIR')) {
        define('ASSET_CACHE_DIR', sys_get_temp_dir() . '/opensim_asset_cache');
    }

    $dir = rtrim(ASSET_CACHE_DIR, '/') . '/map_tiles';

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0750, true)) {
            error_log('region_image.php: cannot create cache directory ' . $dir);
            return null;
        }
    }

    if (!is_writable($dir)) {
        error_log('region_image.php: cache directory not writable: ' . $dir);
        return null;
    }

    return $dir . '/' . $x . '-' . $y . '.jpg';
}

/**
 * Serve a JPEG from an in-memory blob.
 *
 * When $no_cache is true (a ?refresh=1 request), the response is not
 * browser-cached at all, so the refreshed image is shown immediately.
 */
function serve_jpeg_data(string $jpeg, bool $no_cache = false): void
{
    header('Content-Type: image/jpeg');
    if ($no_cache) {
        header('Cache-Control: no-store');
    } else {
        header('Cache-Control: private, max-age=' . TILE_BROWSER_CACHE_SECONDS);
    }
    header('Content-Length: ' . strlen($jpeg));
    echo $jpeg;
}

/**
 * Serve an SVG map-tile placeholder and exit.
 * Used whenever we cannot (or should not) produce a real map tile.
 *
 * Styled as a simple terrain/map icon on a muted surface, distinct from
 * the avatar placeholder in profile_image.php.
 */
function serve_placeholder(): void
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" preserveAspectRatio="xMidYMid slice">'
         . '<rect width="256" height="256" fill="#e4ddf0"/>'
         . '<circle cx="180" cy="64" r="24" fill="#d8cdec"/>'
         . '<polygon points="0,256 70,140 130,200 170,110 256,210 256,256" fill="#c7b8de"/>'
         . '</svg>';

    header('Content-Type: image/svg+xml');
    header('Cache-Control: private, max-age=300');
    echo $svg;
}
