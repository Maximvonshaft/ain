<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\MemoService;
use Core\Request;
use Core\Response;

class CategoryController
{
    public function __construct(private MemoService $service)
    {
    }

    public function index(): void
    {
        $payload = $this->service->categories();
        Response::json([
            'ok' => 1,
            'categories' => $payload['cats'],
            'counts' => $payload['counts'],
            'stats' => $payload['stats'],
        ]);
    }

    public function store(Request $request): void
    {
        $body = $request->json();
        $name = (string)($body['name'] ?? '');
        $payload = $this->service->createCategory($name);

        Response::json([
            'ok' => 1,
            'categories' => $payload['cats'],
            'counts' => $payload['counts'],
            'stats' => $payload['stats'],
        ], 201);
    }

    public function update(Request $request, int $id): void
    {
        $body = $request->json();
        $name = (string)($body['name'] ?? '');
        $payload = $this->service->updateCategory($id, $name);

        Response::json([
            'ok' => 1,
            'categories' => $payload['cats'],
            'counts' => $payload['counts'],
            'stats' => $payload['stats'],
        ]);
    }

    public function destroy(int $id): void
    {
        $payload = $this->service->deleteCategory($id);

        Response::json([
            'ok' => 1,
            'categories' => $payload['cats'],
            'counts' => $payload['counts'],
            'stats' => $payload['stats'],
        ]);
    }
}
