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

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS interactions (
                client_id       TEXT NOT NULL,
                request_id      TEXT NOT NULL,
                received_at     TEXT NOT NULL,
                received_at_us  REAL NOT NULL,
                worker_id       TEXT,
                method          TEXT,
                path            TEXT,
                status_code     INTEGER,
                data            TEXT NOT NULL,
                PRIMARY KEY (client_id, request_id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS interactions_listing_idx
                ON interactions (client_id, received_at_us DESC)
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS client_workers (
                client_id    TEXT NOT NULL,
                worker_id    TEXT NOT NULL,
                ip           TEXT,
                auth_hint    TEXT,
                last_seen_at TEXT,
                data         TEXT NOT NULL,
                PRIMARY KEY (client_id, worker_id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS client_origins (
                client_id      TEXT NOT NULL,
                ip_hash        TEXT NOT NULL,
                ip             TEXT,
                worker_id      TEXT,
                user_agent     TEXT,
                first_seen_at  TEXT,
                last_seen_at   TEXT,
                data           TEXT NOT NULL,
                PRIMARY KEY (client_id, ip_hash)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS webhook_dispatches (
                client_id    TEXT NOT NULL,
                webhook_id   TEXT NOT NULL,
                request_id   TEXT,
                event        TEXT,
                status       TEXT,
                target_url   TEXT,
                created_at   TEXT NOT NULL,
                updated_at   TEXT NOT NULL,
                data         TEXT NOT NULL,
                PRIMARY KEY (client_id, webhook_id)
            )
        SQL);

        $pdo->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS webhook_dispatches_by_request_idx
                ON webhook_dispatches (client_id, request_id)
        SQL);
    }
}
