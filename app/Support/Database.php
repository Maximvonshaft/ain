<?php

namespace App\Support;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (!self::$pdo) {
            $config = config('database.connections.sqlite');
            $database = $config['database'] ?? base_path('database/database.sqlite');
            if (!str_starts_with($database, '/')) {
                $database = base_path($database);
            }
            $dir = dirname($database);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }

            $needsInit = !file_exists($database);

            self::$pdo = new PDO('sqlite:' . $database, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            self::$pdo->exec('PRAGMA foreign_keys = ON;');
            self::$pdo->exec('PRAGMA journal_mode = WAL;');

            if ($needsInit) {
                touch($database);
            }
        }

        return self::$pdo;
    }
}

