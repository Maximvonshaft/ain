<?php

return static function (\PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS memos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        content_md TEXT DEFAULT NULL,
        content_html TEXT DEFAULT NULL,
        is_done INTEGER NOT NULL DEFAULT 0,
        done_at INTEGER DEFAULT NULL,
        pinned INTEGER NOT NULL DEFAULT 0,
        archived INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS subtasks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        memo_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        is_done INTEGER NOT NULL DEFAULT 0,
        "order" INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        FOREIGN KEY (memo_id) REFERENCES memos(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        memo_id INTEGER DEFAULT NULL,
        disk TEXT NOT NULL,
        path TEXT NOT NULL,
        original_name TEXT NOT NULL,
        size_bytes INTEGER NOT NULL,
        mime TEXT NOT NULL,
        sha256 TEXT DEFAULT NULL,
        uploaded_at INTEGER NOT NULL,
        FOREIGN KEY (memo_id) REFERENCES memos(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        color TEXT DEFAULT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS memo_tag (
        memo_id INTEGER NOT NULL,
        tag_id INTEGER NOT NULL,
        PRIMARY KEY (memo_id, tag_id),
        FOREIGN KEY (memo_id) REFERENCES memos(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS mindmaps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        memo_id INTEGER DEFAULT NULL,
        title TEXT NOT NULL,
        canvas_w INTEGER DEFAULT 1024,
        canvas_h INTEGER DEFAULT 768,
        viewport TEXT DEFAULT NULL,
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL,
        FOREIGN KEY (memo_id) REFERENCES memos(id) ON DELETE SET NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS mindmap_nodes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mindmap_id INTEGER NOT NULL,
        text TEXT NOT NULL,
        x REAL NOT NULL DEFAULT 0,
        y REAL NOT NULL DEFAULT 0,
        width REAL DEFAULT 160,
        height REAL DEFAULT 80,
        style_json TEXT DEFAULT NULL,
        parent_id INTEGER DEFAULT NULL,
        FOREIGN KEY (mindmap_id) REFERENCES mindmaps(id) ON DELETE CASCADE,
        FOREIGN KEY (parent_id) REFERENCES mindmap_nodes(id) ON DELETE SET NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS mindmap_edges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        mindmap_id INTEGER NOT NULL,
        from_node_id INTEGER NOT NULL,
        to_node_id INTEGER NOT NULL,
        style_json TEXT DEFAULT NULL,
        FOREIGN KEY (mindmap_id) REFERENCES mindmaps(id) ON DELETE CASCADE,
        FOREIGN KEY (from_node_id) REFERENCES mindmap_nodes(id) ON DELETE CASCADE,
        FOREIGN KEY (to_node_id) REFERENCES mindmap_nodes(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS activity_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor TEXT DEFAULT NULL,
        action TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id INTEGER NOT NULL,
        meta_json TEXT DEFAULT NULL,
        created_at INTEGER NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value_json TEXT DEFAULT NULL
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_memos_is_done_updated_at ON memos(is_done, updated_at DESC)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_subtasks_memo_order ON subtasks(memo_id, "order")');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attachments_memo ON attachments(memo_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tags_slug ON tags(slug)');
};
