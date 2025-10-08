<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;

class ItemRepository extends BaseRepository
{
    /**
     * @param array{category_id?:int|null,status?:string,search?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];

        $status = $filters['status'] ?? 'active';
        if ($status === 'active') {
            $where[] = 'items.done = 0';
        } elseif ($status === 'completed') {
            $where[] = 'items.done = 1';
        }

        if (array_key_exists('category_id', $filters)) {
            $categoryId = $filters['category_id'];
            if ($categoryId === null) {
                $where[] = 'items.category_id IS NULL';
            } elseif (is_int($categoryId)) {
                $where[] = 'items.category_id = :category_id';
                $params[':category_id'] = $categoryId;
            }
        }

        if (!empty($filters['search'])) {
            $where[] = '(items.title LIKE :search OR items.description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT items.*, categories.name AS category_name, prev.name AS previous_category_name '
            . 'FROM items '
            . 'LEFT JOIN categories ON categories.id = items.category_id '
            . 'LEFT JOIN categories AS prev ON prev.id = items.previous_category_id';

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY items.order_index ASC, items.updated_at DESC, items.id DESC';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map($this->formatItem(...), $stmt->fetchAll());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT items.*, categories.name AS category_name, prev.name AS previous_category_name '
            . 'FROM items '
            . 'LEFT JOIN categories ON categories.id = items.category_id '
            . 'LEFT JOIN categories AS prev ON prev.id = items.previous_category_id '
            . 'WHERE items.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? $this->formatItem($row) : null;
    }

    /**
     * @param array{title:string,description?:string,category_id?:int|null,done?:bool} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            throw new RuntimeException('标题必填');
        }

        $description = (string)($data['description'] ?? '');
        $categoryId = $data['category_id'] ?? null;
        $categoryId = is_int($categoryId) ? $categoryId : null;
        $done = !empty($data['done']);

        $pdo = $this->pdo();
        $now = now();
        $orderIndex = $this->nextOrderIndex();

        $stmt = $pdo->prepare(
            'INSERT INTO items(title, description, done, category_id, order_index, created_at, updated_at, previous_category_id) '
            . 'VALUES(?,?,?,?,?,?,?,NULL)'
        );
        $stmt->execute([$title, $description, $done ? 1 : 0, $categoryId, $orderIndex, $now, $now]);
        $id = (int)$pdo->lastInsertId();

        return $this->find($id) ?? [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'done' => $done ? 1 : 0,
            'category_id' => $categoryId,
            'order_index' => $orderIndex,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param array{title?:string,description?:string,category_id?:int|null} $data
     */
    public function update(int $id, array $data): void
    {
        $item = $this->find($id);
        if (!$item) {
            throw new RuntimeException('指定的备忘录不存在');
        }

        $title = array_key_exists('title', $data) ? trim((string)$data['title']) : $item['title'];
        if ($title === '') {
            throw new RuntimeException('标题必填');
        }

        $description = array_key_exists('description', $data)
            ? (string)$data['description']
            : ($item['description'] ?? '');

        $categoryId = $data['category_id'] ?? $item['category_id'];
        $categoryId = is_int($categoryId) ? $categoryId : null;

        $stmt = $this->pdo()->prepare(
            'UPDATE items SET title = ?, description = ?, category_id = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$title, $description, $categoryId, now(), $id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleDone(int $id, bool $done, int $doneCategoryId): array
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT items.*, categories.name AS category_name, prev.name AS previous_category_name '
                . 'FROM items '
                . 'LEFT JOIN categories ON categories.id = items.category_id '
                . 'LEFT JOIN categories AS prev ON prev.id = items.previous_category_id '
                . 'WHERE items.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('指定的备忘录不存在');
            }

            $now = now();
            $categoryLabel = (string)($row['category_name'] ?? '');
            $newCategoryId = $row['category_id'] !== null ? (int)$row['category_id'] : null;

            if ($done) {
                $previous = (int)($row['done'] ? ($row['previous_category_id'] ?? $row['category_id']) : ($row['category_id'] ?? 0));
                $previous = $previous > 0 ? $previous : null;
                $stmtUpdate = $pdo->prepare('UPDATE items SET previous_category_id = ?, category_id = ?, done = 1, updated_at = ? WHERE id = ?');
                $stmtUpdate->execute([$previous, $doneCategoryId, $now, $id]);
                $categoryLabel = '已完成';
                $newCategoryId = $doneCategoryId;
            } else {
                $restore = $row['previous_category_id'] !== null ? (int)$row['previous_category_id'] : null;
                $stmtUpdate = $pdo->prepare('UPDATE items SET previous_category_id = NULL, category_id = ?, done = 0, updated_at = ? WHERE id = ?');
                $stmtUpdate->execute([$restore, $now, $id]);
                if ($restore !== null) {
                    $labelStmt = $pdo->prepare('SELECT name FROM categories WHERE id = ? LIMIT 1');
                    $labelStmt->execute([$restore]);
                    $categoryLabel = (string)($labelStmt->fetchColumn() ?: '');
                } else {
                    $categoryLabel = '未分类';
                }
                $newCategoryId = $restore;
            }

            $pdo->commit();

            return [
                'id' => $id,
                'done' => $done,
                'updated_at' => $now,
                'category_id' => $newCategoryId,
                'category_label' => $categoryLabel,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function reorder(array $ids): void
    {
        if (!$ids) {
            return;
        }

        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE items SET order_index = ?, updated_at = ? WHERE id = ?');
            $index = 0;
            $now = now();
            foreach ($ids as $id) {
                if (!is_int($id)) {
                    continue;
                }
                $stmt->execute([$index++, $now, $id]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function delete(int $id): ?array
    {
        $item = $this->find($id);
        if (!$item) {
            return null;
        }

        $stmt = $this->pdo()->prepare('DELETE FROM items WHERE id = ?');
        $stmt->execute([$id]);

        return $item;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function itemsForIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $ids = array_values(array_filter($ids, fn ($id) => is_int($id) && $id > 0));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo()->prepare(
            'SELECT items.*, categories.name AS category_name '
            . 'FROM items '
            . 'LEFT JOIN categories ON categories.id = items.category_id '
            . "WHERE items.id IN ($placeholders)"
        );
        $stmt->execute($ids);

        return array_map($this->formatItem(...), $stmt->fetchAll());
    }

    private function formatItem(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['done'] = (int)$row['done'];
        $row['order_index'] = (int)$row['order_index'];
        $row['created_at'] = (int)$row['created_at'];
        $row['updated_at'] = (int)$row['updated_at'];
        $row['category_id'] = isset($row['category_id']) ? (int)$row['category_id'] : null;
        $row['previous_category_id'] = isset($row['previous_category_id']) ? (int)$row['previous_category_id'] : null;

        return $row;
    }

    private function nextOrderIndex(): int
    {
        $value = (int)$this->pdo()->query('SELECT COALESCE(MAX(order_index), -1) FROM items')->fetchColumn();
        return $value + 1;
    }
}
