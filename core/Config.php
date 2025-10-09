<?php

namespace Core;

class Config
{
    private array $items = [];

    /**
     * @var array<string, mixed>
     */
    private array $fileCache = [];

    private static ?self $instance = null;

    public function __construct(private string $path)
    {
        self::$instance = $this;
    }

    public static function setInstance(self $config): void
    {
        self::$instance = $config;
    }

    public static function instance(): self
    {
        if (!self::$instance) {
            throw new \RuntimeException('Config repository has not been initialised');
        }

        return self::$instance;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $segments = explode('.', $key);
        $file = array_shift($segments);
        if ($file === null || $file === '') {
            return $default;
        }

        $data = $this->loadFile($file);
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

        $this->items[$key] = $value;

        return $value;
    }

    private function loadFile(string $file): mixed
    {
        if (array_key_exists($file, $this->fileCache)) {
            return $this->fileCache[$file];
        }

        $configFile = rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file . '.php';
        if (!is_file($configFile)) {
            $this->fileCache[$file] = null;
            return null;
        }

        $data = require $configFile;
        $this->fileCache[$file] = $data;

        return $data;
    }
}
