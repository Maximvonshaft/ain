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
}
