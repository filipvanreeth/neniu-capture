<?php

declare(strict_types=1);

namespace Neniu\Capture;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $connection;

    public function __construct(string $storage)
    {
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            throw new RuntimeException('Cannot create storage directory');
        }

        $path = $storage . '/verify.sqlite';
        $db = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        chmod($path, 0600);
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('CREATE TABLE IF NOT EXISTS records (
            code TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            accuracy_meters REAL NOT NULL,
            source TEXT NOT NULL,
            mime TEXT NOT NULL,
            sha256 TEXT NOT NULL,
            image BLOB NOT NULL
        )');
        $columns = $db->query('PRAGMA table_info(records)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('metadata', $columns, true)) {
            $db->exec("ALTER TABLE records ADD COLUMN metadata TEXT NOT NULL DEFAULT '{}'");
        }
        $db->exec('CREATE TABLE IF NOT EXISTS verification_sessions (
            token_hash TEXT PRIMARY KEY,
            session_id TEXT NOT NULL,
            issued_at TEXT NOT NULL,
            expires_at INTEGER NOT NULL
        )');
        $this->connection = $db;
    }

    public function connection(): PDO
    {
        return $this->connection;
    }
}
