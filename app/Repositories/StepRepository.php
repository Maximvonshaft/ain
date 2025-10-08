<?php

namespace App\Repositories;

class StepRepository extends BaseRepository
{
    public function listByItem(int $itemId, bool $byCreatedAt = false): array
    {
        $order = $byCreatedAt ? 'created_at ASC, id ASC' : 'order_index ASC, id ASC';
        $stmt = $this->pdo()->prepare("SELECT * FROM steps WHERE item_id = ? ORDER BY $order");
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
    }

    public function countsForItems(array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $this->pdo()->prepare("SELECT item_id, COUNT(*) AS count FROM steps WHERE item_id IN ($placeholders) GROUP BY item_id");
        $stmt->execute($itemIds);
        $rows = $stmt->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['item_id']] = (int)$row['count'];
        }
        return $counts;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM steps WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(int $itemId, string $title, string $notes, bool $done, ?int $orderIndex = null): int
    {
        if ($orderIndex === null) {
            $stmt = $this->pdo()->prepare('SELECT COALESCE(MAX(order_index), -1) FROM steps WHERE item_id=?');
            $stmt->execute([$itemId]);
            $orderIndex = (int)$stmt->fetchColumn() + 1;
        } else {
            $orderIndex = (int)$orderIndex;
        }
        $now = now();
        $stmt = $this->pdo()->prepare('INSERT INTO steps(item_id, title, notes, done, order_index, created_at, updated_at) VALUES(?,?,?,?,?,?,?)');
        $stmt->execute([$itemId, $title, $notes, $done ? 1 : 0, $orderIndex, $now, $now]);
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
        if (array_key_exists('notes', $payload)) {
            $fields[] = 'notes = ?';
            $params[] = $payload['notes'];
        }
        if (array_key_exists('done', $payload)) {
            $fields[] = 'done = ?';
            $params[] = $payload['done'] ? 1 : 0;
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

        $sql = 'UPDATE steps SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM steps WHERE id=?');
        $stmt->execute([$id]);
    }
}
