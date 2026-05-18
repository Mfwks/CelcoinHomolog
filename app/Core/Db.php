<?php

namespace App\Core;

use PDO;
use PDOException;

class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dir = defined('TMP') ? TMP . 'cslabs' : sys_get_temp_dir() . '/cslabs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $dsn = 'sqlite:' . $dir . '/cslabs.sqlite';

        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('SQLite indisponível: ' . $e->getMessage(), 0, $e);
        }

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$pdo = $pdo;
        self::ensureSchema($pdo);

        return $pdo;
    }

    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $work($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS entities (
                client_id  TEXT NOT NULL,
                type       TEXT NOT NULL,
                id         TEXT NOT NULL,
                entity_key TEXT,
                data       TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (client_id, type, id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS entities_listing_idx
                ON entities (client_id, type, entity_key)
        SQL);
    }
}
