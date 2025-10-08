<?php

namespace App\Repositories;

class ItemRepository extends BaseRepository
{
    public function list(array $filters = []): array
    {
        $sql = 'SELECT items.*, categories.name AS category_name FROM items '
            . 'LEFT JOIN categories ON categories.id = items.category_id';
        $where = [];
        $params = [];

        if (array_key_exists('category_id', $filters)) {
            $categoryId = $filters['category_id'];
            if ($categoryId === null) {
                $where[] = 'items.category_id IS NULL';
            } else {
                $where[] = 'items.category_id = :category_id';
                $params[':category_id'] = (int)$categoryId;
            }
        }

        if (array_key_exists('done', $filters)) {
            $where[] = 'items.done = :done';
            $params[':done'] = $filters['done'] ? 1 : 0;
        }

        if (!empty($filters['query'])) {
            $where[] = '(items.title LIKE :query OR items.description LIKE :query)';
            $params[':query'] = '%' . $filters['query'] . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $order = $this->resolveOrder($filters['sort'] ?? null);
        $sql .= ' ' . $order;

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT items.*, categories.name AS category_name FROM items '
            . 'LEFT JOIN categories ON categories.id = items.category_id WHERE items.id=? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $title, string $description, ?int $categoryId): int
    {
        $maxOrder = (int)$this->pdo()->query('SELECT COALESCE(MAX(order_index), -1) FROM items')->fetchColumn();
        $orderIndex = $maxOrder + 1;
        $now = now();
        $stmt = $this->pdo()->prepare('INSERT INTO items(title, description, done, category_id, order_index, created_at, updated_at) '
            . 'VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$title, $description, 0, $categoryId, $orderIndex, $now, $now]);
        return (int)$this->pdo()->lastInsertId();
    }

    public function update(int $id, array $payload): void
    {
        $fields = [];
        $params = [];
        if (array_key_exists('title', $payload)) {
            $fields[] = 'title = ?';
            $params[] = $payload['title'];
        }
        if (array_key_exists('description', $payload)) {
            $fields[] = 'description = ?';
            $params[] = $payload['description'];
        }
        if (array_key_exists('category_id', $payload)) {
            $fields[] = 'category_id = ?';
            $params[] = $payload['category_id'];
        }
        if (array_key_exists('done', $payload)) {
            $fields[] = 'done = ?';
            $params[] = $payload['done'] ? 1 : 0;
        }
        if (array_key_exists('previous_category_id', $payload)) {
            $fields[] = 'previous_category_id = ?';
            $params[] = $payload['previous_category_id'];
        }
        if (array_key_exists('order_index', $payload)) {
            $fields[] = 'order_index = ?';
            $params[] = $payload['order_index'];
        }
        if (!$fields) {
            return;
        }
        $fields[] = 'updated_at = ?';
        $params[] = now();
        $params[] = $id;

        $sql = 'UPDATE items SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM items WHERE id=?');
        $stmt->execute([$id]);
    }

    public function touch(int $id): void
    {
        $stmt = $this->pdo()->prepare('UPDATE items SET updated_at = ? WHERE id = ?');
        $stmt->execute([now(), $id]);
    }

    public function reassignCategory(int $fromId, ?int $toId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE items SET category_id = ? WHERE category_id = ?');
        $stmt->execute([$toId, $fromId]);
        $stmtPrev = $this->pdo()->prepare('UPDATE items SET previous_category_id = ? WHERE previous_category_id = ?');
        $stmtPrev->execute([$toId, $fromId]);
    }

    public function countsByCategory(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare("SELECT category_id, COUNT(*) AS count FROM items WHERE category_id IN ($placeholders) GROUP BY category_id");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['category_id']] = (int)$row['count'];
        }
        return $counts;
    }

    public function countByCategory(?int $categoryId): int
    {
        if ($categoryId === null) {
            $stmt = $this->pdo()->query('SELECT COUNT(*) FROM items WHERE category_id IS NULL');
            return (int)$stmt->fetchColumn();
        }
        $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM items WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        return (int)$stmt->fetchColumn();
    }

    public function maxOrderIndex(): int
    {
        return (int)$this->pdo()->query('SELECT COALESCE(MAX(order_index), -1) FROM items')->fetchColumn();
    }

    public function latestUpdatedAt(): int
    {
        return (int)$this->pdo()->query('SELECT COALESCE(MAX(updated_at), 0) FROM items')->fetchColumn();
    }

    private function resolveOrder(?string $sort): string
    {
        $sort = (string)$sort;
        return match ($sort) {
            'created_at' => 'ORDER BY created_at DESC, id DESC',
            'title' => 'ORDER BY title COLLATE NOCASE ASC',
            'done' => 'ORDER BY done ASC, updated_at DESC',
            default => 'ORDER BY order_index ASC, updated_at DESC, id DESC',
        };
    }
}
