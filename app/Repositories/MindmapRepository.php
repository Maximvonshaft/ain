<?php

namespace App\Repositories;

use App\Models\Mindmap;
use App\Support\Database;
use PDO;

class MindmapRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @return Mindmap[]
     */
    public function forMemo(int $memoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mindmaps WHERE memo_id = :memo_id ORDER BY updated_at DESC');
        $stmt->execute(['memo_id' => $memoId]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'map'], $rows);
    }

    public function find(int $id): ?Mindmap
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mindmaps WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->map($row) : null;
    }

    public function create(int $memoId, string $title, array $attributes = []): Mindmap
    {
        $now = time();
        $stmt = $this->pdo->prepare('INSERT INTO mindmaps (memo_id, title, canvas_w, canvas_h, viewport, created_at, updated_at) VALUES (:memo_id, :title, :canvas_w, :canvas_h, :viewport, :created_at, :updated_at)');
        $stmt->execute([
            'memo_id' => $attributes['memo_id'] ?? $memoId,
            'title' => $title,
            'canvas_w' => $attributes['canvas_w'] ?? 1024,
            'canvas_h' => $attributes['canvas_h'] ?? 768,
            'viewport' => $attributes['viewport'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->find($id);
    }

    public function update(int $id, array $attributes): ?Mindmap
    {
        $mindmap = $this->find($id);
        if (!$mindmap) {
            return null;
        }

        $fields = [];
        $bindings = ['id' => $id];
        foreach (['title', 'canvas_w', 'canvas_h', 'viewport', 'memo_id'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[] = $field . ' = :' . $field;
                $bindings[$field] = $attributes[$field];
            }
        }
        if (!$fields) {
            return $mindmap;
        }

        $fields[] = 'updated_at = :updated_at';
        $bindings['updated_at'] = time();

        $sql = 'UPDATE mindmaps SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM mindmaps WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function touch(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE mindmaps SET updated_at = :updated_at WHERE id = :id');
        $stmt->execute([
            'updated_at' => time(),
            'id' => $id,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): Mindmap
    {
        return new Mindmap(
            id: (int)$row['id'],
            memoId: $row['memo_id'] !== null ? (int)$row['memo_id'] : null,
            title: (string)$row['title'],
            canvasW: (int)$row['canvas_w'],
            canvasH: (int)$row['canvas_h'],
            viewport: $row['viewport'],
            createdAt: (int)$row['created_at'],
            updatedAt: (int)$row['updated_at'],
        );
    }
}
