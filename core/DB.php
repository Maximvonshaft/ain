<?php

namespace Core;

use PDO;
use RuntimeException;
use Throwable;

class DB
{
    private const SCHEMA_VERSION = 4;

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

        if ($currentVersion < 2) {
            self::ensureMindmapSchema($pdo);
            self::setUserVersion($pdo, 2);
            $currentVersion = 2;
        } else {
            self::ensureMindmapSchema($pdo);
        }

        self::ensureItemIndexes($pdo);

        if ($currentVersion < self::SCHEMA_VERSION) {
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
            context TEXT NOT NULL DEFAULT "memo:item",
            item_id INTEGER,
            step_id INTEGER,
            mindmap_id INTEGER,
            node_uid TEXT,
            session_key TEXT,
            orig_name TEXT NOT NULL,
            stored_name TEXT NOT NULL,
            mime TEXT NOT NULL,
            size INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            FOREIGN KEY(item_id) REFERENCES items(id) ON DELETE CASCADE,
            FOREIGN KEY(step_id) REFERENCES steps(id) ON DELETE CASCADE
        );');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_steps_item_order ON steps(item_id, order_index, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_steps_item_created ON steps(item_id, created_at, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_item ON attachments(item_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_step ON attachments(step_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_context_created ON attachments(context, created_at DESC, id DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_mindmap ON attachments(mindmap_id, context)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_session ON attachments(session_key)');
        self::ensureUnifiedAttachments($pdo);
        self::ensurePortalTables($pdo);
        self::ensurePreviousCategoryColumn($pdo);
        self::ensureItemIndexes($pdo);
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
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_items_category_order ON items(category_id, done, order_index, updated_at DESC, id DESC)');
    }

    private static function ensurePortalTables(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS portal_directives(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nickname TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_portal_directives_created ON portal_directives(created_at DESC, id DESC)');
    }

    private static function ensureUnifiedAttachments(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(attachments)')->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($columns as $column) {
            $name = $column['name'] ?? null;
            if ($name !== null) {
                $names[] = $name;
            }
        }

        if (!in_array('context', $names, true)) {
            $pdo->exec('ALTER TABLE attachments ADD COLUMN context TEXT NOT NULL DEFAULT "memo:item"');
            $pdo->exec("UPDATE attachments SET context = CASE WHEN step_id IS NOT NULL THEN 'memo:step' ELSE 'memo:item' END WHERE context IS NULL OR context = ''");
        }

        if (!in_array('mindmap_id', $names, true)) {
            $pdo->exec('ALTER TABLE attachments ADD COLUMN mindmap_id INTEGER');
        }

        if (!in_array('node_uid', $names, true)) {
            $pdo->exec('ALTER TABLE attachments ADD COLUMN node_uid TEXT');
        }

        if (!in_array('session_key', $names, true)) {
            $pdo->exec('ALTER TABLE attachments ADD COLUMN session_key TEXT');
        }

        $pdo->exec("UPDATE attachments SET context = 'memo:step' WHERE context = 'memo:item' AND step_id IS NOT NULL");
        $pdo->exec("UPDATE attachments SET context = 'memo:item' WHERE context IS NULL OR context = ''");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_context_created ON attachments(context, created_at DESC, id DESC)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_mindmap ON attachments(mindmap_id, context)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_att_session ON attachments(session_key)');
    }

    private static function migrateMindmapAssets(PDO $pdo): void
    {
        $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='mindmap_assets'")->fetchColumn();
        if ($tableExists === 0) {
            return;
        }

        $count = (int)$pdo->query('SELECT COUNT(*) FROM mindmap_assets')->fetchColumn();
        if ($count === 0) {
            $pdo->exec('DROP TABLE mindmap_assets');
            return;
        }

        $pdo->beginTransaction();
        try {
            $select = $pdo->query('SELECT id, mindmap_id, node_uid, session_key, orig_name, stored_name, mime, size, created_at FROM mindmap_assets ORDER BY id ASC');
            $rows = $select ? $select->fetchAll() : [];
            $insert = $pdo->prepare('INSERT INTO attachments(context, item_id, step_id, mindmap_id, node_uid, session_key, orig_name, stored_name, mime, size, created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($rows as $row) {
                $mindmapId = isset($row['mindmap_id']) ? (int)$row['mindmap_id'] : null;
                $sessionKey = isset($row['session_key']) && $row['session_key'] !== '' ? (string)$row['session_key'] : null;
                $context = $mindmapId ? 'mindmap:node' : 'mindmap:session';
                $createdAt = isset($row['created_at']) ? (int)$row['created_at'] : now();
                $insert->execute([
                    $context,
                    null,
                    null,
                    $mindmapId ?: null,
                    $row['node_uid'] ?? null,
                    $sessionKey,
                    $row['orig_name'] ?? '',
                    $row['stored_name'] ?? '',
                    $row['mime'] ?? 'application/octet-stream',
                    isset($row['size']) ? (int)$row['size'] : 0,
                    $createdAt,
                ]);
            }
            $pdo->exec('DROP TABLE mindmap_assets');
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
        self::ensureUnifiedAttachments($pdo);
        self::migrateMindmapAssets($pdo);

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
