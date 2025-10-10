<?php

namespace App\Memo\Config;

use Core\Config;

final class RuntimeConfig
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(private array $settings)
    {
    }

    public static function fromConfig(Config $config, string $projectRoot): self
    {
        $defaultMimes = self::normalizeMimeMap(AllowedMimes::defaults());

        $defaults = [
            'timezone' => 'Asia/Shanghai',
            'db_file' => rtrim($projectRoot, '/\\') . '/memo.sqlite',
            'upload_dir' => rtrim($projectRoot, '/\\') . '/storage/uploads',
            'max_upload_bytes' => 15 * 1024 * 1024,
            'allowed_mimes' => $defaultMimes,
            'max_import_bytes' => 1024 * 1024,
            'import_mimes' => self::normalizeImportMimes(['application/json', 'text/json', 'text/plain']),
        ];

        $timezone = $config->get('app.timezone');
        if (is_string($timezone) && $timezone !== '') {
            $defaults['timezone'] = $timezone;
        }

        $dbPath = $config->get('app.database.path');
        if (is_string($dbPath) && $dbPath !== '') {
            $defaults['db_file'] = $dbPath;
        }

        $uploadDir = $config->get('app.uploads.path');
        if (is_string($uploadDir) && $uploadDir !== '') {
            $defaults['upload_dir'] = $uploadDir;
        }

        $maxBytes = $config->get('app.uploads.max_bytes');
        if (is_numeric($maxBytes)) {
            $defaults['max_upload_bytes'] = max(1, (int) $maxBytes);
        }

        $mimes = $config->get('app.uploads.allowed_mimes');
        if (is_array($mimes) && $mimes) {
            $defaults['allowed_mimes'] = self::normalizeMimeMap(array_merge($defaultMimes, $mimes));
        } else {
            $defaults['allowed_mimes'] = $defaultMimes;
        }

        $importSettings = $config->get('app.imports');
        if (is_array($importSettings)) {
            $importMax = $importSettings['max_bytes'] ?? null;
            if (is_numeric($importMax)) {
                $defaults['max_import_bytes'] = max(1, (int) $importMax);
            }

            $importMimes = $importSettings['mime_types'] ?? null;
            if (is_array($importMimes)) {
                $normalized = self::normalizeImportMimes($importMimes);
                if ($normalized !== []) {
                    $defaults['import_mimes'] = $normalized;
                }
            }
        }

        return new self($defaults);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->settings;
    }

    public function timezone(): string
    {
        return (string) ($this->settings['timezone'] ?? 'UTC');
    }

    public function dbFile(): string
    {
        return (string) ($this->settings['db_file'] ?? 'memo.sqlite');
    }

    public function uploadDir(): string
    {
        return (string) ($this->settings['upload_dir'] ?? 'storage/uploads');
    }

    public function maxUploadBytes(): int
    {
        return (int) ($this->settings['max_upload_bytes'] ?? (15 * 1024 * 1024));
    }

    public function maxImportBytes(): int
    {
        $value = (int) ($this->settings['max_import_bytes'] ?? (1024 * 1024));

        return $value > 0 ? $value : (1024 * 1024);
    }

    /**
     * @return array<string, string>
     */
    public function allowedMimes(): array
    {
        $mimes = $this->settings['allowed_mimes'] ?? [];
        if (!is_array($mimes) || $mimes === []) {
            return self::normalizeMimeMap(AllowedMimes::defaults());
        }

        return self::normalizeMimeMap($mimes);
    }

    /**
     * @return array<int, string>
     */
    public function importMimes(): array
    {
        $mimes = $this->settings['import_mimes'] ?? [];
        if (!is_array($mimes) || $mimes === []) {
            return self::normalizeImportMimes(['application/json', 'text/json', 'text/plain']);
        }

        $normalized = self::normalizeImportMimes($mimes);

        return $normalized === []
            ? self::normalizeImportMimes(['application/json', 'text/json', 'text/plain'])
            : $normalized;
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, string>
     */
    private static function normalizeMimeMap(array $map): array
    {
        $normalized = [];

        foreach ($map as $mime => $extension) {
            if (!is_string($mime)) {
                continue;
            }

            $key = strtolower(trim($mime));
            if ($key === '') {
                continue;
            }

            $value = null;
            if (is_string($extension)) {
                $value = strtolower(ltrim(trim($extension), '.'));
            }

            if ($value === null || $value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $mimes
     * @return array<int, string>
     */
    private static function normalizeImportMimes(array $mimes): array
    {
        $normalized = [];

        foreach ($mimes as $mime) {
            if (!is_string($mime)) {
                continue;
            }

            $value = strtolower(trim($mime));
            if ($value === '') {
                continue;
            }

            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }
}
