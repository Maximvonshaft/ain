<?php

namespace App\Repositories;

use App\Models\MindmapNode;
use App\Support\Database;
use PDO;

class MindmapNodeRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @return MindmapNode[]
     */
    public function forMindmap(int $mindmapId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mindmap_nodes WHERE mindmap_id = :mindmap_id ORDER BY id ASC');
        $stmt->execute(['mindmap_id' => $mindmapId]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'map'], $rows);
    }

    public function create(int $mindmapId, array $attributes): MindmapNode
    {
        $stmt = $this->pdo->prepare('INSERT INTO mindmap_nodes (mindmap_id, text, x, y, width, height, style_json, parent_id) VALUES (:mindmap_id, :text, :x, :y, :width, :height, :style_json, :parent_id)');
        $stmt->execute([
            'mindmap_id' => $mindmapId,
            'text' => $attributes['text'] ?? '节点',
            'x' => $attributes['x'] ?? 0,
            'y' => $attributes['y'] ?? 0,
            'width' => $attributes['width'] ?? 160,
            'height' => $attributes['height'] ?? 80,
            'style_json' => $attributes['style_json'] ?? null,
            'parent_id' => $attributes['parent_id'] ?? null,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->find($id);
    }

    public function update(int $id, array $attributes): ?MindmapNode
    {
        $node = $this->find($id);
        if (!$node) {
            return null;
        }

        $fields = [];
        $bindings = ['id' => $id];
        foreach (['text', 'x', 'y', 'width', 'height', 'style_json', 'parent_id'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[] = $field . ' = :' . $field;
                $bindings[$field] = $attributes[$field];
            }
        }

        if (!$fields) {
            return $node;
        }

        $sql = 'UPDATE mindmap_nodes SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mindmap_nodes WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param int $mindmapId
     * @param array<int, array<string, mixed>> $items
     * @return MindmapNode[]
     */
    public function upsertMany(int $mindmapId, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int)$item['id'] : null;
            $payload = [
                'text' => $item['text'] ?? null,
                'x' => $item['x'] ?? null,
                'y' => $item['y'] ?? null,
                'width' => $item['width'] ?? null,
                'height' => $item['height'] ?? null,
                'style_json' => $item['style_json'] ?? null,
                'parent_id' => $item['parent_id'] ?? null,
            ];
            $payload = array_filter(
                $payload,
                static fn($value) => $value !== null
            );

            if ($id) {
                $node = $this->update($id, $payload);
                if ($node) {
                    $result[] = $node;
                }
            } else {
                $node = $this->create($mindmapId, $item + ['mindmap_id' => $mindmapId]);
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * @param int $mindmapId
     * @param int[] $ids
     */
    public function deleteByIds(int $mindmapId, array $ids): void
    {
        if (!$ids) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM mindmap_nodes WHERE mindmap_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$mindmapId], array_map('intval', $ids)));
    }

    public function find(int $id): ?MindmapNode
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mindmap_nodes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->map($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): MindmapNode
    {
        return new MindmapNode(
            id: (int)$row['id'],
            mindmapId: (int)$row['mindmap_id'],
            text: (string)$row['text'],
            x: (float)$row['x'],
            y: (float)$row['y'],
            width: (float)$row['width'],
            height: (float)$row['height'],
            styleJson: $row['style_json'],
            parentId: $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
        );
    }
}
