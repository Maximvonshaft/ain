<?php

declare(strict_types=1);

namespace OrderMatcher\Config;

use RuntimeException;

final class Config
{
    public string $ordersFile;
    public string $mappingFile;
    public string $outputDir;
    public array $ordersColumns;
    public array $mappingColumns;
    public bool $ordersHasHeader;
    public bool $mappingHasHeader;
    public bool $requireBothMatch;
    public bool $exportDetails;
    public float $highThreshold;
    public float $passThreshold;
    public float $lowThreshold;
    public float $baseWeight;
    public float $tokenWeight;
    public float $aliasBonus;
    public float $samplingRatio;
    public float $columnNonEmptyThreshold;
    public int $maxCandidates;
    public array $aliasMap;
    public array $noiseWords;
    public string $preserveCharacters;
    public bool $exportAnomalies;
    public bool $exportReview;

    private function __construct()
    {
    }

    /**
     * @return array{normalized: self, raw: array}
     */
    public static function fromFile(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('配置文件不存在：%s', $path));
        }

        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException(sprintf('无法读取配置文件：%s', $path));
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('配置文件格式不正确，应为 JSON 对象');
        }

        $config = new self();

        $config->ordersFile = self::requireString($data, 'orders_file');
        $config->mappingFile = self::requireString($data, 'mapping_file');
        $config->outputDir = self::requireString($data, 'output_dir');

        $config->ordersColumns = self::prepareColumns($data['orders_columns'] ?? [], ['order_id', 'city', 'province']);
        $config->mappingColumns = self::prepareColumns($data['mapping_columns'] ?? [], ['city', 'province']);

        $config->ordersHasHeader = (bool)($data['orders_has_header'] ?? true);
        $config->mappingHasHeader = (bool)($data['mapping_has_header'] ?? true);
        $config->requireBothMatch = (bool)($data['require_both_match'] ?? false);
        $config->exportDetails = (bool)($data['export_details'] ?? true);
        $config->exportReview = (bool)($data['export_review'] ?? true);
        $config->exportAnomalies = (bool)($data['export_anomalies'] ?? true);

        $thresholds = $data['thresholds'] ?? [];
        if (!is_array($thresholds)) {
            $thresholds = [];
        }
        $config->highThreshold = self::sanitizeFloat($thresholds['high'] ?? 0.85);
        $config->passThreshold = self::sanitizeFloat($thresholds['pass'] ?? 0.75);
        $config->lowThreshold = self::sanitizeFloat($thresholds['low'] ?? 0.65);

        $weights = $data['weights'] ?? [];
        if (!is_array($weights)) {
            $weights = [];
        }
        $config->baseWeight = self::sanitizeFloat($weights['base'] ?? 0.7);
        $config->tokenWeight = self::sanitizeFloat($weights['token'] ?? 0.3);
        $weightSum = $config->baseWeight + $config->tokenWeight;
        if ($weightSum > 0) {
            $config->baseWeight /= $weightSum;
            $config->tokenWeight /= $weightSum;
        } else {
            $config->baseWeight = 0.7;
            $config->tokenWeight = 0.3;
        }

        $config->aliasBonus = min(max(self::sanitizeFloat($data['alias_bonus'] ?? 0.05), 0.0), 0.25);
        $config->samplingRatio = min(max(self::sanitizeFloat($data['sampling_ratio'] ?? 0.03), 0.0), 1.0);
        $config->columnNonEmptyThreshold = min(max(self::sanitizeFloat($data['column_non_empty_threshold'] ?? 0.6), 0.0), 1.0);
        $config->maxCandidates = max((int)($data['max_candidates'] ?? 400), 1);

        $config->aliasMap = self::prepareAliases($data['aliases'] ?? []);
        $config->noiseWords = self::prepareNoiseWords($data['noise_words'] ?? []);
        $config->preserveCharacters = (string)($data['preserve_characters'] ?? '');

        return ['normalized' => $config, 'raw' => $data];
    }

    private static function requireString(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            throw new RuntimeException(sprintf('配置字段 %s 缺失或为空', $key));
        }

        return $data[$key];
    }

    private static function sanitizeFloat(mixed $value): float
    {
        if (!is_numeric($value)) {
            return 0.0;
        }

        return (float) $value;
    }

    private static function prepareColumns(mixed $columns, array $requiredKeys): array
    {
        if (!is_array($columns)) {
            throw new RuntimeException('列配置必须为对象');
        }

        $result = [];
        foreach ($requiredKeys as $key) {
            if (!isset($columns[$key]) || !is_string($columns[$key]) || trim($columns[$key]) === '') {
                throw new RuntimeException(sprintf('列配置缺少字段：%s', $key));
            }
            $result[$key] = strtoupper(trim($columns[$key]));
        }

        return $result;
    }

    private static function prepareAliases(mixed $aliases): array
    {
        $map = [];
        if (is_array($aliases)) {
            if (array_is_list($aliases)) {
                foreach ($aliases as $group) {
                    if (!is_array($group) || $group === []) {
                        continue;
                    }
                    $canonical = (string) array_values($group)[0];
                    foreach ($group as $alias) {
                        $map[(string) $alias] = $canonical;
                    }
                }
            } else {
                foreach ($aliases as $alias => $canonical) {
                    $map[(string) $alias] = (string) $canonical;
                }
            }
        }

        return $map;
    }

    private static function prepareNoiseWords(mixed $noiseWords): array
    {
        if (!is_array($noiseWords)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($word) => (string) $word, $noiseWords), static fn ($word) => trim($word) !== ''));
    }
}
