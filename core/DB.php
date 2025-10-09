<?php

namespace Core;

use PDO;
use RuntimeException;

class DB
{
    private const SCHEMA_VERSION = 3;

    private static ?PDO $pdo = null;

    public static function connect(string $path): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }

        $isFresh = !is_file($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');

        self::migrate($pdo, $isFresh);

        self::$pdo = $pdo;

        return self::$pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            throw new RuntimeException('Database not initialized');
        }
        return self::$pdo;
    }

    private static function bootstrap(PDO $pdo): void
    {
        $now = time();
        $pdo->exec('CREATE TABLE IF NOT EXISTS categories(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            created_at INTEGER NOT NULL
        );');
        $pdo->exec('CREATE TABLE IF NOT EXISTS items(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            done INTEGER NOT NULL DEFAULT 0,
            category_id INTEGER,
            previous_category_id INTEGER,
            order_index INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE SET NULL,
            FOREIGN KEY(previous_category_id) REFERENCES categories(id) ON DELETE SET NULL
        );');
        $pdo->exec('CREATE TABLE IF NOT EXISTS steps(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            notes TEXT,
            done INTEGER NOT NULL DEFAULT 0,
            order_index INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE
        );');
        $pdo->exec('CREATE TABLE IF NOT EXISTS attachments(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER,
            step_id INTEGER,
            orig_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY(step_id) REFERENCES steps(id) ON DELETE CASCADE
        );');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_item ON attachments(item_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_step ON attachments(step_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_prev_category ON items(previous_category_id)');
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO categories(name, created_at) VALUES(?,?)');
        foreach (["备忘录", "流程", "其他"] as $name) {
            $stmt->execute([$name, $now]);
        }
    }

    private static function ensureMindmapTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS mindmaps(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        );');
        $pdo->exec('CREATE TABLE IF NOT EXISTS mindmap_assets(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mindmap_id INTEGER,
            node_uid TEXT,
            session_key TEXT,
            orig_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            FOREIGN KEY(mindmap_id) REFERENCES mindmaps(id) ON DELETE CASCADE
        );');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mindmap_assets_map ON mindmap_assets(mindmap_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mindmap_assets_session ON mindmap_assets(session_key)');

        $hasMap = (int)$pdo->query('SELECT COUNT(*) FROM mindmaps')->fetchColumn();
        if ($hasMap === 0) {
            $now = time();
            $defaultPayload = json_encode([
                'meta' => [
                    'name' => 'memo-mindmap',
                    'author' => 'memo.php',
                    'version' => '0.3',
                ],
                'format' => 'node_tree',
                'data' => [
                    'id' => 'root',
                    'topic' => '默认导图',
                    'expanded' => true,
                    'children' => [],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $pdo->prepare('INSERT INTO mindmaps(title, content, created_at, updated_at) VALUES(?,?,?,?)')
                ->execute(['默认导图', $defaultPayload, $now, $now]);
        }
    }

    private static function addPreviousCategoryColumn(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'previous_category_id') {
                return;
            }
        }

        $pdo->exec('ALTER TABLE items ADD COLUMN previous_category_id INTEGER');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_prev_category ON items(previous_category_id)');
    }

    private static function migrate(PDO $pdo, bool $isFresh): void
    {
        if ($isFresh) {
            self::bootstrap($pdo);
            self::ensureMindmapTables($pdo);
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);

            return;
        }

        $currentVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

        if ($currentVersion < 1) {
            self::bootstrap($pdo);
            $currentVersion = 1;
        }

        if ($currentVersion < 2) {
            self::addPreviousCategoryColumn($pdo);
            $currentVersion = 2;
        }

        if ($currentVersion < 3) {
            self::ensureMindmapTables($pdo);
            $currentVersion = 3;
        }

        if ($currentVersion !== self::SCHEMA_VERSION) {
            $pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
        }
    }
}
