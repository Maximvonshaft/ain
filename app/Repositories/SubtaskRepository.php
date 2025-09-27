<?php

namespace App\Repositories;

use App\Models\Subtask;
use App\Support\Database;
use PDO;

class SubtaskRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @return Subtask[]
     */
    public function forMemo(int $memoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subtasks WHERE memo_id = :memo_id ORDER BY "order" ASC');
        $stmt->execute(['memo_id' => $memoId]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'map'], $rows);
    }

    public function create(int $memoId, string $title): Subtask
    {
        $now = time();
        $stmt = $this->pdo->prepare('INSERT INTO subtasks (memo_id, title, is_done, "order", updated_at, created_at) VALUES (:memo_id, :title, 0, :order, :updated_at, :created_at)');
        $order = $this->nextOrder($memoId);
        $stmt->execute([
            'memo_id' => $memoId,
            'title' => $title,
            'order' => $order,
            'updated_at' => $now,
            'created_at' => $now,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->find($id);
    }

    public function find(int $id): ?Subtask
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subtasks WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->map($row) : null;
    }

    public function toggleDone(int $id): ?Subtask
    {
        $subtask = $this->find($id);
        if (!$subtask) {
            return null;
        }

        $isDone = !$subtask->isDone;
        $stmt = $this->pdo->prepare('UPDATE subtasks SET is_done = :is_done, updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'is_done' => $isDone ? 1 : 0,
            'updated_at' => time(),
            'id' => $id,
        ]);

        return $this->find($id);
    }

    public function reorder(array $orderedIds): void
    {
        $stmt = $this->pdo->prepare('UPDATE subtasks SET "order" = :order, updated_at = :updated_at WHERE id = :id');
        $order = 0;
        $now = time();
        foreach ($orderedIds as $id) {
            $stmt->execute([
                'order' => $order++,
                'updated_at' => $now,
                'id' => $id,
            ]);
        }
    }

    private function nextOrder(int $memoId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX("order") FROM subtasks WHERE memo_id = :memo_id');
        $stmt->execute(['memo_id' => $memoId]);
        $value = $stmt->fetchColumn();
        return $value !== null ? ((int)$value + 1) : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Subtask
    {
        return new Subtask(
            id: (int)$row['id'],
            memoId: (int)$row['memo_id'],
            title: (string)$row['title'],
            isDone: (bool)$row['is_done'],
            order: (int)$row['order'],
            updatedAt: (int)$row['updated_at'],
            createdAt: (int)$row['created_at'],
        );
    }
}

