<?php

namespace App\Services;

use App\Repositories\MemoRepository;
use App\Repositories\MindmapRepository;
use App\Repositories\SubtaskRepository;

class MemoService
{
    public function __construct(
        private MemoRepository $memos = new MemoRepository(),
        private SubtaskRepository $subtasks = new SubtaskRepository(),
        private MindmapRepository $mindmaps = new MindmapRepository(),
    ) {
    }

    public function list(): array
    {
        $memos = $this->memos->all();
        $result = [];
        foreach ($memos as $memo) {
            $result[] = [
                'memo' => $memo,
                'subtasks' => $this->subtasks->forMemo($memo->id),
                'mindmaps' => $this->mindmaps->forMemo($memo->id),
            ];
        }
        return $result;
    }

    public function create(string $title, ?string $contentMd = null, ?string $contentHtml = null)
    {
        return $this->memos->create([
            'title' => $title,
            'content_md' => $contentMd,
            'content_html' => $contentHtml,
        ]);
    }

    public function update(int $id, array $attributes)
    {
        return $this->memos->update($id, $attributes);
    }

    public function toggle(int $id)
    {
        return $this->memos->toggleDone($id);
    }

    public function addSubtask(int $memoId, string $title)
    {
        $memo = $this->memos->find($memoId);
        if (!$memo) {
            return null;
        }
        return $this->subtasks->create($memoId, $title);
    }

    public function toggleSubtask(int $subtaskId)
    {
        return $this->subtasks->toggleDone($subtaskId);
    }
}

