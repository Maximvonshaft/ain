<?php

namespace Core;

class Config
{
    private array $items = [];

    public function __construct(private string $path)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->items[$key])) {
            return $this->items[$key];
        }

        $segments = explode('.', $key);
        $file = array_shift($segments);
        $configFile = rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file . '.php';
        if (!is_file($configFile)) {
            return $default;
        }

        $data = require $configFile;
        if (!is_array($data)) {
            return $default;
        }

        $value = $data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
