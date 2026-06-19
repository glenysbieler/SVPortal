<?php
/**
 * theme_loader.php — Theme discovery and portal preferences cookie helper
 *
 * COOKIE FORMAT
 * -------------
 * A single JSON cookie named 'portal_prefs' replaces the old 'portal_bg' cookie.
 * It stores per-device display preferences that persist for one year.
 *
 * Structure:
 *   {
 *     "theme": "default",   // active theme folder name
 *     "bg":    true         // background image enabled
 *   }
 *
 * Back-compat: if 'portal_prefs' is absent but 'portal_bg' is present, the
 * legacy value is migrated transparently. The old cookie is not explicitly
 * deleted — it simply expires naturally.
 *
 * THEME DISCOVERY
 * ---------------
 * A "theme" is any subdirectory of /themes/ that contains a CSS file named
 * <foldername>.css. No manual registration is needed. Optionally a file named
 * <foldername>-thumbnail.png (or .jpg / .webp) in the same folder provides a
 * thumbnail for the picker UI (currently unused — see theme_swatch_colors()).
 *
 * BACKGROUND IMAGES
 * -----------------
 * Each theme may provide a /themes/<name>/bgimages/ folder containing any
 * number of .jpg / .jpeg / .png / .webp files. theme_bg_image_url() picks one
 * at random on each call. If the active theme has no bgimages folder (or it's
 * empty), themes/default/bgimages/ is used instead — so child themes only
 * need to supply images if they want a different look from default.
 * Used for both the dimmed background overlay on logged-in pages and the
 * splash/login/register backgrounds on logged-out pages.
 *
 * PUBLIC API
 * ----------
 *   get_portal_prefs()          → ['theme' => string, 'bg' => bool]
 *   get_active_theme()          → string (theme folder name)
 *   get_active_theme_css_url()  → string (root-relative URL to active theme CSS)
 *   discover_themes()           → array of theme info arrays
 *   theme_thumbnail_url(string) → string|null (root-relative URL or null)
 *   theme_swatch_colors(string) → ['main' => string, 'accent' => string] (hex colours)
 *   theme_bg_image_url(?string)  → string|null (root-relative URL to a random bg image)
 */

declare(strict_types=1);

// ─── Constants ────────────────────────────────────────────────────────────────

/** Absolute filesystem path to the /themes directory.
 *  Assumes themes/ lives alongside this includes/ directory.
 */
define('THEMES_DIR', dirname(__DIR__) . '/themes');

/** Root-relative URL prefix for themes. */
define('THEMES_URL', '/themes');

/** Cookie name for all portal display preferences. */
define('PORTAL_PREFS_COOKIE', 'portal_prefs');


// ─── Portal preferences ───────────────────────────────────────────────────────

/**
 * Read and validate the portal_prefs cookie, falling back to defaults or
 * migrating the legacy portal_bg cookie if necessary.
 *
 * @return array{theme: string, bg: bool}
 */
function get_portal_prefs(): array
{
    $default_theme = defined('DEFAULT_THEME') ? DEFAULT_THEME : 'default';
    $defaults = ['theme' => $default_theme, 'bg' => true];

    if (!empty($_COOKIE[PORTAL_PREFS_COOKIE])) {
        $decoded = json_decode($_COOKIE[PORTAL_PREFS_COOKIE], true);
        if (is_array($decoded)) {
            $theme = isset($decoded['theme']) && is_string($decoded['theme'])
                ? $decoded['theme']
                : $default_theme;
            // Validate theme exists; fall back if not
            if (!theme_exists($theme)) {
                $theme = $default_theme;
            }
            $bg = isset($decoded['bg']) ? (bool)$decoded['bg'] : true;
            return ['theme' => $theme, 'bg' => $bg];
        }
    }

    // Migrate legacy portal_bg cookie
    if (isset($_COOKIE['portal_bg'])) {
        return [
            'theme' => $default_theme,
            'bg'    => $_COOKIE['portal_bg'] !== '0',
        ];
    }

    return $defaults;
}

/**
 * Return the active theme folder name (validated against discovered themes).
 */
function get_active_theme(): string
{
    return get_portal_prefs()['theme'];
}

/**
 * Return the root-relative URL to the active theme's CSS file.
 */
function get_active_theme_css_url(): string
{
    $theme = get_active_theme();
    return THEMES_URL . '/' . $theme . '/' . $theme . '.css';
}


// ─── Theme discovery ──────────────────────────────────────────────────────────

/**
 * Check whether a named theme exists on disk.
 */
function theme_exists(string $name): bool
{
    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
        return false;   // reject path traversal attempts
    }
    $css = THEMES_DIR . '/' . $name . '/' . $name . '.css';
    return is_file($css);
}

/**
 * Discover all installed themes by scanning the themes directory.
 *
 * Returns an array of associative arrays, each with:
 *   'name'          => string  folder / theme identifier
 *   'css_url'       => string  root-relative URL to the CSS file
 *   'thumbnail_url' => string|null  root-relative URL to thumbnail, or null
 *   'swatch'        => array{main: string, accent: string}  hex colours for picker
 *
 * Themes are returned in alphabetical order, with 'default' always first.
 */
function discover_themes(): array
{
    if (!is_dir(THEMES_DIR)) {
        return [];
    }

    $themes = [];
    $entries = scandir(THEMES_DIR);
    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $dir = THEMES_DIR . '/' . $entry;
        if (!is_dir($dir)) {
            continue;
        }
        // Must contain <name>.css
        if (!is_file($dir . '/' . $entry . '.css')) {
            continue;
        }
        // Reject names with suspicious characters
        if (!preg_match('/^[a-z0-9_-]+$/i', $entry)) {
            continue;
        }

        $themes[] = [
            'name'          => $entry,
            'css_url'       => THEMES_URL . '/' . $entry . '/' . $entry . '.css',
            'thumbnail_url' => theme_thumbnail_url($entry),
            'swatch'        => theme_swatch_colors($entry),
        ];
    }

    // Sort: 'default' first, rest alphabetical
    usort($themes, function (array $a, array $b): int {
        if ($a['name'] === 'default') return -1;
        if ($b['name'] === 'default') return  1;
        return strcmp($a['name'], $b['name']);
    });

    return $themes;
}

/**
 * Return the root-relative URL for a named image file within a theme,
 * falling back to the default theme folder if the file doesn't exist in
 * the active theme.
 *
 * This means child themes only need to include images they actually want
 * to replace. Any image not present in the child theme is served from
 * themes/default/ automatically.
 *
 * Example:
 *   theme_image_url('headerimageblurred.png')
 *   → '/themes/ocean/headerimageblurred.png'  (if file exists there)
 *   → '/themes/default/headerimageblurred.png' (fallback)
 *
 * @param string $filename  The image filename (e.g. 'background.png')
 * @param string|null $theme  Theme name, defaults to the active theme
 */
function theme_image_url(string $filename, ?string $theme = null): string
{
    $theme ??= get_active_theme();

    if (!preg_match('/^[a-z0-9_-]+$/i', $theme)) {
        $theme = 'default';
    }

    $path = THEMES_DIR . '/' . $theme . '/' . $filename;
    if (is_file($path)) {
        return THEMES_URL . '/' . $theme . '/' . $filename;
    }

    // Fall back to default theme
    return THEMES_URL . '/default/' . $filename;
}

/**
 * Return the root-relative URL to a theme's thumbnail image, or null if none.
 * Checks for .png, then .jpg, then .webp.
 */
function theme_thumbnail_url(string $name): ?string
{
    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
        return null;
    }
    $dir = THEMES_DIR . '/' . $name;
    foreach (['.png', '.jpg', '.webp'] as $ext) {
        $file = $dir . '/' . $name . '-thumbnail' . $ext;
        if (is_file($file)) {
            return THEMES_URL . '/' . $name . '/' . $name . '-thumbnail' . $ext;
        }
    }
    return null;
}

/**
 * Extract the value of a CSS custom property (e.g. '--clr-lilac-deep') from
 * the :root { ... } block of a CSS file, by simple regex.
 *
 * This is intentionally lightweight — it does not parse @import chains or
 * full CSS; it only looks at custom property declarations physically present
 * in the given file's :root block. Returns null if not found or file unreadable.
 *
 * @param string $css_path  Absolute filesystem path to a CSS file
 * @param string $var_name  Custom property name, including leading '--'
 */
function css_var_from_file(string $css_path, string $var_name): ?string
{
    if (!is_file($css_path) || !is_readable($css_path)) {
        return null;
    }
    $css = file_get_contents($css_path);
    if ($css === false) {
        return null;
    }

    // Isolate the first :root { ... } block (non-greedy, single level of braces)
    if (!preg_match('/:root\s*\{(.*?)\}/s', $css, $root_match)) {
        return null;
    }

    $escaped = preg_quote($var_name, '/');
    if (preg_match('/' . $escaped . '\s*:\s*([^;]+);/', $root_match[1], $val_match)) {
        return trim($val_match[1]);
    }

    return null;
}

/**
 * Return representative swatch colours for a theme, for use in the theme
 * picker UI.
 *
 * Reads '--clr-lilac-deep' (main colour) and '--clr-rose-accent' (accent
 * colour) directly from the theme's own CSS file. Child themes only need to
 * override the tokens they actually change — any token NOT redefined in the
 * theme's own :root block falls back to the value from default.css, so the
 * swatch always reflects what the theme actually renders as.
 *
 * @param string $name  Theme folder / identifier name
 * @return array{main: string, accent: string}
 */
function theme_swatch_colors(string $name): array
{
    // Fallback defaults match default.css design tokens.
    $fallback = [
        'main'   => '#8b68c4', // --clr-lilac-deep
        'accent' => '#e8698a', // --clr-rose-accent
    ];

    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) {
        return $fallback;
    }

    $default_css = THEMES_DIR . '/default/default.css';
    $theme_css   = THEMES_DIR . '/' . $name . '/' . $name . '.css';

    $main = css_var_from_file($theme_css, '--clr-lilac-deep')
        ?? css_var_from_file($default_css, '--clr-lilac-deep')
        ?? $fallback['main'];

    $accent = css_var_from_file($theme_css, '--clr-rose-accent')
        ?? css_var_from_file($default_css, '--clr-rose-accent')
        ?? $fallback['accent'];

    return ['main' => $main, 'accent' => $accent];
}


// ─── Background images ────────────────────────────────────────────────────────

/** Recognised background image file extensions. */
const THEME_BG_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];

/**
 * Return a list of background image filenames found directly inside
 * /themes/<name>/bgimages/, or an empty array if the folder doesn't exist
 * or contains no recognised image files.
 *
 * @param string $name  Theme folder / identifier name (already validated)
 * @return string[]
 */
function theme_bg_image_files(string $name): array
{
    $dir = THEMES_DIR . '/' . $name . '/bgimages';
    if (!is_dir($dir)) {
        return [];
    }

    $files = [];
    foreach (new \DirectoryIterator($dir) as $f) {
        if ($f->isFile() && in_array(strtolower($f->getExtension()), THEME_BG_IMAGE_EXTS, true)) {
            $files[] = $f->getFilename();
        }
    }
    return $files;
}

/**
 * Return the root-relative URL to a randomly chosen background image for a
 * theme, or null if neither the theme nor the default theme has any.
 *
 * Looks in /themes/<theme>/bgimages/ first; if that folder is missing or
 * empty, falls back to /themes/default/bgimages/ (same fallback pattern as
 * theme_image_url()). A different random image may be returned on every call.
 *
 * @param string|null $theme  Theme name, defaults to the active theme
 */
function theme_bg_image_url(?string $theme = null): ?string
{
    $theme ??= get_active_theme();

    if (!preg_match('/^[a-z0-9_-]+$/i', $theme)) {
        $theme = 'default';
    }

    $files = theme_bg_image_files($theme);
    if ($files) {
        return THEMES_URL . '/' . $theme . '/bgimages/' . $files[array_rand($files)];
    }

    if ($theme !== 'default') {
        $files = theme_bg_image_files('default');
        if ($files) {
            return THEMES_URL . '/default/bgimages/' . $files[array_rand($files)];
        }
    }

    return null;
}
