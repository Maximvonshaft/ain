<?php

declare(strict_types=1);

use OrderMatcher\Config\Config;
use OrderMatcher\IO\Exporter;
use OrderMatcher\IO\SpreadsheetReader;
use OrderMatcher\Matching\Matcher;
use OrderMatcher\Normalization\NormalizerService;

require __DIR__ . '/vendor_autoload.php';

$options = getopt('', ['config::']);
$configPath = $options['config'] ?? __DIR__ . '/config.json';
if (!is_file($configPath)) {
    $fallback = __DIR__ . '/config.example.json';
    if (is_file($fallback)) {
        $configPath = $fallback;
        fwrite(STDERR, "未找到 config.json，已自动使用 config.example.json\n");
    }
}

['normalized' => $config, 'raw' => $rawConfig] = Config::fromFile($configPath);

$normalizer = new NormalizerService($config->aliasMap, $config->noiseWords, $config->preserveCharacters);
$matcher = new Matcher($config, $normalizer);

$mappingData = SpreadsheetReader::read($config->mappingFile, $config->mappingHasHeader);
$matcher->loadMapping($mappingData['rows']);

$ordersData = SpreadsheetReader::read($config->ordersFile, $config->ordersHasHeader);

$cityColumnIndex = \OrderMatcher\Support\ColumnHelper::indexFromLetter($config->ordersColumns['city']);
$provinceColumnIndex = \OrderMatcher\Support\ColumnHelper::indexFromLetter($config->ordersColumns['province']);
$nonEmptyCity = nonEmptyRatio($ordersData['rows'], $cityColumnIndex);
$nonEmptyProvince = nonEmptyRatio($ordersData['rows'], $provinceColumnIndex);

if ($nonEmptyCity < $config->columnNonEmptyThreshold) {
    fwrite(STDERR, sprintf("警告：城市列有效值比例仅为 %.2f%%\n", $nonEmptyCity * 100));
}
if ($nonEmptyProvince < $config->columnNonEmptyThreshold) {
    fwrite(STDERR, sprintf("警告：省份列有效值比例仅为 %.2f%%\n", $nonEmptyProvince * 100));
}

$result = $matcher->match($ordersData['rows']);

$timestamp = date('c');
$configId = substr(hash('sha256', json_encode($rawConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 12);

$mainRows = array_map(fn ($row) => enrichRow($row, $timestamp, $configId), $result['main']);
$reviewRows = array_map(fn ($row) => enrichRow($row, $timestamp, $configId), $result['review']);
$anomalyRows = array_map(function ($row) use ($timestamp, $configId) {
    $row['timestamp'] = $timestamp;
    $row['config_id'] = $configId;
    return $row;
}, $result['anomalies']);

$outputDir = rtrim($config->outputDir, '/');
$mainPath = $outputDir . '/命中订单.csv';
$reviewPath = $outputDir . '/低置信度待人工复核.csv';
$anomalyPath = $outputDir . '/异常数据.csv';
$logPath = $outputDir . '/run_log.json';

if ($config->exportDetails) {
    Exporter::writeCsv($mainPath, mainHeaders(), $mainRows);
}
if ($config->exportReview && $reviewRows !== []) {
    Exporter::writeCsv($reviewPath, reviewHeaders(), prepareReviewRows($reviewRows));
}
if ($config->exportAnomalies && $anomalyRows !== []) {
    Exporter::writeCsv($anomalyPath, anomalyHeaders(), $anomalyRows);
}

$warnings = [];
$totalMatches = $result['stats']['matched_high'] + $result['stats']['matched_mid'] + $result['stats']['matched_low'] + $result['stats']['matched_fail'];
$lowRatio = $totalMatches > 0 ? $result['stats']['matched_low'] / $totalMatches : 0;
$highRatio = $totalMatches > 0 ? $result['stats']['matched_high'] / $totalMatches : 0;
if ($lowRatio > 0.15) {
    $warnings[] = '低置信度比例高于 15%，建议检查阈值是否偏紧';
}
if ($highRatio < 0.5) {
    $warnings[] = '高置信度比例低于 50%，建议放宽归一化或阈值';
}
if ($nonEmptyCity < $config->columnNonEmptyThreshold || $nonEmptyProvince < $config->columnNonEmptyThreshold) {
    $warnings[] = '订单表地址列缺失率较高，请确认源数据完整性';
}

$failReasons = $result['stats']['fail_reasons'];
arsort($failReasons);
$result['stats']['fail_reasons'] = $failReasons;

$log = [
    'timestamp' => $timestamp,
    'config_id' => $configId,
    'config_path' => $configPath,
    'orders_file' => $config->ordersFile,
    'mapping_file' => $config->mappingFile,
    'output_dir' => $outputDir,
    'thresholds' => [
        'high' => $config->highThreshold,
        'pass' => $config->passThreshold,
        'low' => $config->lowThreshold,
    ],
    'stats' => array_merge($result['stats'], [
        'city_non_empty_ratio' => $nonEmptyCity,
        'province_non_empty_ratio' => $nonEmptyProvince,
    ]),
    'warnings' => $warnings,
];

Exporter::writeJson($logPath, $log);

printSummary($result['stats'], $warnings, $timestamp, $configId);

if ($config->samplingRatio > 0 && $result['main'] !== []) {
    $samples = sampleMidConfidence($result['main'], $config->samplingRatio);
    if ($samples !== []) {
        $samplePath = $outputDir . '/中置信抽检样本.csv';
        $sampleRows = array_map(fn ($row) => enrichRow($row, $timestamp, $configId), $samples);
        Exporter::writeCsv($samplePath, mainHeaders(), $sampleRows);
    }
}

/**
 * @param list<array<int, string>> $rows
 */
function nonEmptyRatio(array $rows, int $columnIndex): float
{
    $total = count($rows);
    if ($total === 0) {
        return 0.0;
    }

    $nonEmpty = 0;
    foreach ($rows as $row) {
        if (isset($row[$columnIndex]) && trim((string) $row[$columnIndex]) !== '') {
            $nonEmpty++;
        }
    }

    return $nonEmpty / $total;
}

function enrichRow(array $row, string $timestamp, string $configId): array
{
    $row['timestamp'] = $timestamp;
    $row['config_id'] = $configId;
    return $row;
}

function mainHeaders(): array
{
    return [
        'order_id',
        'order_city',
        'order_province',
        'matched_city',
        'matched_province',
        'score_city',
        'score_province',
        'score_max',
        'match_source',
        'confidence_level',
        'confidence_tag',
        'notes',
        'timestamp',
        'config_id',
    ];
}

function reviewHeaders(): array
{
    return array_merge(mainHeaders(), [
        'candidate_1_city',
        'candidate_1_province',
        'candidate_1_score_city',
        'candidate_1_score_province',
        'candidate_1_score_max',
        'candidate_2_city',
        'candidate_2_province',
        'candidate_2_score_city',
        'candidate_2_score_province',
        'candidate_2_score_max',
        'candidate_3_city',
        'candidate_3_province',
        'candidate_3_score_city',
        'candidate_3_score_province',
        'candidate_3_score_max',
    ]);
}

function anomalyHeaders(): array
{
    return [
        'order_id',
        'order_city',
        'order_province',
        'reason',
        'timestamp',
        'config_id',
    ];
}

function prepareReviewRows(array $rows): array
{
    return array_map(function ($row) {
        $candidates = $row['top_candidates'] ?? [];
        unset($row['top_candidates']);
        for ($i = 0; $i < 3; $i++) {
            $candidate = $candidates[$i] ?? ['city' => '', 'province' => '', 'score_city' => '', 'score_province' => '', 'score_max' => ''];
            $row['candidate_' . ($i + 1) . '_city'] = $candidate['city'];
            $row['candidate_' . ($i + 1) . '_province'] = $candidate['province'];
            $row['candidate_' . ($i + 1) . '_score_city'] = $candidate['score_city'];
            $row['candidate_' . ($i + 1) . '_score_province'] = $candidate['score_province'];
            $row['candidate_' . ($i + 1) . '_score_max'] = $candidate['score_max'];
        }
        return $row;
    }, $rows);
}

function printSummary(array $stats, array $warnings, string $timestamp, string $configId): void
{
    echo "======== 匹配完成 ========\n";
    echo '时间：' . $timestamp . "\n";
    echo '配置ID：' . $configId . "\n";
    echo '去重前订单数：' . $stats['total_orders'] . "\n";
    echo '去重后订单数：' . $stats['deduped_orders'] . "\n";
    echo '高置信命中：' . $stats['matched_high'] . "\n";
    echo '中置信命中：' . $stats['matched_mid'] . "\n";
    echo '低置信命中：' . $stats['matched_low'] . "\n";
    echo '未命中：' . $stats['matched_fail'] . "\n";
    echo '平均城市得分：' . round($stats['avg_city_score'], 4) . "\n";
    echo '平均省份得分：' . round($stats['avg_province_score'], 4) . "\n";
    echo '平均最高得分：' . round($stats['avg_max_score'], 4) . "\n";
    if ($warnings !== []) {
        echo "---- 告警 ----\n";
        foreach ($warnings as $warning) {
            echo '- ' . $warning . "\n";
        }
    }
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function sampleMidConfidence(array $rows, float $ratio): array
{
    $midRows = array_values(array_filter($rows, static fn ($row) => ($row['confidence_level'] ?? '') === 'Mid'));
    if ($midRows === []) {
        return [];
    }

    $count = max(1, (int) floor(count($midRows) * $ratio));
    shuffle($midRows);

    return array_slice($midRows, 0, $count);
}
