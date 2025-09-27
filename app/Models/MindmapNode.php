<?php

namespace App\Models;

class MindmapNode
{
    public function __construct(
        public int $id,
        public int $mindmapId,
        public string $text,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?string $styleJson,
        public ?int $parentId,
    ) {
    }
}
