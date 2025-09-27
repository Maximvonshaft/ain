<?php

namespace App\Models;

class MindmapEdge
{
    public function __construct(
        public int $id,
        public int $mindmapId,
        public int $fromNodeId,
        public int $toNodeId,
        public ?string $styleJson,
    ) {
    }
}
