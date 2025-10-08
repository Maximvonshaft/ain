<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\MemoService;
use Core\Request;
use Core\Response;
use RuntimeException;

class ItemController
{
    public function __construct(private MemoService $service)
    {
    }

    public function index(Request $request): void
    {
        $filters = [
            'category_id' => $this->normalizeCategory($request->query('category_id')),
            'status' => $this->normalizeStatus((string)$request->query('status', 'active')),
            'search' => trim((string)$request->query('search', '')),
            'include_steps' => $request->query('include_steps') ? true : false,
            'include_attachments' => $request->query('include_attachments') ? true : false,
        ];

        $items = $this->service->listItems($filters);

        Response::json([
            'ok' => 1,
            'items' => $items,
        ]);
    }

    public function store(Request $request): void
    {
        $body = $request->json();
        $item = $this->service->createItem([
            'title' => (string)($body['title'] ?? ''),
            'description' => (string)($body['description'] ?? ''),
            'category_id' => $this->normalizeCategory($body['category_id'] ?? null),
        ]);

        Response::json([
            'ok' => 1,
            'item' => $item,
        ], 201);
    }

    public function show(int $id, Request $request): void
    {
        $with = $request->query('with');
        $withRelations = $with === 'all' || $with === 'relations';
        $item = $this->service->findItem($id, $withRelations);
        if (!$item) {
            Response::json(['ok' => 0, 'error' => '未找到'], 404);
            return;
        }

        Response::json([
            'ok' => 1,
            'item' => $item,
        ]);
    }

    public function update(Request $request, int $id): void
    {
        $body = $request->json();
        $payload = [];
        if (array_key_exists('title', $body)) {
            $payload['title'] = $body['title'];
        }
        if (array_key_exists('description', $body)) {
            $payload['description'] = $body['description'];
        }
        if (array_key_exists('category_id', $body)) {
            $payload['category_id'] = $this->normalizeCategory($body['category_id']);
        }

        $this->service->updateItem($id, $payload);

        $item = $this->service->findItem($id, true);

        Response::json([
            'ok' => 1,
            'item' => $item,
        ]);
    }

    public function destroy(int $id): void
    {
        $this->service->deleteItem($id);
        Response::json(['ok' => 1]);
    }

    public function toggleDone(Request $request, int $id): void
    {
        $body = $request->json();
        $done = (bool)($body['done'] ?? true);
        $result = $this->service->toggleItemDone($id, $done);

        Response::json([
            'ok' => 1,
            'item' => $result,
        ]);
    }

    public function reorder(Request $request): void
    {
        $body = $request->json();
        $order = $body['order'] ?? [];
        if (is_string($order)) {
            $order = array_filter(array_map('intval', array_filter(explode(',', $order))));
        } elseif (is_array($order)) {
            $order = array_values(array_filter($order, fn ($value) => is_int($value) || ctype_digit((string)$value)));
            $order = array_map('intval', $order);
        } else {
            throw new RuntimeException('排序数据无效');
        }

        $this->service->reorderItems($order);
        Response::json(['ok' => 1]);
    }

    private function normalizeCategory(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int)$value;
        }

        return null;
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'active', 'completed', 'all' => $status,
            default => 'active',
        };
    }
}
