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

        $memoUrl = $prefix . '/memo.php';
        $mindmapUrl = $memoUrl . '?cat=mindmaps';

        $todoItems = $this->loadTodoItems($pdo, $memoUrl);
        $mindmaps = $this->loadMindmaps($pdo, $memoUrl);

        require __DIR__ . '/../../resources/views/portal/index.phtml';
    }

    /**
     * @return array<int, array{id:int,title:string,done:bool,updated_at:int|null,updated_label:string,updated_iso:string,url:string}>
     */
    private function loadTodoItems(PDO $pdo, string $memoUrl): array
    {
        $stmt = $pdo->prepare(
            'SELECT items.id, items.title, items.done, items.updated_at
            FROM items
            ORDER BY items.updated_at DESC, items.id DESC
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
            $updatedAt = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            $updatedLabel = $updatedAt ? '最近修改 ' . format_datetime($updatedAt) : '';
            $updatedIso = $updatedAt ? date('c', $updatedAt) : '';

            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? '未命名任务'),
                'done' => $done,
                'updated_at' => $updatedAt,
                'updated_label' => $updatedLabel,
                'updated_iso' => $updatedIso,
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
            ['status' => 'active', 'label' => 'ACTIVE'],
            ['status' => 'standby', 'label' => 'STANDBY'],
            ['status' => 'deploy', 'label' => 'DEPLOY'],
            ['status' => 'archive', 'label' => 'ARCHIVE'],
        ];

        $result = [];
        foreach ($rows as $index => $row) {
            $statusMeta = $statusCycle[$index % count($statusCycle)];
            $summaryData = $this->summariseMindmap($row['content'] ?? null);
            $updatedAt = isset($row['updated_at']) ? (int)$row['updated_at'] : null;
            $updatedLabel = $updatedAt ? '最近修改 ' . format_datetime($updatedAt) : '';
            $updatedIso = $updatedAt ? date('c', $updatedAt) : '';

            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => (string)($row['title'] ?? '未命名导图'),
                'status' => $statusMeta['status'],
                'status_label' => $statusMeta['label'],
                'summary' => $summaryData['summary'],
                'nodes' => $summaryData['nodes'],
                'node_count' => $summaryData['node_count'],
                'updated_at' => $updatedAt,
                'updated_label' => $updatedLabel,
                'updated_iso' => $updatedIso,
                'url' => $memoUrl . '?view=map_edit&id=' . ((int)($row['id'] ?? 0)),
            ];
        }

        return $result;
    }

    /**
     * @return array{summary:string,nodes:array<int,string>,node_count:int}
     */
    private function summariseMindmap(?string $payload): array
    {
        if (!is_string($payload) || $payload === '') {
            return [
                'summary' => '',
                'nodes' => [],
                'node_count' => 0,
            ];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [
                'summary' => '',
                'nodes' => [],
                'node_count' => 0,
            ];
        }

        $data = $decoded['data'] ?? null;
        if (!is_array($data)) {
            return [
                'summary' => '',
                'nodes' => [],
                'node_count' => 0,
            ];
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
            if (count($childTopics) >= 3) {
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

        return [
            'summary' => $summary,
            'nodes' => $childTopics,
            'node_count' => $nodeCount,
        ];
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
