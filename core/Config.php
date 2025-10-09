<?php

namespace Core;

class Config
{
    /**
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * @var array<string, array|null>
     */
    private array $fileCache = [];

    public function __construct(private string $path)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $segments = explode('.', $key);
        $file = array_shift($segments);
        if ($file === null || $file === '') {
            return $default;
        }

        if (!array_key_exists($file, $this->fileCache)) {
            $configFile = rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file . '.php';
            if (!is_file($configFile)) {
                $this->fileCache[$file] = null;
            } else {
                $data = require $configFile;
                $this->fileCache[$file] = is_array($data) ? $data : null;
            }
        }

        $data = $this->fileCache[$file];
        if (!is_array($data)) {
            return $default;
        }

        if (count($segments) === 0) {
            $this->cache[$key] = $data;
            return $data;
        }

        $value = $data;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        $this->cache[$key] = $value;

        return $value;
    }
}
