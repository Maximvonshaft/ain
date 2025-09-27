<?php

namespace App\Models;

class Memo
{
    public function __construct(
        public int $id,
        public string $title,
        public ?string $contentMd,
        public ?string $contentHtml,
        public bool $isDone,
        public ?int $doneAt,
        public bool $pinned,
        public bool $archived,
        public int $updatedAt,
        public int $createdAt,
    ) {
    }
}

