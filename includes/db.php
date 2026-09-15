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
            // Deliberately NOT persistent. A persistent connection is reused
            // across unrelated requests by the same PHP worker, so if it gets
            // killed mid-transaction by the host's MySQL connection-rate
            // limiting (which we've hit repeatedly), the next request to reuse
            // it inherits a connection in an undefined state instead of a
            // clean failure. On a shared account already fighting a
            // per-account connection cap, holding one connection open per
            // worker indefinitely also eats into that budget for no benefit.
            // See incident notes from 2026-09-15/16 before re-enabling this.
        ];

        try {
            $this->pdo = new PDO($dsn, $config['db']['user'], $config['db']['password'], $options);
        } catch (PDOException $e) {
            // Prevent leaking credentials in error logs
            error_log("Database Connection Error: " . $e->getMessage());

            // A plain die() here used to return HTTP 200 with no cache
            // headers, so the server cache stored the failure page and kept
            // serving it to every later visitor long after MySQL recovered —
            // a ~30 second spike looked like an all-day outage. 503 tells
            // caches and crawlers this is temporary; no-store keeps the
            // response out of the cache entirely.
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
