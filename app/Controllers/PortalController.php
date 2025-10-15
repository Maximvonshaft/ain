<?php

namespace App\Controllers;

use App\Middlewares\CsrfMiddleware;
use Core\DB;
use Core\Request;
use PDO;
use RuntimeException;

final class PortalController
{
    public function __construct(private CsrfMiddleware $csrf)
    {
    }

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
        $files = $this->loadFiles($pdo, $memoUrl);
        $directives = $this->loadDirectives($pdo);
        $directiveToken = $this->csrf->token($request);
        $directiveEndpoint = $prefix . '/portal/directives';

        require __DIR__ . '/../../resources/views/portal/index.phtml';
    }

    public function storeDirective(Request $request): void
    {
        try {
            $this->csrf->verify($request);
        } catch (RuntimeException) {
            http_response_code(419);
            $this->respondDirectiveError('会话已失效，请刷新页面后重试。');
            return;
        }

        $nickname = $this->sanitizeDirectiveText((string)$request->input('nickname', ''), 24);
        $message = $this->sanitizeDirectiveText((string)$request->input('message', ''), 140);

        if ($nickname === '' || $message === '') {
            http_response_code(422);
            $this->respondDirectiveError('昵称与留言内容均不能为空。');
            return;
        }

        $pdo = DB::pdo();
        $now = now();
        $count = (int)$pdo->query('SELECT COUNT(*) FROM directives')->fetchColumn();
        $priority = $this->nextPriority($count);

        $stmt = $pdo->prepare('INSERT INTO directives(nickname, message, priority, created_at) VALUES(?,?,?,?)');
        $stmt->execute([$nickname, $message, $priority, $now]);
        $id = (int)$pdo->lastInsertId();

        $entry = [
            'id' => $id,
            'nickname' => $this->formatDirectiveNickname($nickname),
            'message' => $this->formatDirectiveMessage($message),
            'priority' => $priority,
            'priority_label' => $this->priorityLabel($priority),
            'time_label' => date('H:i:s', $now),
            'time_iso' => date('c', $now),
        ];

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(201);
        echo json_encode([
            'ok' => true,
            'entry' => $entry,
        ], JSON_UNESCAPED_UNICODE);
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
     * @return array<int, array<string, mixed>>
     */
    private function loadFiles(PDO $pdo, string $memoUrl): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, kind, item_id, step_id, mindmap_id, orig_name, mime, size, created_at
            FROM files ORDER BY created_at DESC, id DESC LIMIT 6'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $kind = (string)($row['kind'] ?? '');
            $createdAt = isset($row['created_at']) ? (int)$row['created_at'] : null;
            $timeLabel = $createdAt ? format_datetime($createdAt) : '';
            $timeIso = $createdAt ? date('c', $createdAt) : '';
            $sizeLabel = bytes_h((int)($row['size'] ?? 0));
            $metaParts = array_filter([
                $this->describeFileSource($kind, $row),
                $timeLabel,
                $sizeLabel,
            ]);

            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['orig_name'] ?? '未命名附件'),
                'code' => $this->fileCode($kind, $row),
                'meta' => implode(' · ', $metaParts),
                'time_iso' => $timeIso,
                'download_url' => $memoUrl . '?file=' . ((int)($row['id'] ?? 0)),
            ];
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadDirectives(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT id, nickname, message, priority, created_at
            FROM directives ORDER BY created_at DESC, id DESC LIMIT 6'
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        if (!$rows) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $priority = $this->normalizePriority((string)($row['priority'] ?? ''));
            $createdAt = isset($row['created_at']) ? (int)$row['created_at'] : now();
            $result[] = [
                'id' => (int)($row['id'] ?? 0),
                'nickname' => $this->formatDirectiveNickname((string)($row['nickname'] ?? '')),
                'message' => $this->formatDirectiveMessage((string)($row['message'] ?? '')),
                'priority' => $priority,
                'priority_label' => $this->priorityLabel($priority),
                'time_label' => date('H:i:s', $createdAt),
                'time_iso' => date('c', $createdAt),
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

    private function respondDirectiveError(string $message): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => $message,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function sanitizeDirectiveText(string $value, int $limit): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $normalized = preg_replace('/\s+/u', ' ', $value);
        if (is_string($normalized)) {
            $value = trim($normalized);
        }
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($value, 'UTF-8') > $limit) {
                $value = mb_substr($value, 0, $limit, 'UTF-8');
            }
        } elseif (strlen($value) > $limit) {
            $value = substr($value, 0, $limit);
        }

        return $value;
    }

    private function nextPriority(int $count): string
    {
        $priorities = ['high', 'medium', 'low'];
        if ($count < 0) {
            $count = 0;
        }
        return $priorities[$count % count($priorities)];
    }

    private function normalizePriority(string $priority): string
    {
        return in_array($priority, ['high', 'medium', 'low'], true) ? $priority : 'medium';
    }

    private function priorityLabel(string $priority): string
    {
        return match ($priority) {
            'high' => '高',
            'low' => '低',
            default => '中',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fileCode(string $kind, array $row): string
    {
        if ($kind === 'memo') {
            return isset($row['step_id']) && (int)$row['step_id'] > 0 ? 'STEP' : 'MEMO';
        }
        if ($kind === 'mindmap') {
            return 'MAP';
        }
        $normalized = strtoupper($kind);
        return $normalized !== '' ? $normalized : 'FILE';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function describeFileSource(string $kind, array $row): string
    {
        if ($kind === 'memo') {
            $stepId = isset($row['step_id']) ? (int)$row['step_id'] : 0;
            if ($stepId > 0) {
                return '步骤 #' . $stepId;
            }
            $itemId = isset($row['item_id']) ? (int)$row['item_id'] : 0;
            return $itemId > 0 ? ('备忘录 #' . $itemId) : '备忘录附件';
        }

        if ($kind === 'mindmap') {
            $mapId = isset($row['mindmap_id']) ? (int)$row['mindmap_id'] : 0;
            return $mapId > 0 ? ('思维导图 #' . $mapId) : '导图草稿附件';
        }

        $normalized = strtoupper($kind);
        return $normalized !== '' ? $normalized : '文件';
    }

    private function formatDirectiveNickname(string $nickname): string
    {
        $nickname = $nickname !== '' ? $nickname : '访客';
        return $this->toUpper($nickname);
    }

    private function formatDirectiveMessage(string $message): string
    {
        return $this->toUpper($message);
    }

    private function toUpper(string $value): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
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
