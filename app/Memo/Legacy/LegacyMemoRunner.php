<?php

namespace App\Memo\Legacy;

use App\Memo\Config\RuntimeConfig;
use Core\Config;
use Core\Request;

final class LegacyMemoRunner
{
    private string $projectRoot;

    public function __construct(private Config $config)
    {
        $this->projectRoot = dirname(__DIR__, 3);
    }

    public function handle(Request $request, string $csrfToken): void
    {
        $runtimeConfig = RuntimeConfig::fromConfig($this->config, $this->projectRoot);
        $basePath = $request->basePath();
        $baseUrl = $this->resolveBaseUrl($request, $basePath);
        Environment::bootstrap($this->config, $csrfToken, $runtimeConfig, $basePath, $baseUrl);

        require $this->projectRoot . '/memo.php';
    }

    private function resolveBaseUrl(Request $request, string $basePath): string
    {
        $configured = $this->config->get('app.base_url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        $host = $request->server('HTTP_HOST');
        if (!is_string($host) || $host === '') {
            return '';
        }

        $scheme = $request->server('REQUEST_SCHEME');
        if (!is_string($scheme) || $scheme === '') {
            $https = $request->server('HTTPS');
            $scheme = (!empty($https) && strtolower((string) $https) !== 'off') ? 'https' : 'http';
        }

        $base = $scheme . '://' . $host;
        if ($basePath !== '') {
            $base .= $basePath;
        }

        return rtrim($base, '/');
    }
}
