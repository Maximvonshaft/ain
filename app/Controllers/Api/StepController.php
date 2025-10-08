<?php

namespace App\Controllers\Api;

use App\Services\StepService;
use Core\Request;

class StepController extends ApiController
{
    public function __construct(private StepService $steps)
    {
    }

    public function store(Request $request, int $itemId): void
    {
        $this->respond(function () use ($request, $itemId) {
            $payload = array_merge($request->json(), $request->input());
            $step = $this->steps->create($itemId, $payload);
            return ['step' => $step];
        }, 201);
    }

    public function update(Request $request, int $id): void
    {
        $this->respond(function () use ($request, $id) {
            $payload = array_merge($request->json(), $request->input());
            $step = $this->steps->update($id, $payload);
            return ['step' => $step];
        });
    }

    public function destroy(Request $request, int $id): void
    {
        $this->respond(function () use ($id) {
            $this->steps->delete($id);
            return [];
        }, 204);
    }
}
