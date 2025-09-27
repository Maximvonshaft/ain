<?php

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Services\MindmapService;

class MindmapController
{
    public function __construct(private MindmapService $service = new MindmapService())
    {
    }

    public function show(Request $request, array $params): Response
    {
        $mindmapId = (int)($params['mindmap'] ?? 0);
        $mindmap = $this->service->get($mindmapId);

        return Response::view('mindmaps/show', [
            'mindmap' => $mindmap,
            'mindmapId' => $mindmapId,
            'appName' => config('app.name'),
            'basePath' => app_base_path(),
            'status' => $mindmap ? 200 : 404,
        ], $mindmap ? 200 : 404);
    }
}
