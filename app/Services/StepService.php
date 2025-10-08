<?php

namespace App\Services;

use App\Repositories\ItemRepository;
use App\Repositories\StepRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;

class StepService
{
    public function __construct(
        private StepRepository $steps,
        private ItemRepository $items
    ) {
    }

    public function create(int $itemId, array $data): array
    {
        $item = $this->items->find($itemId);
        if (!$item) {
            throw new NotFoundException('备忘录不存在');
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException('步骤标题不能为空');
        }
        $notes = (string)($data['notes'] ?? '');
        $done = (bool)($data['done'] ?? false);
        $orderIndex = array_key_exists('order_index', $data) ? (int)$data['order_index'] : null;

        $stepId = $this->steps->create($itemId, $title, $notes, $done, $orderIndex);
        $this->items->touch($itemId);

        return $this->formatStep($this->steps->find($stepId));
    }

    public function update(int $id, array $data): array
    {
        $step = $this->steps->find($id);
        if (!$step) {
            throw new NotFoundException('步骤不存在');
        }

        $payload = [];
        if (array_key_exists('title', $data)) {
            $title = trim((string)$data['title']);
            if ($title === '') {
                throw new ValidationException('步骤标题不能为空');
            }
            $payload['title'] = $title;
        }
        if (array_key_exists('notes', $data)) {
            $payload['notes'] = (string)$data['notes'];
        }
        if (array_key_exists('done', $data)) {
            $payload['done'] = (bool)$data['done'];
        }
        if (array_key_exists('order_index', $data)) {
            $payload['order_index'] = (int)$data['order_index'];
        }

        if ($payload) {
            $this->steps->update($id, $payload);
            $this->items->touch((int)$step['item_id']);
        }

        return $this->formatStep($this->steps->find($id));
    }

    public function delete(int $id): void
    {
        $step = $this->steps->find($id);
        if (!$step) {
            throw new NotFoundException('步骤不存在');
        }
        $this->steps->delete($id);
        $this->items->touch((int)$step['item_id']);
    }

    private function formatStep(?array $row): array
    {
        if (!$row) {
            throw new NotFoundException('步骤不存在');
        }
        return [
            'id' => (int)$row['id'],
            'item_id' => (int)$row['item_id'],
            'title' => (string)$row['title'],
            'notes' => (string)($row['notes'] ?? ''),
            'done' => ((int)$row['done']) === 1,
            'order_index' => (int)$row['order_index'],
            'created_at' => (int)$row['created_at'],
            'updated_at' => (int)$row['updated_at'],
        ];
    }
}
