<?php

namespace App\Services;

use App\Models\Mindmap;
use App\Repositories\MemoRepository;
use App\Repositories\MindmapEdgeRepository;
use App\Repositories\MindmapNodeRepository;
use App\Repositories\MindmapRepository;
use App\Support\Database;

class MindmapService
{
    public function __construct(
        private MindmapRepository $mindmaps = new MindmapRepository(),
        private MindmapNodeRepository $nodes = new MindmapNodeRepository(),
        private MindmapEdgeRepository $edges = new MindmapEdgeRepository(),
        private MemoRepository $memos = new MemoRepository(),
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForMemo(int $memoId): array
    {
        return array_map(function (Mindmap $mindmap) {
            return $this->serializeMindmap($mindmap);
        }, $this->mindmaps->forMemo($memoId));
    }

    public function createForMemo(int $memoId, string $title): ?array
    {
        $memo = $this->memos->find($memoId);
        if (!$memo) {
            return null;
        }

        $mindmap = $this->mindmaps->create($memoId, $title);
        $root = $this->nodes->create($mindmap->id, [
            'text' => $title,
            'x' => 0,
            'y' => 0,
            'width' => 200,
            'height' => 100,
            'parent_id' => null,
        ]);

        return $this->serializeMindmap($mindmap, [$root], []);
    }

    public function get(int $mindmapId): ?array
    {
        $mindmap = $this->mindmaps->find($mindmapId);
        if (!$mindmap) {
            return null;
        }
        return $this->serializeMindmap($mindmap);
    }

    public function updateProperties(int $mindmapId, array $attributes): ?array
    {
        $updates = [];
        if (isset($attributes['title'])) {
            $title = trim((string)$attributes['title']);
            if ($title === '') {
                throw new \InvalidArgumentException('Title cannot be empty');
            }
            $updates['title'] = $title;
        }
        foreach (['canvas_w', 'canvas_h'] as $dim) {
            if (isset($attributes[$dim])) {
                $updates[$dim] = max(320, (int)$attributes[$dim]);
            }
        }
        if (array_key_exists('viewport', $attributes)) {
            $updates['viewport'] = $attributes['viewport'] !== null ? (string)$attributes['viewport'] : null;
        }

        if (!$updates) {
            return $this->get($mindmapId);
        }

        $mindmap = $this->mindmaps->update($mindmapId, $updates);
        if (!$mindmap) {
            return null;
        }

        return $this->serializeMindmap($mindmap);
    }

    /**
     * @param array{upsert?: array<int, array<string, mixed>>, delete?: int[]} $payload
     */
    public function syncNodes(int $mindmapId, array $payload): array
    {
        $mindmap = $this->mindmaps->find($mindmapId);
        if (!$mindmap) {
            return [];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $upsert = $payload['upsert'] ?? [];
            $delete = $payload['delete'] ?? [];

            if ($upsert) {
                $nodes = $this->nodes->upsertMany($mindmapId, array_map(function ($item) {
                    if (isset($item['text'])) {
                        $item['text'] = trim((string)$item['text']);
                        if ($item['text'] === '') {
                            $item['text'] = '节点';
                        }
                    }
                    if (isset($item['x'])) {
                        $item['x'] = (float)$item['x'];
                    }
                    if (isset($item['y'])) {
                        $item['y'] = (float)$item['y'];
                    }
                    if (isset($item['width'])) {
                        $item['width'] = max(80, (float)$item['width']);
                    }
                    if (isset($item['height'])) {
                        $item['height'] = max(40, (float)$item['height']);
                    }
                    if (isset($item['parent_id'])) {
                        $item['parent_id'] = $item['parent_id'] !== null ? (int)$item['parent_id'] : null;
                    }
                    return $item;
                }, $upsert));
            }

            if ($delete) {
                $this->nodes->deleteByIds($mindmapId, array_map('intval', $delete));
                // Remove edges pointing to deleted nodes
                $placeholders = implode(',', array_fill(0, count($delete), '?'));
                $stmt = $pdo->prepare("DELETE FROM mindmap_edges WHERE mindmap_id = ? AND (from_node_id IN ($placeholders) OR to_node_id IN ($placeholders))");
                $stmt->execute(array_merge([$mindmapId], array_map('intval', $delete), array_map('intval', $delete)));
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->mindmaps->touch($mindmapId);
        $mindmap = $this->mindmaps->find($mindmapId);
        if (!$mindmap) {
            return [];
        }

        return $this->serializeMindmap($mindmap);
    }

    /**
     * @param array{upsert?: array<int, array<string, mixed>>, delete?: int[]} $payload
     */
    public function syncEdges(int $mindmapId, array $payload): array
    {
        $mindmap = $this->mindmaps->find($mindmapId);
        if (!$mindmap) {
            return [];
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $upsert = $payload['upsert'] ?? [];
            $delete = $payload['delete'] ?? [];

            if ($upsert) {
                $this->edges->upsertMany($mindmapId, array_map(function ($item) {
                    if (isset($item['from_node_id'])) {
                        $item['from_node_id'] = (int)$item['from_node_id'];
                    }
                    if (isset($item['to_node_id'])) {
                        $item['to_node_id'] = (int)$item['to_node_id'];
                    }
                    return $item;
                }, $upsert));
            }

            if ($delete) {
                $this->edges->deleteByIds($mindmapId, array_map('intval', $delete));
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->mindmaps->touch($mindmapId);
        $mindmap = $this->mindmaps->find($mindmapId);
        if (!$mindmap) {
            return [];
        }

        return $this->serializeMindmap($mindmap);
    }

    public function delete(int $mindmapId): bool
    {
        return $this->mindmaps->delete($mindmapId);
    }

    /**
     * @param Mindmap $mindmap
     * @param array<int, \App\Models\MindmapNode>|null $nodes
     * @param array<int, \App\Models\MindmapEdge>|null $edges
     */
    private function serializeMindmap(Mindmap $mindmap, ?array $nodes = null, ?array $edges = null): array
    {
        $nodes ??= $this->nodes->forMindmap($mindmap->id);
        $edges ??= $this->edges->forMindmap($mindmap->id);

        return [
            'id' => $mindmap->id,
            'memo_id' => $mindmap->memoId,
            'title' => $mindmap->title,
            'canvas_w' => $mindmap->canvasW,
            'canvas_h' => $mindmap->canvasH,
            'viewport' => $mindmap->viewport,
            'created_at' => $mindmap->createdAt,
            'updated_at' => $mindmap->updatedAt,
            'nodes' => array_map(static function ($node) {
                return [
                    'id' => $node->id,
                    'mindmap_id' => $node->mindmapId,
                    'text' => $node->text,
                    'x' => $node->x,
                    'y' => $node->y,
                    'width' => $node->width,
                    'height' => $node->height,
                    'style_json' => $node->styleJson,
                    'parent_id' => $node->parentId,
                ];
            }, $nodes),
            'edges' => array_map(static function ($edge) {
                return [
                    'id' => $edge->id,
                    'mindmap_id' => $edge->mindmapId,
                    'from_node_id' => $edge->fromNodeId,
                    'to_node_id' => $edge->toNodeId,
                    'style_json' => $edge->styleJson,
                ];
            }, $edges),
        ];
    }
}
