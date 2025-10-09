<?php

namespace Core;

class Config
{
    private array $items = [];
    private array $files = [];

    public function __construct(private string $path)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $segments = explode('.', $key);
        $file = array_shift($segments);
        if ($file === null || $file === '') {
            return $this->items[$key] = $default;
        }

        $data = $this->loadFile($file);
        if ($data === null) {
            return $this->items[$key] = $default;
        }

        $value = $data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $this->items[$key] = $default;
            }
            $value = $value[$segment];
        }

        return $this->items[$key] = $value;
    }

    private function loadFile(string $file): ?array
    {
        if (array_key_exists($file, $this->files)) {
            return $this->files[$file];
        }

        $configFile = rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file . '.php';
        if (!is_file($configFile)) {
            return $this->files[$file] = null;
        }

        $data = require $configFile;
        if (!is_array($data)) {
            return $this->files[$file] = null;
        }

        return $this->files[$file] = $data;
    }
}
