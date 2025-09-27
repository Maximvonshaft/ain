<?php

namespace App\Models;

class Mindmap
{
    public function __construct(
        public int $id,
        public ?int $memoId,
        public string $title,
        public int $canvasW,
        public int $canvasH,
        public ?string $viewport,
        public int $createdAt,
        public int $updatedAt,
    ) {
    }
}
