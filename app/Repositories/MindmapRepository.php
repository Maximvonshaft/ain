<?php

namespace App\Repositories;

class MindmapRepository extends BaseRepository
{
    public function countAll(): int
    {
        return (int)$this->pdo()->query('SELECT COUNT(*) FROM mindmaps')->fetchColumn();
    }
}
