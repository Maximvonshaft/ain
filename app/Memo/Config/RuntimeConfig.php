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
        $defaultMimes = AllowedMimes::defaults();

        $defaults = [
            'timezone' => 'Asia/Shanghai',
            'db_file' => rtrim($projectRoot, '/\\') . '/memo.sqlite',
            'upload_dir' => rtrim($projectRoot, '/\\') . '/storage/uploads',
            'max_upload_bytes' => 15 * 1024 * 1024,
            'allowed_mimes' => $defaultMimes,
            'template_mode' => 'default',
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
            $defaults['max_upload_bytes'] = (int) $maxBytes;
        }

        $mimes = $config->get('app.uploads.allowed_mimes');
        if (is_array($mimes) && $mimes) {
            $defaults['allowed_mimes'] = array_merge($defaultMimes, $mimes);
        }

        $templateMode = $config->get('template.mode');
        if (is_string($templateMode) && $templateMode !== '') {
            $defaults['template_mode'] = $templateMode;
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

    /**
     * @return array<string, string>
     */
    public function allowedMimes(): array
    {
        $mimes = $this->settings['allowed_mimes'] ?? [];
        if (!is_array($mimes) || $mimes === []) {
            return AllowedMimes::defaults();
        }

        return $mimes;
    }

    public function templateMode(): string
    {
        $mode = $this->settings['template_mode'] ?? 'default';
        if (!is_string($mode) || $mode === '') {
            return 'default';
        }

        $normalized = strtoupper($mode);

        return $normalized === 'MAX' ? 'MAX' : 'default';
    }
}
