<?php

declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

class AttachmentRepository extends BaseRepository
{
    public function __construct(private string $uploadDir)
    {
    }

    public function uploadDirectory(): string
    {
        return $this->uploadDir;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forItem(int $itemId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM attachments WHERE item_id = ? ORDER BY id DESC');
        $stmt->execute([$itemId]);
        $rows = $stmt->fetchAll();

        return array_map($this->formatAttachment(...), $rows);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM attachments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->formatAttachment($row) : null;
    }

    public function delete(int $id): void
    {
        $attachment = $this->find($id);
        if (!$attachment) {
            return;
        }

        $this->removeFile($attachment['stored_name']);

        $stmt = $this->pdo()->prepare('DELETE FROM attachments WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function deleteForItem(int $itemId): void
    {
        $attachments = $this->forItem($itemId);
        foreach ($attachments as $attachment) {
            $this->removeFile($attachment['stored_name']);
        }
        $stmt = $this->pdo()->prepare('DELETE FROM attachments WHERE item_id = ?');
        $stmt->execute([$itemId]);
    }

    /**
     * @param array<int> $itemIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function groupedByItem(array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }

        $ids = array_values(array_filter($itemIds, fn ($id) => is_int($id) && $id > 0));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM attachments WHERE item_id IN ($placeholders) ORDER BY item_id ASC, id DESC"
        );
        $stmt->execute($ids);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $itemId = (int)$row['item_id'];
            $grouped[$itemId] ??= [];
            $grouped[$itemId][] = $this->formatAttachment($row);
        }

        return $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAttachment(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['item_id'] = isset($row['item_id']) ? (int)$row['item_id'] : null;
        $row['step_id'] = isset($row['step_id']) ? (int)$row['step_id'] : null;
        $row['size'] = (int)$row['size'];
        $row['created_at'] = (int)$row['created_at'];

        return $row;
    }

    private function removeFile(string $storedName): void
    {
        if ($storedName === '') {
            return;
        }

        $path = rtrim($this->uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($path) && !@unlink($path)) {
            throw new RuntimeException('无法删除附件文件');
        }
    }
}
