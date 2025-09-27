<?php

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\MemoService;

class MemoController
{
    public function __construct(private MemoService $service = new MemoService())
    {
    }

    public function index(Request $request): Response
    {
        $data = $this->service->list();
        return Response::view('memos/index', [
            'items' => $data,
            'appName' => config('app.name'),
            'basePath' => \app_base_path(),
        ]);
    }

    public function store(Request $request): Response
    {
        $payload = $request->all();
        $title = trim($payload['title'] ?? '');
        if ($title === '') {
            return Response::json(['message' => 'Title is required'], 422);
        }

        $memo = $this->service->create($title, $payload['content_md'] ?? null, $payload['content_html'] ?? null);
        return Response::json([
            'memo' => $this->serializeMemo($memo->id),
        ], 201);
    }

    public function update(Request $request, array $params): Response
    {
        $memoId = (int)($params['memo'] ?? 0);
        $payload = $request->all();
        $attributes = array_intersect_key($payload, array_flip(['title', 'content_md', 'content_html', 'pinned', 'archived']));

        if (isset($attributes['title'])) {
            $attributes['title'] = trim((string)$attributes['title']);
            if ($attributes['title'] === '') {
                return Response::json(['message' => 'Title cannot be empty'], 422);
            }
        }

        foreach (['pinned', 'archived'] as $flag) {
            if (isset($attributes[$flag])) {
                $attributes[$flag] = $this->normalizeBoolean($attributes[$flag]) ? 1 : 0;
            }
        }

        $memo = $this->service->update($memoId, $attributes);
        if (!$memo) {
            return Response::json(['message' => 'Memo not found'], 404);
        }

        return Response::json([
            'memo' => $this->serializeMemo($memoId),
        ]);
    }

    public function toggle(Request $request, array $params): Response
    {
        $memoId = (int)($params['memo'] ?? 0);
        $memo = $this->service->toggle($memoId);
        if (!$memo) {
            return Response::json(['message' => 'Memo not found'], 404);
        }

        return Response::json([
            'memo' => $this->serializeMemo($memoId),
        ]);
    }

    public function addSubtask(Request $request, array $params): Response
    {
        $memoId = (int)($params['memo'] ?? 0);
        $payload = $request->all();
        $title = trim($payload['title'] ?? '');
        if ($title === '') {
            return Response::json(['message' => 'Subtask title is required'], 422);
        }

        $subtask = $this->service->addSubtask($memoId, $title);
        if (!$subtask) {
            return Response::json(['message' => 'Memo not found'], 404);
        }

        return Response::json([
            'subtask' => [
                'id' => $subtask->id,
                'memo_id' => $subtask->memoId,
                'title' => $subtask->title,
                'is_done' => $subtask->isDone,
                'order' => $subtask->order,
            ],
        ], 201);
    }

    public function toggleSubtask(Request $request, array $params): Response
    {
        $subtaskId = (int)($params['subtask'] ?? 0);
        $subtask = $this->service->toggleSubtask($subtaskId);
        if (!$subtask) {
            return Response::json(['message' => 'Subtask not found'], 404);
        }

        return Response::json([
            'subtask' => [
                'id' => $subtask->id,
                'memo_id' => $subtask->memoId,
                'title' => $subtask->title,
                'is_done' => $subtask->isDone,
                'order' => $subtask->order,
            ],
        ]);
    }

    private function serializeMemo(int $memoId): array
    {
        $memoRepository = new \App\Repositories\MemoRepository();
        $subtaskRepository = new \App\Repositories\SubtaskRepository();
        $memo = $memoRepository->find($memoId);
        if (!$memo) {
            throw new \RuntimeException('Memo missing after update');
        }
        return [
            'id' => $memo->id,
            'title' => $memo->title,
            'content_md' => $memo->contentMd,
            'content_html' => $memo->contentHtml,
            'is_done' => $memo->isDone,
            'done_at' => $memo->doneAt,
            'pinned' => $memo->pinned,
            'archived' => $memo->archived,
            'updated_at' => $memo->updatedAt,
            'created_at' => $memo->createdAt,
            'subtasks' => array_map(fn($subtask) => [
                'id' => $subtask->id,
                'memo_id' => $subtask->memoId,
                'title' => $subtask->title,
                'is_done' => $subtask->isDone,
                'order' => $subtask->order,
            ], $subtaskRepository->forMemo($memo->id)),
        ];
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower((string)$value);
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}

