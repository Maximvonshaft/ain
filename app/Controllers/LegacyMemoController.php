<?php

namespace App\Controllers;

use Core\Request;

class LegacyMemoController
{
    public function handle(Request $request): void
    {
        require __DIR__ . '/../../memo.php';
    }
}
