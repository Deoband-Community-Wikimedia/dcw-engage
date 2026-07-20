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
