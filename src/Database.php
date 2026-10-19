<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Class Database
 * Secure PDO connection manager and schema initializer.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = Config::get('db_driver', 'sqlite');

        try {
            if ($driver === 'sqlite') {
                $baseDir = Config::get('base_dir', dirname(__DIR__));
                $relativeSqlite = Config::get('db_sqlite_path', 'storage/database.sqlite');
                $sqlitePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeSqlite);

                $dir = dirname($sqlitePath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }

                $dsn = "sqlite:{$sqlitePath}";
                self::$pdo = new PDO($dsn, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                // Enable SQLite Foreign Keys and WAL journal mode for concurrency & safety
                self::$pdo->exec("PRAGMA foreign_keys = ON;");
                self::$pdo->exec("PRAGMA journal_mode = WAL;");
            } else {
                // MySQL
                $host = Config::get('db_host', '127.0.0.1');
                $port = Config::get('db_port', 3306);
                $name = Config::get('db_name', 'payment_gateway');
                $user = Config::get('db_user', 'root');
                $pass = Config::get('db_pass', '');

                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            }

            self::initializeSchema();
            return self::$pdo;
        } catch (PDOException $e) {
            Logger::error("Database connection failure: " . $e->getMessage());
            throw new \RuntimeException("Database service is currently unavailable.");
        }
    }

    /**
     * Creates tables if they do not exist
     */
    public static function initializeSchema(): void
    {
        if (self::$pdo === null) {
            return;
        }

        $driver = Config::get('db_driver', 'sqlite');

        if ($driver === 'sqlite') {
            self::$pdo->exec("
                CREATE TABLE IF NOT EXISTS orders (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    order_ref VARCHAR(64) UNIQUE NOT NULL,
                    product_code VARCHAR(64) NOT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    amount DECIMAL(10, 2) NOT NULL,
                    currency VARCHAR(3) NOT NULL,
                    customer_name VARCHAR(150) NOT NULL,
                    customer_email VARCHAR(150) NOT NULL,
                    country VARCHAR(2) NOT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
                    tco_refno VARCHAR(64) DEFAULT NULL,
                    metadata TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE INDEX IF NOT EXISTS idx_orders_ref ON orders(order_ref);
                CREATE INDEX IF NOT EXISTS idx_orders_tco_refno ON orders(tco_refno);
                CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);

                CREATE TABLE IF NOT EXISTS transactions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    order_ref VARCHAR(64) NOT NULL,
                    tco_refno VARCHAR(64) DEFAULT NULL,
                    type VARCHAR(30) NOT NULL,
                    amount DECIMAL(10, 2) NOT NULL,
                    currency VARCHAR(3) NOT NULL,
                    status VARCHAR(30) NOT NULL,
                    payload TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (order_ref) REFERENCES orders(order_ref) ON DELETE CASCADE
                );

                CREATE TABLE IF NOT EXISTS ipn_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ipn_refno VARCHAR(64) NOT NULL,
                    ipn_orderno VARCHAR(64) DEFAULT NULL,
                    order_status VARCHAR(64) NOT NULL,
                    payload TEXT NOT NULL,
                    processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(ipn_refno, order_status)
                );
            ");
        } else {
            self::$pdo->exec("
                CREATE TABLE IF NOT EXISTS orders (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    order_ref VARCHAR(64) UNIQUE NOT NULL,
                    product_code VARCHAR(64) NOT NULL,
                    product_name VARCHAR(255) NOT NULL,
                    amount DECIMAL(10, 2) NOT NULL,
                    currency VARCHAR(3) NOT NULL,
                    customer_name VARCHAR(150) NOT NULL,
                    customer_email VARCHAR(150) NOT NULL,
                    country VARCHAR(2) NOT NULL,
                    status VARCHAR(30) NOT NULL DEFAULT 'PENDING',
                    tco_refno VARCHAR(64) DEFAULT NULL,
                    metadata TEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX (order_ref),
                    INDEX (tco_refno),
                    INDEX (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS transactions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    order_ref VARCHAR(64) NOT NULL,
                    tco_refno VARCHAR(64) DEFAULT NULL,
                    type VARCHAR(30) NOT NULL,
                    amount DECIMAL(10, 2) NOT NULL,
                    currency VARCHAR(3) NOT NULL,
                    status VARCHAR(30) NOT NULL,
                    payload MEDIUMTEXT DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX (order_ref),
                    INDEX (tco_refno)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS ipn_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    ipn_refno VARCHAR(64) NOT NULL,
                    ipn_orderno VARCHAR(64) DEFAULT NULL,
                    order_status VARCHAR(64) NOT NULL,
                    payload MEDIUMTEXT NOT NULL,
                    processed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_ref_status (ipn_refno, order_status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        }
    }
}
