<?php

namespace Apps\OrderMatching;

final class CliOptions
{
    private const DEFAULT_THRESHOLD = 0.75;
    private const DEFAULT_HIGH_THRESHOLD = 0.85;
    private const DEFAULT_LOW_THRESHOLD = 0.65;

    private readonly array $options;

    public function __construct(array $argv)
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('This utility can only be executed from the command line.');
        }

        $this->options = getopt('', [
            'orders:',
            'mapping:',
            'output-dir::',
            'threshold::',
            'high-threshold::',
            'mid-threshold::',
            'low-threshold::',
            'order-id-col::',
            'city-col::',
            'province-col::',
            'map-city-col::',
            'map-province-col::',
            'map-city-alias-cols::',
            'map-province-alias-cols::',
            'allow-partial',
            'require-both',
            'export-details',
            'help',
        ]);

        if ($this->flag('help')) {
            $this->printHelp();
            exit(0);
        }
    }

    public function ordersPath(): string
    {
        $path = $this->options['orders'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException('Missing required --orders parameter.');
        }
        return $path;
    }

    public function mappingPath(): string
    {
        $path = $this->options['mapping'] ?? null;
        if (!is_string($path) || $path === '') {
            throw new \InvalidArgumentException('Missing required --mapping parameter.');
        }
        return $path;
    }

    public function outputDir(): string
    {
        $default = dirname(__DIR__) . '/output';
        $dir = $this->options['output-dir'] ?? $default;
        if (!is_string($dir) || $dir === '') {
            $dir = $default;
        }
        return $dir;
    }

    public function threshold(): float
    {
        return $this->floatOption('threshold', self::DEFAULT_THRESHOLD);
    }

    public function highThreshold(): float
    {
        return $this->floatOption('high-threshold', self::DEFAULT_HIGH_THRESHOLD);
    }

    public function midThreshold(): float
    {
        return $this->floatOption('mid-threshold', $this->threshold());
    }

    public function lowThreshold(): float
    {
        return $this->floatOption('low-threshold', self::DEFAULT_LOW_THRESHOLD);
    }

    public function orderIdColumn(): string
    {
        return $this->stringOption('order-id-col', 'A');
    }

    public function orderCityColumn(): string
    {
        return $this->stringOption('city-col', 'BK');
    }

    public function orderProvinceColumn(): string
    {
        return $this->stringOption('province-col', 'BL');
    }

    public function mapCityColumn(): string
    {
        return $this->stringOption('map-city-col', 'D');
    }

    public function mapProvinceColumn(): string
    {
        return $this->stringOption('map-province-col', 'E');
    }

    /**
     * @return string[]
     */
    public function mapCityAliasColumns(): array
    {
        return $this->stringListOption('map-city-alias-cols');
    }

    /**
     * @return string[]
     */
    public function mapProvinceAliasColumns(): array
    {
        return $this->stringListOption('map-province-alias-cols');
    }

    public function allowPartial(): bool
    {
        if ($this->flag('require-both')) {
            return false;
        }

        return $this->flag('allow-partial', true);
    }

    public function exportDetails(): bool
    {
        return $this->flag('export-details');
    }

    public function configId(): string
    {
        $payload = [
            'threshold' => $this->threshold(),
            'high' => $this->highThreshold(),
            'mid' => $this->midThreshold(),
            'low' => $this->lowThreshold(),
            'allowPartial' => $this->allowPartial(),
            'exportDetails' => $this->exportDetails(),
            'orderId' => $this->orderIdColumn(),
            'orderCity' => $this->orderCityColumn(),
            'orderProvince' => $this->orderProvinceColumn(),
            'mapCity' => $this->mapCityColumn(),
            'mapProvince' => $this->mapProvinceColumn(),
            'mapCityAliases' => $this->mapCityAliasColumns(),
            'mapProvinceAliases' => $this->mapProvinceAliasColumns(),
        ];

        return substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 12);
    }

    private function floatOption(string $key, float $default): float
    {
        $value = $this->options[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('Option --%s must be numeric', $key));
        }
        return max(0.0, min(1.0, (float) $value));
    }

    private function stringOption(string $key, string $default): string
    {
        $value = $this->options[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        return trim($value);
    }

    /**
     * @return string[]
     */
    private function stringListOption(string $key): array
    {
        $value = $this->options[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = array_filter(array_map(static fn (string $item): string => trim($item), explode(',', $value)), static fn (string $item): bool => $item !== '');
        return array_values(array_unique($parts));
    }

    private function flag(string $key, bool $default = false): bool
    {
        if (array_key_exists($key, $this->options)) {
            $value = $this->options[$key];
            if ($value === false) {
                return true;
            }
            if ($value === null || $value === '') {
                return true;
            }
            if (is_string($value)) {
                $normalized = strtolower($value);
                return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
            }
        }

        return $default;
    }

    private function printHelp(): void
    {
        $help = <<<TEXT
订单地名匹配工具

用法：
  php run.php --orders=订单表.csv --mapping=映射表.csv [选项]

核心参数：
  --orders                订单表 CSV 文件路径（必须）
  --mapping               映射表 CSV 文件路径（必须）
  --output-dir            结果输出目录，默认当前目录
  --threshold             命中阈值 τ，默认 0.75
  --high-threshold        高置信阈值，默认 0.85
  --mid-threshold         中置信阈值，默认与 --threshold 相同
  --low-threshold         低置信阈值，默认 0.65
  --allow-partial         市/省任一命中即视为通过（默认开启）
  --require-both          强制市省同时命中方可通过
  --export-details        导出冗余明细字段，便于排错

列映射：
  --order-id-col          订单号列名，默认 A
  --city-col              订单城市列名，默认 BK
  --province-col          订单省份列名，默认 BL
  --map-city-col          映射表城市列名，默认 D
  --map-province-col      映射表省份列名，默认 E
  --map-city-alias-cols   逗号分隔的映射表城市别名列
  --map-province-alias-cols 映射表省份别名列

其他：
  --help                  显示帮助
TEXT;
        fwrite(STDOUT, $help . PHP_EOL);
    }
}
