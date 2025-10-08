<?php

namespace App\Controllers\Api;

use App\Services\ItemService;
use Core\Request;

class ItemController extends ApiController
{
    public function __construct(private ItemService $items)
    {
    }

    public function index(Request $request): void
    {
        $this->respond(function () use ($request) {
            $filters = [];
            $category = $request->query('category');
            if ($category !== null && $category !== '' && $category !== 'all') {
                if ($category === 'null' || $category === 'none' || $category === 'uncategorized') {
                    $filters['category_id'] = null;
                } elseif (is_numeric($category)) {
                    $filters['category_id'] = (int)$category;
                }
            }
            $done = $request->query('done');
            if ($done !== null && $done !== '') {
                $filters['done'] = $this->toBool($done);
            }
            $query = trim((string)$request->query('q', ''));
            if ($query !== '') {
                $filters['query'] = $query;
            }
            $sort = $request->query('sort');
            if (is_string($sort) && $sort !== '') {
                $filters['sort'] = $sort;
            }
            $withSteps = $this->toBool($request->query('with_steps', false));

            $items = $this->items->list($filters, $withSteps);
            return [
                'items' => $items,
                'count' => count($items),
            ];
        });
    }

    public function show(Request $request, int $id): void
    {
        $this->respond(function () use ($request, $id) {
            $withSteps = $this->toBool($request->query('with_steps', true));
            $item = $this->items->get($id, $withSteps);
            return ['item' => $item];
        });
    }

    public function store(Request $request): void
    {
        $this->respond(function () use ($request) {
            $payload = array_merge($request->json(), $request->input());
            $item = $this->items->create($payload);
            return ['item' => $item];
        }, 201);
    }

    public function update(Request $request, int $id): void
    {
        $this->respond(function () use ($request, $id) {
            $payload = array_merge($request->json(), $request->input());
            $item = $this->items->update($id, $payload);
            return ['item' => $item];
        });
    }

    public function toggle(Request $request, int $id): void
    {
        $this->respond(function () use ($request, $id) {
            $payload = array_merge($request->json(), $request->input());
            $done = $this->toBool($payload['done'] ?? true);
            $item = $this->items->toggleDone($id, $done);
            return ['item' => $item];
        });
    }

    public function destroy(Request $request, int $id): void
    {
        $this->respond(function () use ($id) {
            $this->items->delete($id);
            return [];
        }, 204);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
                return false;
            }
            return true;
        }
        return (bool)$value;
    }
}
