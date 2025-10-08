<?php

namespace App\Repositories;

use Core\DB;
use PDO;

abstract class BaseRepository
{
    protected function pdo(): PDO
    {
        return DB::pdo();
    }

    public function connection(): PDO
    {
        return $this->pdo();
    }
}
