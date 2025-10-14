<?php

namespace App\Controllers;

use Core\DB;
use Core\Request;
use PDO;

final class PortalController
{
    public function index(Request $request): void
    {
        $pdo = DB::pdo();

        $basePath = $request->basePath();
        $prefix = $basePath === '' ? '' : $basePath;

        $asset = static function (string $path) use ($prefix): string {
            $normalized = '/assets/portal/' . ltrim($path, '/');
            return $prefix . $normalized;
        };

        $memoUrl = $prefix . '/memo';
        $mindmapUrl = $memoUrl . '?cat=mindmaps';

        $todoItems = $this->loadTodoItems($pdo, $memoUrl);
        $mindmaps = $this->loadMindmaps($pdo, $memoUrl);

        require __DIR__ . '/../../resources/views/portal/index.phtml';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTodoItems(PDO $pdo, string $memoUrl): array
    {
        $stmt = $pdo->prepare(
            'SELECT items.id, items.title, items.done, items.updated_at, categories.name AS category_name
            FROM items
            LEFT JOIN categories ON categories.id = items.category_id
            ORDER BY items.done ASC, items.order_index ASC, items.updated_at DESC, items.id DESC
            LIMIT 4'
        );
        $stmt->execute();
        $items = $stmt->fetchAll();
        if (!$items) {
            return [];
        }

        $result = [];
        foreach ($items as $row) {
            $done = (int)($row['done'] ?? 0) === 1;
            $metaParts = [];
            if (!$done) {
                $categoryName = is_string($row['category_name'] ?? null) ? trim($row['category_name']) : '';
                if ($categoryName !== '') {
                    $metaParts[] = $categoryName;
                }
            } else {
                $metaParts[] = '已完成';
            }

            $updatedAt = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            if ($updatedAt) {
                $metaParts[] = '更新 ' . format_datetime($updatedAt);
            }

            $meta = implode(' · ', $metaParts);

            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? '未命名任务'),
                'done' => $done,
                'meta' => $meta,
                'url' => $memoUrl . '?view=item&id=' . ((int)($row['id'] ?? 0)),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadMindmaps(PDO $pdo, string $memoUrl): array
    {
        $stmt = $pdo->query(
            'SELECT id, title, content, updated_at FROM mindmaps ORDER BY updated_at DESC, id DESC LIMIT 4'
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        if (!$rows) {
            return [];
        }

        $statusCycle = [
            ['status' => 'active', 'label' => 'ACTIVE', 'preview' => 'polar'],
            ['status' => 'standby', 'label' => 'STANDBY', 'preview' => 'desert'],
            ['status' => 'deploy', 'label' => 'DEPLOY', 'preview' => 'coast'],
            ['status' => 'archive', 'label' => 'ARCHIVE', 'preview' => 'urban'],
        ];

        $result = [];
        foreach ($rows as $index => $row) {
            $statusMeta = $statusCycle[$index % count($statusCycle)];
            $summary = $this->summariseMindmap($row['content'] ?? null);
            $updatedAt = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            $meta = $updatedAt ? '更新 ' . format_datetime($updatedAt) : '暂无更新记录';

            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? '未命名导图'),
                'status' => $statusMeta['status'],
                'status_label' => $statusMeta['label'],
                'preview' => $statusMeta['preview'],
                'summary' => $summary,
                'meta' => $meta,
                'url' => $memoUrl . '?view=map_edit&id=' . ((int)($row['id'] ?? 0)),
            ];
        }

        return $result;
    }

    private function summariseMindmap(?string $payload): string
    {
        if (!is_string($payload) || $payload === '') {
            return '';
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return '';
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            return '';
        }

        $topic = trim((string)($data['topic'] ?? ''));
        $children = [];
        if (isset($data['children']) && is_array($data['children'])) {
            $children = $data['children'];
        }

        $childTopics = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $childTopic = trim((string)($child['topic'] ?? ''));
            if ($childTopic === '') {
                continue;
            }
            $childTopics[] = $childTopic;
            if (count($childTopics) >= 2) {
                break;
            }
        }

        $nodeCount = $this->countMindmapNodes($data);

        if ($childTopics !== []) {
            $summary = implode(' · ', $childTopics);
        } elseif ($topic !== '') {
            $summary = $topic;
        } else {
            $summary = '';
        }

        if ($summary !== '') {
            $summary .= ' · 节点 ' . $nodeCount;
        } else {
            $summary = '节点 ' . $nodeCount;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function countMindmapNodes(array $node): int
    {
        $count = 1;
        $children = $node['children'] ?? [];
        if (!is_array($children)) {
            return $count;
        }
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $count += $this->countMindmapNodes($child);
        }
        return $count;
    }
}
