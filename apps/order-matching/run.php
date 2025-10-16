#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/src/autoload.php';

use Apps\OrderMatching\Application;

try {
    (new Application())->run($argv);
} catch (\Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
