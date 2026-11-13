<?php
namespace CertGen;

use PDO;

/**
 * Thin PDO wrapper. Every query in the app goes through prepared statements.
 * Works with SQLite (zero setup) or MySQL/MariaDB (XAMPP, shared hosting).
 */
class Db
{
    private static ?PDO $pdo = null;

    public static function driver(): string
    {
        return config('db.driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $c = config('db');
        if (self::driver() === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
            $pdo = new PDO($dsn, $c['user'], $c['pass']);
        } else {
            $path = $c['sqlite_path'];
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path);
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return self::$pdo = $pdo;
    }

    /** For tests: drop the cached connection. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $r = $st->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function ready(): bool
    {
        try {
            self::pdo()->query('SELECT 1 FROM users LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Create all tables if they do not exist. Safe to run repeatedly. */
    public static function migrate(): void
    {
        $my = self::driver() === 'mysql';
        $pk = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $int = $my ? 'INT UNSIGNED' : 'INTEGER';
        $long = $my ? 'LONGTEXT' : 'TEXT';
        $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $pdo = self::pdo();

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            k VARCHAR(100) NOT NULL PRIMARY KEY,
            v $long
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id $pk,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'staff',
            created_at DATETIME NOT NULL
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS templates (
            id $pk,
            name VARCHAR(150) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT '',
            fields_json $long NOT NULL,
            blocks_json $long NOT NULL,
            options_json $long NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
            id $pk,
            template_id $int NOT NULL,
            name VARCHAR(200) NOT NULL,
            created_by $int NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (template_id) REFERENCES templates(id)
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS records (
            id $pk,
            batch_id $int NOT NULL,
            seq $int NOT NULL,
            data_json $long NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
            " . ($my ? ', INDEX idx_records_batch (batch_id)' : '') . "
        )$tail");
        if (!$my) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_records_batch ON records(batch_id)');
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS generation_log (
            id $pk,
            batch_id $int NULL,
            template_name VARCHAR(150) NOT NULL,
            user_name VARCHAR(100) NOT NULL,
            record_count $int NOT NULL,
            mode VARCHAR(10) NOT NULL,
            created_at DATETIME NOT NULL
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id $pk,
            username VARCHAR(100) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL
        )$tail");
    }
}
