<?php

namespace App\Repositories;

class CategoryRepository extends BaseRepository
{
    public function all(): array
    {
        $stmt = $this->pdo()->query('SELECT id, name FROM categories ORDER BY name COLLATE NOCASE');
        return $stmt->fetchAll();
    }

    public function counts(): array
    {
        $stmt = $this->pdo()->query('SELECT category_id, COUNT(*) AS count FROM items GROUP BY category_id');
        $rows = $stmt->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['category_id']] = (int)$row['count'];
        }
        return $counts;
    }

    public function stats(): array
    {
        $stmt = $this->pdo()->query('SELECT
            (SELECT COUNT(*) FROM items WHERE done = 0) AS active_total,
            (SELECT COUNT(*) FROM items WHERE done = 0 AND category_id IS NULL) AS active_uncategorized
        ');
        $row = $stmt->fetch();
        return [
            'active_total' => (int)($row['active_total'] ?? 0),
            'active_uncategorized' => (int)($row['active_uncategorized'] ?? 0),
        ];
    }

    public function ensureOther(): int
    {
        $stmt = $this->pdo()->query("SELECT id FROM categories WHERE name='其他' LIMIT 1");
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id'];
        }
        $insert = $this->pdo()->prepare('INSERT INTO categories(name, created_at) VALUES(?,?)');
        $insert->execute(['其他', now()]);
        return (int)$this->pdo()->lastInsertId();
    }

    public function create(string $name): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO categories(name, created_at) VALUES(?,?)');
        $stmt->execute([$name, now()]);
        return (int)$this->pdo()->lastInsertId();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM categories WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function ensureDone(): int
    {
        $stmt = $this->pdo()->query("SELECT id FROM categories WHERE name='已完成' LIMIT 1");
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['id'];
        }

        $insert = $this->pdo()->prepare('INSERT INTO categories(name, created_at) VALUES(?,?)');
        $insert->execute(['已完成', now()]);
        return (int)$this->pdo()->lastInsertId();
    }

    public function update(int $id, string $name): void
    {
        $stmt = $this->pdo()->prepare('UPDATE categories SET name=? WHERE id=?');
        $stmt->execute([$name, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM categories WHERE id=?');
        $stmt->execute([$id]);
    }
}
