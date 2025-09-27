<?php

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\MindmapService;

class MindmapApiController
{
    public function __construct(private MindmapService $service = new MindmapService())
    {
    }

    public function indexForMemo(Request $request, array $params): Response
    {
        $memoId = (int)($params['memo'] ?? 0);
        $mindmaps = $this->service->listForMemo($memoId);
        return Response::json(['mindmaps' => $mindmaps]);
    }

    public function storeForMemo(Request $request, array $params): Response
    {
        $memoId = (int)($params['memo'] ?? 0);
        $payload = $request->all();
        $title = trim((string)($payload['title'] ?? '新导图'));
        if ($title === '') {
            return Response::json(['message' => 'Title is required'], 422);
        }

        $mindmap = $this->service->createForMemo($memoId, $title);
        if (!$mindmap) {
            return Response::json(['message' => 'Memo not found'], 404);
        }

        return Response::json(['mindmap' => $mindmap], 201);
    }

    public function show(Request $request, array $params): Response
    {
        $mindmapId = (int)($params['mindmap'] ?? 0);
        $mindmap = $this->service->get($mindmapId);
        if (!$mindmap) {
            return Response::json(['message' => 'Mindmap not found'], 404);
        }

        return Response::json(['mindmap' => $mindmap]);
    }

    public function update(Request $request, array $params): Response
    {
        $mindmapId = (int)($params['mindmap'] ?? 0);
        $payload = $request->all();
        try {
            $mindmap = $this->service->updateProperties($mindmapId, $payload);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['message' => $e->getMessage()], 422);
        }

        if (!$mindmap) {
            return Response::json(['message' => 'Mindmap not found'], 404);
        }

        return Response::json(['mindmap' => $mindmap]);
    }

    public function syncNodes(Request $request, array $params): Response
    {
        $mindmapId = (int)($params['mindmap'] ?? 0);
        $payload = $request->all();
        $changes = [
            'upsert' => isset($payload['upsert']) && is_array($payload['upsert']) ? $payload['upsert'] : [],
            'delete' => isset($payload['delete']) && is_array($payload['delete']) ? array_map('intval', $payload['delete']) : [],
        ];

        $mindmap = $this->service->syncNodes($mindmapId, $changes);
        if (!$mindmap) {
            return Response::json(['message' => 'Mindmap not found'], 404);
        }

        return Response::json(['mindmap' => $mindmap]);
    }

    public function syncEdges(Request $request, array $params): Response
    {
        $mindmapId = (int)($params['mindmap'] ?? 0);
        $payload = $request->all();
        $changes = [
            'upsert' => isset($payload['upsert']) && is_array($payload['upsert']) ? $payload['upsert'] : [],
            'delete' => isset($payload['delete']) && is_array($payload['delete']) ? array_map('intval', $payload['delete']) : [],
        ];

        $mindmap = $this->service->syncEdges($mindmapId, $changes);
        if (!$mindmap) {
            return Response::json(['message' => 'Mindmap not found'], 404);
        }

        return Response::json(['mindmap' => $mindmap]);
    }

    public function destroy(Request $request, array $params): Response
    {
        $mindmapId = (int)($params['mindmap'] ?? 0);
        $deleted = $this->service->delete($mindmapId);
        if (!$deleted) {
            return Response::json(['message' => 'Mindmap not found'], 404);
        }

        return Response::json(['deleted' => true]);
    }
}
