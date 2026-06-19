<?php
/**
 * db.php — Database connection
 *
 * Returns a shared PDO instance connected to the OpenSim MariaDB database.
 * Uses a singleton so the connection is opened once per request.
 *
 * All queries throughout the portal use prepared statements via this
 * connection — no raw string interpolation anywhere.
 *
 * This connection is READ-ONLY by intent. The database user configured
 * in config.php should be granted SELECT privileges only:
 *
 *   GRANT SELECT ON opensim.* TO 'opensim_web'@'localhost';
 *
 * Write operations (account creation, password changes) go through
 * the ROBUST XMLRPC API, not through this connection.
 */

declare(strict_types=1);

function get_db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // use real prepared statements
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET sql_mode='STRICT_ALL_TABLES'",
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Log the real error internally; show nothing useful to the browser.
        error_log('OpenSim portal DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection unavailable.');
    }

    return $pdo;
}
