<?php

namespace App\Memo\Legacy;

use App\Memo\Config\RuntimeConfig;
use Core\Config;
use Core\Request;

final class LegacyMemoRunner
{
    private string $projectRoot;
    private ?RuntimeConfig $cachedRuntimeConfig = null;

    public function __construct(private Config $config)
    {
        $this->projectRoot = dirname(__DIR__, 3);
    }

    public function handle(Request $request, string $csrfToken): void
    {
        $runtimeConfig = $this->runtimeConfig();
        Environment::bootstrap($this->config, $csrfToken, $runtimeConfig, $request->basePath());

        require $this->projectRoot . '/memo.php';
    }

    private function runtimeConfig(): RuntimeConfig
    {
        if ($this->cachedRuntimeConfig === null) {
            $this->cachedRuntimeConfig = RuntimeConfig::fromConfig($this->config, $this->projectRoot);
        }

        return $this->cachedRuntimeConfig;
    }
}
