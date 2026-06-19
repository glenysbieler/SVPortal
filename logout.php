<?php
/**
 * logout.php — Ends the authenticated session
 *
 * Destroys the session cleanly and redirects to the login page.
 * No output is produced — this page only redirects.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

session_start_secure();
logout();  // destroys session and redirects to login.php
