<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\MemoService;
use Core\Request;
use Core\Response;

class StepController
{
    public function __construct(private MemoService $service)
    {
    }

    public function store(Request $request, int $itemId): void
    {
        $body = $request->json();
        $step = $this->service->addStep($itemId, (string)($body['title'] ?? ''));

        Response::json([
            'ok' => 1,
            'step' => $step,
        ], 201);
    }

    public function update(Request $request, int $id): void
    {
        $body = $request->json();
        $payload = [];
        if (array_key_exists('title', $body)) {
            $payload['title'] = (string)$body['title'];
        }
        if (array_key_exists('notes', $body)) {
            $payload['notes'] = (string)$body['notes'];
        }
        $this->service->updateStep($id, $payload);

        Response::json(['ok' => 1]);
    }

    public function toggle(Request $request, int $id): void
    {
        $body = $request->json();
        $done = (bool)($body['done'] ?? true);
        $result = $this->service->toggleStep($id, $done);

        Response::json([
            'ok' => 1,
            'step' => $result,
        ]);
    }

    public function destroy(int $id): void
    {
        $this->service->deleteStep($id);
        Response::json(['ok' => 1]);
    }

    public function reorder(Request $request, int $itemId): void
    {
        $body = $request->json();
        $order = $body['order'] ?? [];
        if (is_string($order)) {
            $order = array_filter(array_map('intval', array_filter(explode(',', $order))));
        } elseif (is_array($order)) {
            $order = array_map('intval', array_filter($order, fn ($value) => is_int($value) || ctype_digit((string)$value)));
        } else {
            $order = [];
        }

        $this->service->reorderSteps($itemId, $order);
        Response::json(['ok' => 1]);
    }
}
