<?php

namespace App\Controllers;

use Core\DB;
use Core\Request;
use PDO;

use function bytes_h;
use function format_datetime;

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
        $recentFiles = $this->loadRecentAssets($pdo, $memoUrl);
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
            LIMIT 5'
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
            'SELECT id, title, content, updated_at FROM mindmaps ORDER BY updated_at DESC, id DESC LIMIT 5'
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
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentAssets(PDO $pdo, string $memoUrl): array
    {
        $stmt = $pdo->prepare(
            'SELECT a.id, a.context, a.item_id, a.step_id, a.mindmap_id, a.orig_name, a.mime, a.size, a.created_at, a.stored_name,
                    i.title AS item_title,
                    st.item_id AS step_item_id,
                    si.title AS step_item_title,
                    m.title AS mindmap_title
             FROM attachments AS a
             LEFT JOIN items AS i ON i.id = a.item_id
             LEFT JOIN steps AS st ON st.id = a.step_id
             LEFT JOIN items AS si ON si.id = st.item_id
             LEFT JOIN mindmaps AS m ON m.id = a.mindmap_id
             WHERE a.context IN ("memo:item", "memo:step", "mindmap:node")
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 5'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        $result = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $stored = (string)($row['stored_name'] ?? '');
            if ($stored === '') {
                continue;
            }

            $context = (string)($row['context'] ?? 'memo:item');
            $createdAt = isset($row['created_at']) ? (int)$row['created_at'] : 0;
            $createdLabel = $createdAt > 0 ? '上传 ' . format_datetime($createdAt) : '';
            $createdIso = $createdAt > 0 ? date('c', $createdAt) : '';
            $size = isset($row['size']) ? (int)$row['size'] : 0;
            $sizeLabel = $size > 0 ? bytes_h($size) : '';
            $fileName = trim((string)($row['orig_name'] ?? ''));
            if ($fileName === '') {
                $fileName = '未命名附件';
            }

            $contextLabel = match ($context) {
                'memo:step' => 'STEP',
                'mindmap:node' => 'MINDMAP',
                default => 'MEMO',
            };

            $ownerLabel = '';
            $ownerUrl = '';
            $resolvedItemId = (int)($row['item_id'] ?? 0);
            $stepItemId = (int)($row['step_item_id'] ?? 0);
            if ($resolvedItemId <= 0 && $stepItemId > 0) {
                $resolvedItemId = $stepItemId;
            }

            if ($context === 'mindmap:node') {
                $mapId = (int)($row['mindmap_id'] ?? 0);
                $mapTitle = trim((string)($row['mindmap_title'] ?? ''));
                if ($mapTitle === '') {
                    $mapTitle = '未命名导图';
                }
                $ownerLabel = '导图 · ' . $mapTitle;
                if ($mapId > 0) {
                    $ownerUrl = $memoUrl . '?view=map_edit&id=' . $mapId;
                }
            } else {
                $itemTitle = trim((string)($row['item_title'] ?? ''));
                $stepItemTitle = trim((string)($row['step_item_title'] ?? ''));
                $title = $itemTitle !== '' ? $itemTitle : $stepItemTitle;
                if ($title === '') {
                    $title = '未命名条目';
                }
                if ($context === 'memo:step') {
                    $ownerLabel = '步骤 · ' . $title;
                } else {
                    $ownerLabel = '备忘录 · ' . $title;
                }
                if ($resolvedItemId > 0) {
                    $ownerUrl = $memoUrl . '?view=item&id=' . $resolvedItemId;
                }
            }

            $result[] = [
                'id' => $id,
                'name' => $fileName,
                'context' => $context,
                'context_label' => $contextLabel,
                'owner_label' => $ownerLabel,
                'owner_url' => $ownerUrl,
                'size_label' => $sizeLabel,
                'created_label' => $createdLabel,
                'created_iso' => $createdIso,
                'download_url' => $memoUrl . '?download=' . $id,
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
