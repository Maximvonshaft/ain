<?php

declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

class StepRepository extends BaseRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function forItem(int $itemId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM steps WHERE item_id = ? ORDER BY order_index ASC, id ASC');
        $stmt->execute([$itemId]);
        $rows = $stmt->fetchAll();

        return array_map($this->formatStep(...), $rows);
    }

    public function create(int $itemId, string $title): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('步骤标题必填');
        }

        $pdo = $this->pdo();
        $now = now();
        $stmt = $pdo->prepare(
            'INSERT INTO steps(item_id, title, notes, done, order_index, created_at, updated_at) VALUES(?,?,?,?,0,?,?)'
        );
        $stmt->execute([$itemId, $title, '', 0, $now, $now]);
        $id = (int)$pdo->lastInsertId();

        return $this->find($id) ?? [
            'id' => $id,
            'item_id' => $itemId,
            'title' => $title,
            'notes' => '',
            'done' => 0,
            'order_index' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM steps WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->formatStep($row) : null;
    }

    public function toggle(int $id, bool $done): array
    {
        $step = $this->find($id);
        if (!$step) {
            throw new RuntimeException('指定的步骤不存在');
        }

        $now = now();
        $stmt = $this->pdo()->prepare('UPDATE steps SET done = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$done ? 1 : 0, $now, $id]);

        return [
            'id' => $id,
            'item_id' => $step['item_id'],
            'done' => $done,
            'updated_at' => $now,
        ];
    }

    public function updateTitle(int $id, string $title): void
    {
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('步骤标题必填');
        }

        $stmt = $this->pdo()->prepare('UPDATE steps SET title = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$title, now(), $id]);
    }

    public function updateNotes(int $id, string $notes): void
    {
        $stmt = $this->pdo()->prepare('UPDATE steps SET notes = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$notes, now(), $id]);
    }

    public function delete(int $id): ?array
    {
        $step = $this->find($id);
        if (!$step) {
            return null;
        }

        $stmt = $this->pdo()->prepare('DELETE FROM steps WHERE id = ?');
        $stmt->execute([$id]);

        return $step;
    }

    public function reorder(int $itemId, array $ids): void
    {
        if (!$ids) {
            return;
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE steps SET order_index = ?, updated_at = ?, item_id = item_id WHERE id = ? AND item_id = ?');
            $index = 0;
            $now = now();
            foreach ($ids as $id) {
                if (!is_int($id)) {
                    continue;
                }
                $stmt->execute([$index++, $now, $id, $itemId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int> $itemIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function groupedByItem(array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }

        $ids = array_values(array_filter($itemIds, fn ($id) => is_int($id) && $id > 0));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM steps WHERE item_id IN ($placeholders) ORDER BY item_id ASC, order_index ASC, id ASC"
        );
        $stmt->execute($ids);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $itemId = (int)$row['item_id'];
            $grouped[$itemId] ??= [];
            $grouped[$itemId][] = $this->formatStep($row);
        }

        return $grouped;
    }

    private function formatStep(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['item_id'] = (int)$row['item_id'];
        $row['done'] = (int)$row['done'];
        $row['order_index'] = (int)$row['order_index'];
        $row['created_at'] = (int)$row['created_at'];
        $row['updated_at'] = (int)$row['updated_at'];

        return $row;
    }
}
