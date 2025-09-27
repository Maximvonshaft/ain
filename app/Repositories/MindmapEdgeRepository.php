<?php

namespace App\Repositories;

use App\Models\MindmapEdge;
use App\Support\Database;
use PDO;

class MindmapEdgeRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @return MindmapEdge[]
     */
    public function forMindmap(int $mindmapId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mindmap_edges WHERE mindmap_id = :mindmap_id ORDER BY id ASC');
        $stmt->execute(['mindmap_id' => $mindmapId]);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'map'], $rows);
    }

    public function create(int $mindmapId, array $attributes): MindmapEdge
    {
        $stmt = $this->pdo->prepare('INSERT INTO mindmap_edges (mindmap_id, from_node_id, to_node_id, style_json) VALUES (:mindmap_id, :from_node_id, :to_node_id, :style_json)');
        $stmt->execute([
            'mindmap_id' => $mindmapId,
            'from_node_id' => $attributes['from_node_id'],
            'to_node_id' => $attributes['to_node_id'],
            'style_json' => $attributes['style_json'] ?? null,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return $this->find($id);
    }

    public function update(int $id, array $attributes): ?MindmapEdge
    {
        $edge = $this->find($id);
        if (!$edge) {
            return null;
        }

        $fields = [];
        $bindings = ['id' => $id];
        foreach (['from_node_id', 'to_node_id', 'style_json'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $fields[] = $field . ' = :' . $field;
                $bindings[$field] = $attributes[$field];
            }
        }

        if (!$fields) {
            return $edge;
        }

        $sql = 'UPDATE mindmap_edges SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM mindmap_edges WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param int $mindmapId
     * @param array<int, array<string, mixed>> $items
     * @return MindmapEdge[]
     */
    public function upsertMany(int $mindmapId, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int)$item['id'] : null;
            $payload = [
                'from_node_id' => $item['from_node_id'] ?? null,
                'to_node_id' => $item['to_node_id'] ?? null,
                'style_json' => $item['style_json'] ?? null,
            ];
            $payload = array_filter(
                $payload,
                static fn($value) => $value !== null
            );

            if ($id) {
                $edge = $this->update($id, $payload);
                if ($edge) {
                    $result[] = $edge;
                }
            } else {
                if (!isset($item['from_node_id'], $item['to_node_id'])) {
                    continue;
                }
                $edge = $this->create($mindmapId, $item + ['mindmap_id' => $mindmapId]);
                $result[] = $edge;
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
        $stmt = $this->pdo->prepare("DELETE FROM mindmap_edges WHERE mindmap_id = ? AND id IN ($placeholders)");
        $stmt->execute(array_merge([$mindmapId], array_map('intval', $ids)));
    }

    public function find(int $id): ?MindmapEdge
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mindmap_edges WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->map($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): MindmapEdge
    {
        return new MindmapEdge(
            id: (int)$row['id'],
            mindmapId: (int)$row['mindmap_id'],
            fromNodeId: (int)$row['from_node_id'],
            toNodeId: (int)$row['to_node_id'],
            styleJson: $row['style_json'],
        );
    }
}
