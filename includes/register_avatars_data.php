<?php
/**
 * register_avatars_data.php — Starter avatar picker data endpoint
 *
 * Public, unauthenticated JSON endpoint (same access model as
 * register.php itself — this only ever runs for people who are not yet
 * registered) that returns the resolved starter avatar options for the
 * registration page's avatar-selection step.
 *
 * Kept as a separate endpoint rather than inline in register.php so the
 * picker data (which does live DB + profile image lookups per avatar) is
 * only fetched when the step-2 UI actually needs it, not on every
 * register.php page load/POST.
 *
 * Gated behind the same FEATURE_REGISTRATION flag as register.php, plus
 * an explicit STARTER_AVATARS non-empty check — if either is false this
 * returns an empty list rather than an error, since "no starter avatars
 * configured" is a normal state, not a failure.
 *
 * Request:
 *   GET register_avatars_data.php
 *
 * Response (JSON):
 *   { "ok": true, "avatars": [ { "key": "...", "label": "...", "image_url": "..." }, ... ] }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/register_avatars.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!defined('FEATURE_REGISTRATION') || !FEATURE_REGISTRATION) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Registration is not available.']);
    exit;
}

try {
    echo json_encode([
        'ok'      => true,
        'avatars' => get_starter_avatar_options(),
    ]);
} catch (Throwable $e) {
    error_log('register_avatars_data.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load starter avatars. Please try again.']);
}
