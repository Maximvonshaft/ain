<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'Apps\\\\OrderMatching\\\\';
    if (str_starts_with($class, $prefix)) {
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/' . str_replace('\\\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
