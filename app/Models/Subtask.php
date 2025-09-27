<?php

namespace App\Models;

class Subtask
{
    public function __construct(
        public int $id,
        public int $memoId,
        public string $title,
        public bool $isDone,
        public int $order,
        public int $updatedAt,
        public int $createdAt,
    ) {
    }
}

