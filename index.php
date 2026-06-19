<?php
/**
 * index.php — Site entry point
 *
 * This file contains no logic or UI. It simply redirects to the correct
 * page based on session state:
 *
 *   Logged in  → profile.php
 *   Not logged in → login.php
 *
 * The root URL (https://portal.sub-version.space/) resolves here via
 * Nginx's default index directive. To prevent /index.php from appearing
 * in the browser address bar, add to the Nginx server block:
 *
 *   if ($request_uri = "/index.php") {
 *       return 301 /;
 *   }
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();

if (is_logged_in()) {
    header('Location: profile.php');
} else {
    header('Location: login.php');
}
exit;
