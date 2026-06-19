<?php
/**
 * assets.php — Asset service helpers
 *
 * Handles fetching images from the ROBUST asset service and converting
 * JPEG2000 codestreams to browser-readable formats via php-imagick.
 *
 * Special UUIDs:
 *   c228d1cf-4b5d-4ba8-84f4-899a0796aa97  default avatar texture (UV skin map)
 *   00000000-0000-0000-0000-000000000000  null UUID — no image set
 *
 * Both are intercepted and replaced with a local placeholder.
 */

define('UUID_NULL',    '00000000-0000-0000-0000-000000000000');
define('UUID_DEFAULT', 'c228d1cf-4b5d-4ba8-84f4-899a0796aa97');

/**
 * Returns the URL to use for a profile image.
 *
 * Intercepts null and default skin UUIDs and returns the SVG placeholder.
 * All other UUIDs route through profile_image.php which fetches from the
 * ROBUST asset service and converts J2K → PNG via php-imagick.
 *
 * @param  string $image_uuid
 * @return string  URL safe to use in an <img src="">
 */
function get_profile_image_url(string $image_uuid): string {
    if ($image_uuid === UUID_NULL || $image_uuid === UUID_DEFAULT || empty($image_uuid)) {
        return get_placeholder_url();
    }
    return 'profile_image.php?uuid=' . urlencode($image_uuid);
}

/**
 * Returns the URL to use for a pick image.
 *
 * @param  string $image_uuid
 * @return string
 */
function get_pick_image_url(string $image_uuid): string {
    if ($image_uuid === UUID_NULL || empty($image_uuid)) {
        return get_pick_placeholder_url();
    }
    return 'profile_image.php?uuid=' . urlencode($image_uuid);
}

/**
 * SVG placeholder for profile pictures — a neutral avatar silhouette.
 * Returned as a data URI so no separate file is needed.
 */
function get_placeholder_url(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">'
         . '<rect width="200" height="200" fill="#e8e0f0"/>'
         . '<circle cx="100" cy="80" r="40" fill="#c4b5d4"/>'
         . '<ellipse cx="100" cy="170" rx="60" ry="45" fill="#c4b5d4"/>'
         . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * SVG placeholder for pick images — a simple landscape suggestion.
 */
function get_pick_placeholder_url(): string {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180">'
         . '<rect width="320" height="180" fill="#ddd6f0"/>'
         . '<rect y="110" width="320" height="70" fill="#b8aed4"/>'
         . '<circle cx="260" cy="50" r="30" fill="#f0e6c8" opacity="0.7"/>'
         . '<polygon points="60,110 100,50 140,110" fill="#9b8fc4" opacity="0.6"/>'
         . '<polygon points="130,110 180,40 230,110" fill="#8a7db8" opacity="0.5"/>'
         . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
