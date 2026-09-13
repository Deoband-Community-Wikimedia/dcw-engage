<?php
/**
 * DCW Engage - Database Wrapper (Singleton)
 * 
 * Strict PDO implementation to prevent SQL injection.
 */

class DB {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        $config = require __DIR__ . '/config.php';
        
        $dsn = "mysql:host=" . $config['db']['host'] . ";dbname=" . $config['db']['name'] . ";charset=" . $config['db']['charset'];
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Fail hard on SQL errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Strictly use real prepared statements
        ];

        try {
            $this->pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], $options);
        } catch (PDOException $e) {
            // Prevent leaking credentials in error logs
            error_log("Database Connection Error: " . $e->getMessage());

            // This used to `die()` with a plain 200 and no cache headers, which
            // meant the server cache happily stored the failure and served it to
            // every later visitor. A ~30 second MySQL spike then looked like an
            // all-day outage: the homepage and the live scholarship form both
            // showed this message long after the database had recovered, and the
            // only way to see the real page was a cache-busting query string.
            //
            // 503 tells caches and crawlers this is temporary, no-store keeps the
            // response out of the cache entirely, and noindex stops the error
            // being indexed as if it were the page's content.
            if (!headers_sent()) {
                http_response_code(503);
                header('Retry-After: 60');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('X-Robots-Tag: noindex');
            }

            die("A database error occurred. Please try again later.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new DB();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }
}
