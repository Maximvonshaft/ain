<?php

namespace App\Services;

use App\Repositories\CategoryRepository;
use App\Repositories\ItemRepository;
use App\Repositories\StepRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

class ItemService
{
    public function __construct(
        private ItemRepository $items,
        private StepRepository $steps,
        private CategoryRepository $categories
    ) {
    }

    public function list(array $filters = [], bool $withSteps = false): array
    {
        $rows = $this->items->list($filters);
        $items = [];
        $ids = [];
        foreach ($rows as $row) {
            $item = $this->formatItem($row);
            $items[] = $item;
            $ids[] = $item['id'];
        }

        $stepCounts = $this->steps->countsForItems($ids);
        $stepsByItem = [];
        if ($withSteps) {
            foreach ($ids as $itemId) {
                $stepsByItem[$itemId] = $this->formatSteps($this->steps->listByItem($itemId));
            }
        }

        foreach ($items as &$item) {
            $id = $item['id'];
            $item['step_count'] = $stepCounts[$id] ?? 0;
            if ($withSteps) {
                $item['steps'] = $stepsByItem[$id] ?? [];
            }
        }

        return $items;
    }

    public function get(int $id, bool $withSteps = false): array
    {
        $row = $this->items->find($id);
        if (!$row) {
            throw new NotFoundException('备忘录不存在');
        }
        $item = $this->formatItem($row);
        $item['step_count'] = $this->steps->countsForItems([$item['id']])[$item['id']] ?? 0;
        if ($withSteps) {
            $item['steps'] = $this->formatSteps($this->steps->listByItem($item['id']));
        }
        return $item;
    }

    public function create(array $data): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException('标题不能为空');
        }
        $description = (string)($data['description'] ?? '');
        $categoryId = $this->normalizeCategoryId($data['category_id'] ?? null);

        $newId = $this->items->create($title, $description, $categoryId);
        return $this->get($newId, true);
    }

    public function update(int $id, array $data): array
    {
        $item = $this->items->find($id);
        if (!$item) {
            throw new NotFoundException('备忘录不存在');
        }

        $payload = [];
        if (array_key_exists('title', $data)) {
            $title = trim((string)$data['title']);
            if ($title === '') {
                throw new ValidationException('标题不能为空');
            }
            $payload['title'] = $title;
        }
        if (array_key_exists('description', $data)) {
            $payload['description'] = (string)$data['description'];
        }
        if (array_key_exists('category_id', $data)) {
            $payload['category_id'] = $this->normalizeCategoryId($data['category_id']);
        }

        if ($payload) {
            $this->items->update($id, $payload);
        }

        return $this->get($id, true);
    }

    public function delete(int $id): void
    {
        $item = $this->items->find($id);
        if (!$item) {
            throw new NotFoundException('备忘录不存在');
        }
        $this->items->delete($id);
    }

    public function toggleDone(int $id, bool $done): array
    {
        $item = $this->items->find($id);
        if (!$item) {
            throw new NotFoundException('备忘录不存在');
        }

        if ($done) {
            $doneCategoryId = $this->categories->ensureDone();
            $previous = $item['category_id'] !== null ? (int)$item['category_id'] : null;
            if ((int)$item['done'] === 1 && $item['previous_category_id'] !== null) {
                $previous = (int)$item['previous_category_id'];
            }
            $this->items->update($id, [
                'done' => 1,
                'category_id' => $doneCategoryId,
                'previous_category_id' => $previous,
            ]);
        } else {
            $restore = $item['previous_category_id'] !== null ? (int)$item['previous_category_id'] : null;
            $this->items->update($id, [
                'done' => 0,
                'category_id' => $restore,
                'previous_category_id' => null,
            ]);
        }

        return $this->get($id, true);
    }

    private function normalizeCategoryId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new ValidationException('分类选择无效');
        }
        $id = (int)$value;
        if ($id <= 0) {
            return null;
        }
        $category = $this->categories->find($id);
        if (!$category) {
            throw new ValidationException('指定的分类不存在');
        }
        return $id;
    }

    private function formatItem(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'done' => ((int)$row['done']) === 1,
            'category_id' => $row['category_id'] !== null ? (int)$row['category_id'] : null,
            'category_name' => $row['category_name'] ?? null,
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
            'previous_category_id' => $row['previous_category_id'] !== null ? (int)$row['previous_category_id'] : null,
        ];
    }

    private function formatSteps(array $steps): array
    {
        $formatted = [];
        foreach ($steps as $step) {
            $formatted[] = [
                'id' => (int)$step['id'],
                'item_id' => (int)$step['item_id'],
                'title' => (string)$step['title'],
                'notes' => (string)($step['notes'] ?? ''),
                'done' => ((int)$step['done']) === 1,
                'order_index' => (int)$step['order_index'],
                'created_at' => (int)$step['created_at'],
                'updated_at' => (int)$step['updated_at'],
            ];
        }
        return $formatted;
    }
}
