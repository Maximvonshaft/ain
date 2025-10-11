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
        Environment::bootstrap($this->config, $csrfToken, $runtimeConfig, $request->basePath());

        require $this->projectRoot . '/memo.php';
    }
}
