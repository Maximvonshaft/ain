<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttachmentRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\ItemRepository;
use App\Repositories\StepRepository;
use RuntimeException;

class MemoService
{
    public function __construct(
        private CategoryRepository $categories,
        private ItemRepository $items,
        private StepRepository $steps,
        private AttachmentRepository $attachments,
        private int $maxUploadBytes,
        private array $allowedMimes
    ) {
    }

    /**
     * @return array{cats:array<int,array<string,mixed>>,counts:array<int,int>,stats:array<string,int>}
     */
    public function categories(): array
    {
        $this->categories->ensureDone();
        $this->categories->ensureOther();
        $cats = $this->categories->all();
        $counts = $this->categories->counts();

        $totalActive = (int)$this->itemsCount('done = 0');
        $uncategorized = (int)$this->itemsCount('done = 0 AND category_id IS NULL');
        $mindmaps = (int)$this->mindmapCount();

        return [
            'cats' => $cats,
            'counts' => $counts,
            'stats' => [
                'active_total' => $totalActive,
                'active_uncategorized' => $uncategorized,
                'mindmap_total' => $mindmaps,
            ],
        ];
    }

    public function createCategory(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('分类名必填');
        }

        $this->categories->create($name);

        return $this->categories();
    }

    public function updateCategory(int $id, string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('分类名必填');
        }

        $this->categories->update($id, $name);

        return $this->categories();
    }

    public function deleteCategory(int $id): array
    {
        $fallback = $this->categories->ensureOther();
        $pdo = $this->items->connection();
        $pdo->beginTransaction();

        try {
            $targetFallback = $fallback === $id ? null : $fallback;
            $stmt = $pdo->prepare('UPDATE items SET category_id = ? WHERE category_id = ?');
            $stmt->execute([$targetFallback, $id]);
            $stmt = $pdo->prepare('UPDATE items SET previous_category_id = ? WHERE previous_category_id = ?');
            $stmt->execute([$targetFallback, $id]);
            $this->categories->delete($id);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($fallback === $id) {
            $this->categories->ensureOther();
        }

        return $this->categories();
    }

    /**
     * @param array{category_id?:int|null,status?:string,search?:string,include_steps?:bool,include_attachments?:bool} $filters
     */
    public function listItems(array $filters = []): array
    {
        $rows = $this->items->list($filters);
        $ids = array_map(fn ($row) => (int)$row['id'], $rows);

        $includeSteps = !empty($filters['include_steps']);
        $includeAttachments = !empty($filters['include_attachments']);

        $steps = $includeSteps ? $this->steps->groupedByItem($ids) : [];
        $attachments = $includeAttachments ? $this->attachments->groupedByItem($ids) : [];

        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            if ($includeSteps) {
                $row['steps'] = $steps[$id] ?? [];
            }
            if ($includeAttachments) {
                $row['attachments'] = $this->transformAttachments($attachments[$id] ?? []);
            }
        }
        unset($row);

        return $rows;
    }

    public function createItem(array $data): array
    {
        return $this->items->create($data);
    }

    public function findItem(int $id, bool $withRelations = false): ?array
    {
        $item = $this->items->find($id);
        if (!$item) {
            return null;
        }

        if ($withRelations) {
            $item['steps'] = $this->steps->forItem($id);
            $item['attachments'] = $this->transformAttachments($this->attachments->forItem($id));
        }

        return $item;
    }

    public function updateItem(int $id, array $data): void
    {
        $this->items->update($id, $data);
    }

    public function toggleItemDone(int $id, bool $done): array
    {
        $doneCategoryId = $this->categories->ensureDone();
        return $this->items->toggleDone($id, $done, $doneCategoryId);
    }

    public function reorderItems(array $ids): void
    {
        $ids = array_values(array_filter($ids, fn ($id) => is_int($id) && $id > 0));
        $this->items->reorder($ids);
    }

    public function deleteItem(int $id): void
    {
        $item = $this->items->find($id);
        if (!$item) {
            throw new RuntimeException('指定的备忘录不存在');
        }

        $this->attachments->deleteForItem($id);
        $this->items->delete($id);
    }

    public function addStep(int $itemId, string $title): array
    {
        $step = $this->steps->create($itemId, $title);
        $this->touchItem($itemId);
        return $step;
    }

    public function toggleStep(int $id, bool $done): array
    {
        $result = $this->steps->toggle($id, $done);
        $this->touchItem($result['item_id']);
        return $result;
    }

    public function updateStep(int $id, array $payload): void
    {
        if (isset($payload['title'])) {
            $this->steps->updateTitle($id, (string)$payload['title']);
        }
        if (array_key_exists('notes', $payload)) {
            $this->steps->updateNotes($id, (string)$payload['notes']);
        }

        $step = $this->steps->find($id);
        if ($step) {
            $this->touchItem((int)$step['item_id']);
        }
    }

    public function deleteStep(int $id): void
    {
        $step = $this->steps->delete($id);
        if ($step) {
            $this->touchItem((int)$step['item_id']);
        }
    }

    public function reorderSteps(int $itemId, array $ids): void
    {
        $ids = array_values(array_filter($ids, fn ($id) => is_int($id) && $id > 0));
        $this->steps->reorder($itemId, $ids);
        $this->touchItem($itemId);
    }

    public function uploadAttachment(array $file, string $target, int $targetId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('上传失败');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size > $this->maxUploadBytes) {
            throw new RuntimeException('文件过大，最大 15MB');
        }

        $tmpName = $file['tmp_name'] ?? null;
        if (!is_string($tmpName) || !is_file($tmpName)) {
            throw new RuntimeException('上传文件不可用');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName) ?: 'application/octet-stream';
        $extension = $this->allowedMimes[$mime] ?? null;
        if (!$extension) {
            throw new RuntimeException('不支持的文件类型');
        }

        $stored = bin2hex(random_bytes(8)) . '.' . $extension;
        $destination = rtrim($this->attachments->uploadDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $stored;

        if (!is_dir(dirname($destination))) {
            @mkdir(dirname($destination), 0775, true);
        }

        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('保存失败');
        }

        $pdo = $this->items->connection();
        $stmt = $pdo->prepare(
            'INSERT INTO attachments(item_id, step_id, orig_name, stored_name, mime, size, created_at) VALUES(?,?,?,?,?,?,?)'
        );
        $itemIdForTouch = null;

        if ($target === 'item') {
            $stmt->execute([$targetId, null, $file['name'], $stored, $mime, $size, now()]);
            $itemIdForTouch = $targetId;
        } elseif ($target === 'step') {
            $step = $this->steps->find($targetId);
            if (!$step) {
                throw new RuntimeException('指定的步骤不存在');
            }
            $itemIdForTouch = (int)$step['item_id'];
            $stmt->execute([$itemIdForTouch, $targetId, $file['name'], $stored, $mime, $size, now()]);
        } else {
            throw new RuntimeException('目标无效');
        }

        if ($itemIdForTouch) {
            $this->touchItem($itemIdForTouch);
        }

        $id = (int)$pdo->lastInsertId();

        return $this->attachments->find($id) ?? [
            'id' => $id,
            'item_id' => $itemIdForTouch,
            'step_id' => $target === 'step' ? $targetId : null,
            'orig_name' => $file['name'],
            'stored_name' => $stored,
            'mime' => $mime,
            'size' => $size,
            'created_at' => now(),
        ];
    }

    public function deleteAttachment(int $id): void
    {
        $attachment = $this->attachments->find($id);
        if (!$attachment) {
            return;
        }

        $itemId = $attachment['item_id'] ? (int)$attachment['item_id'] : null;
        $stepId = $attachment['step_id'] ? (int)$attachment['step_id'] : null;

        $this->attachments->delete($id);

        if ($itemId) {
            $this->touchItem($itemId);
        } elseif ($stepId) {
            $step = $this->steps->find($stepId);
            if ($step) {
                $this->touchItem((int)$step['item_id']);
            }
        }
    }

    private function touchItem(int $itemId): void
    {
        $stmt = $this->items->connection()->prepare('UPDATE items SET updated_at = ? WHERE id = ?');
        $stmt->execute([now(), $itemId]);
    }

    private function itemsCount(string $where): int
    {
        $pdo = $this->items->connection();
        return (int)$pdo->query('SELECT COUNT(*) FROM items WHERE ' . $where)->fetchColumn();
    }

    private function mindmapCount(): int
    {
        $pdo = $this->items->connection();
        return (int)$pdo->query('SELECT COUNT(*) FROM mindmaps')->fetchColumn();
    }

    private function transformAttachments(array $attachments): array
    {
        return array_map(function (array $attachment): array {
            $attachment['download_url'] = 'api/attachments/' . $attachment['id'] . '/download';
            return $attachment;
        }, $attachments);
    }

    public function getAttachment(int $id): ?array
    {
        return $this->attachments->find($id);
    }

    public function attachmentAbsolutePath(array $attachment): string
    {
        return rtrim($this->attachments->uploadDirectory(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $attachment['stored_name'];
    }

    public function shouldInlineAttachment(array $attachment): bool
    {
        $mime = strtolower((string)($attachment['mime'] ?? ''));
        if ($mime === '') {
            return false;
        }

        if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            return true;
        }

        return in_array($mime, ['application/pdf'], true);
    }
}
