<?php

namespace App\Controllers\Api;

use App\Repositories\CategoryRepository;
use App\Repositories\ItemRepository;
use App\Repositories\MindmapRepository;
use App\Support\NotFoundException;
use App\Support\ValidationException;
use Core\Request;
use PDOException;

class CategoryController extends ApiController
{
    public function __construct(
        private CategoryRepository $categories,
        private ItemRepository $items,
        private MindmapRepository $mindmaps
    ) {
    }

    public function index(Request $request): void
    {
        $this->respond(function () {
            $cats = $this->categories->all();
            $counts = $this->items->countsByCategory(array_map(fn($cat) => (int)$cat['id'], $cats));
            $uncategorizedCount = $this->items->countByCategory(null);
            $stats = $this->categories->stats();
            $mindmapTotal = $this->mindmaps->countAll();

            $categories = [];
            foreach ($cats as $cat) {
                $id = (int)$cat['id'];
                $categories[] = [
                    'id' => $id,
                    'name' => (string)$cat['name'],
                    'count' => $counts[$id] ?? 0,
                ];
            }

            return [
                'categories' => $categories,
                'uncategorized_count' => $uncategorizedCount,
                'stats' => [
                    'active_total' => $stats['active_total'] ?? 0,
                    'active_uncategorized' => $stats['active_uncategorized'] ?? 0,
                    'mindmap_total' => $mindmapTotal,
                ],
            ];
        });
    }

    public function store(Request $request): void
    {
        $this->respond(function () use ($request) {
            $payload = array_merge($request->json(), $request->input());
            $name = trim((string)($payload['name'] ?? ''));
            if ($name === '') {
                throw new ValidationException('分类名称不能为空');
            }
            try {
                $id = $this->categories->create($name);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ValidationException('分类名称已存在');
                }
                throw $e;
            }
            $category = $this->categories->find($id);
            return [
                'category' => [
                    'id' => $id,
                    'name' => $category['name'] ?? $name,
                    'count' => 0,
                ],
            ];
        }, 201);
    }

    public function update(Request $request, int $id): void
    {
        $this->respond(function () use ($request, $id) {
            $category = $this->categories->find($id);
            if (!$category) {
                throw new NotFoundException('分类不存在');
            }
            $payload = array_merge($request->json(), $request->input());
            $name = trim((string)($payload['name'] ?? ''));
            if ($name === '') {
                throw new ValidationException('分类名称不能为空');
            }
            try {
                $this->categories->update($id, $name);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ValidationException('分类名称已存在');
                }
                throw $e;
            }
            return [
                'category' => [
                    'id' => $id,
                    'name' => $name,
                    'count' => $this->items->countByCategory($id),
                ],
            ];
        });
    }

    public function destroy(Request $request, int $id): void
    {
        $this->respond(function () use ($id) {
            $category = $this->categories->find($id);
            if (!$category) {
                throw new NotFoundException('分类不存在');
            }
            $fallbackId = $this->categories->ensureOther();
            $targetId = $fallbackId === $id ? null : $fallbackId;
            $this->items->reassignCategory($id, $targetId);
            $this->categories->delete($id);
            if ($targetId === null) {
                $this->categories->ensureOther();
            }
            return [];
        }, 204);
    }
}
