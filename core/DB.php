<?php

namespace Core;

use PDO;
use RuntimeException;

class DB
{
    private const SCHEMA_VERSION = 2;

    private static ?PDO $pdo = null;

    public static function connect(string $path): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        self::runMigrations($pdo);
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

    private static function runMigrations(PDO $pdo): void
    {
        $currentVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();

        if ($currentVersion < 1) {
            self::ensureBaseTables($pdo);
            self::seedDefaultCategories($pdo);
            self::setUserVersion($pdo, 1);
            $currentVersion = 1;
        } else {
            self::ensureBaseTables($pdo);
        }

        if ($currentVersion < self::SCHEMA_VERSION) {
            self::ensureMindmapSchema($pdo);
            self::setUserVersion($pdo, self::SCHEMA_VERSION);
        }
    }

    private static function setUserVersion(PDO $pdo, int $version): void
    {
        $pdo->exec('PRAGMA user_version = ' . max(0, $version));
    }

    private static function ensureBaseTables(PDO $pdo): void
    {
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
            order_index INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            previous_category_id INTEGER,
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
        self::ensureItemIndexes($pdo);
        self::ensurePreviousCategoryColumn($pdo);
    }

    private static function ensurePreviousCategoryColumn(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC);
        $hasColumn = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? null) === 'previous_category_id') {
                $hasColumn = true;
                break;
            }
        }
        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE items ADD COLUMN previous_category_id INTEGER');
        }
    }

    private static function ensureItemIndexes(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_done_order ON items(done, order_index, updated_at DESC, id DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_category_order ON items(category_id, order_index, updated_at DESC, id DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_done_category ON items(done, category_id)');
    }

    private static function seedDefaultCategories(PDO $pdo): void
    {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        if ($count > 0) {
            return;
        }
        $stmt = $pdo->prepare('INSERT INTO categories(name, created_at) VALUES(?,?)');
        $now = time();
        foreach (["备忘录", "流程", "其他"] as $name) {
            $stmt->execute([$name, $now]);
        }
    }

    private static function ensureMindmapSchema(PDO $pdo): void
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
}
