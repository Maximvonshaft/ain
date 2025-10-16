<?php

declare(strict_types=1);

namespace OrderMatcher\Matching;

use OrderMatcher\Config\Config;
use OrderMatcher\Normalization\NormalizerService;
use OrderMatcher\Support\ColumnHelper;
use RuntimeException;

final class Matcher
{
    private Config $config;
    private NormalizerService $normalizer;

    /**
     * @var list<array{
     *     city_raw:string,
     *     province_raw:string,
     *     norm_city:array{normalized:string,tokens:array<int,string>,alias_applied:bool},
     *     norm_province:array{normalized:string,tokens:array<int,string>,alias_applied:bool}
     * }>
     */
    private array $mapping = [];

    /** @var array<string, list<int>> */
    private array $cityBuckets = [];

    /** @var array<string, list<int>> */
    private array $provinceBuckets = [];

    public function __construct(Config $config, NormalizerService $normalizer)
    {
        $this->config = $config;
        $this->normalizer = $normalizer;
    }

    /**
     * @param list<array<int, string>> $rows
     */
    public function loadMapping(array $rows): void
    {
        $this->mapping = [];
        $this->cityBuckets = [];
        $this->provinceBuckets = [];

        $cityColumn = ColumnHelper::indexFromLetter($this->config->mappingColumns['city']);
        $provinceColumn = ColumnHelper::indexFromLetter($this->config->mappingColumns['province']);

        foreach ($rows as $row) {
            $city = $row[$cityColumn] ?? '';
            $province = $row[$provinceColumn] ?? '';
            if (!is_string($city)) {
                $city = '';
            }
            if (!is_string($province)) {
                $province = '';
            }
            $city = trim($city);
            $province = trim($province);

            if ($city === '' && $province === '') {
                continue;
            }

            $normCity = $this->normalizer->normalize($city);
            $normProvince = $this->normalizer->normalize($province);

            $index = count($this->mapping);
            $this->mapping[] = [
                'city_raw' => $city,
                'province_raw' => $province,
                'norm_city' => $normCity,
                'norm_province' => $normProvince,
            ];

            $this->addToBucket($this->cityBuckets, $normCity['normalized'], $index);
            $this->addToBucket($this->provinceBuckets, $normProvince['normalized'], $index);
        }

        if ($this->mapping === []) {
            throw new RuntimeException('映射表中未找到有效的市/省数据');
        }
    }

    /**
     * @param list<array<int, string>> $rows
     * @return array{
     *     main: list<array<string, mixed>>,
     *     review: list<array<string, mixed>>,
     *     anomalies: list<array<string, string>>,
     *     stats: array<string, mixed>
     * }
     */
    public function match(array $rows): array
    {
        $orderColumn = ColumnHelper::indexFromLetter($this->config->ordersColumns['order_id']);
        $cityColumn = ColumnHelper::indexFromLetter($this->config->ordersColumns['city']);
        $provinceColumn = ColumnHelper::indexFromLetter($this->config->ordersColumns['province']);

        $seenOrders = [];
        $dedupedRows = [];
        $dedupInfo = ['duplicates' => 0, 'replaced' => 0];

        foreach ($rows as $row) {
            $orderId = isset($row[$orderColumn]) ? trim((string) $row[$orderColumn]) : '';
            if ($orderId === '') {
                continue;
            }

            $city = isset($row[$cityColumn]) ? trim((string) $row[$cityColumn]) : '';
            $province = isset($row[$provinceColumn]) ? trim((string) $row[$provinceColumn]) : '';

            $completeness = $this->calculateCompleteness($city, $province);

            if (isset($seenOrders[$orderId])) {
                $dedupInfo['duplicates']++;
                $existingIndex = $seenOrders[$orderId];
                if ($completeness > $dedupedRows[$existingIndex]['completeness']) {
                    $dedupedRows[$existingIndex] = [
                        'order_id' => $orderId,
                        'city' => $city,
                        'province' => $province,
                        'completeness' => $completeness,
                    ];
                    $dedupInfo['replaced']++;
                }
                continue;
            }

            $seenOrders[$orderId] = count($dedupedRows);
            $dedupedRows[] = [
                'order_id' => $orderId,
                'city' => $city,
                'province' => $province,
                'completeness' => $completeness,
            ];
        }

        $main = [];
        $review = [];
        $anomalies = [];

        $stats = [
            'total_orders' => count($rows),
            'deduped_orders' => count($dedupedRows),
            'matched_high' => 0,
            'matched_mid' => 0,
            'matched_low' => 0,
            'matched_fail' => 0,
            'missing_address' => 0,
            'avg_city_score' => 0.0,
            'avg_province_score' => 0.0,
            'avg_max_score' => 0.0,
            'scores_count' => 0,
            'dedup_info' => $dedupInfo,
            'fail_reasons' => [],
        ];

        foreach ($dedupedRows as $row) {
            $city = $row['city'];
            $province = $row['province'];
            if ($city === '' && $province === '') {
                $stats['missing_address']++;
                $anomalies[] = [
                    'order_id' => $row['order_id'],
                    'order_city' => $city,
                    'order_province' => $province,
                    'reason' => '缺失地址',
                ];
                continue;
            }

            $result = $this->matchRow($row['order_id'], $city, $province);
            $stats['avg_city_score'] += $result['score_city'];
            $stats['avg_province_score'] += $result['score_province'];
            $stats['avg_max_score'] += $result['score_max'];
            $stats['scores_count']++;

            switch ($result['confidence_level']) {
                case 'High':
                    $stats['matched_high']++;
                    $main[] = $result;
                    break;
                case 'Mid':
                    $stats['matched_mid']++;
                    $main[] = $result;
                    break;
                case 'Low':
                    $stats['matched_low']++;
                    $review[] = $result;
                    break;
                default:
                    $stats['matched_fail']++;
                    $anomalies[] = [
                        'order_id' => $row['order_id'],
                        'order_city' => $city,
                        'order_province' => $province,
                        'reason' => $result['notes'] === '' ? '未命中' : $result['notes'],
                    ];
                    $stats['fail_reasons'][$result['notes'] === '' ? '未命中' : $result['notes']] = ($stats['fail_reasons'][$result['notes'] === '' ? '未命中' : $result['notes']] ?? 0) + 1;
                    break;
            }
        }

        if ($stats['scores_count'] > 0) {
            $stats['avg_city_score'] /= $stats['scores_count'];
            $stats['avg_province_score'] /= $stats['scores_count'];
            $stats['avg_max_score'] /= $stats['scores_count'];
        }

        return [
            'main' => $main,
            'review' => $review,
            'anomalies' => $anomalies,
            'stats' => $stats,
        ];
    }

    private function matchRow(string $orderId, string $city, string $province): array
    {
        $normCity = $this->normalizer->normalize($city);
        $normProvince = $this->normalizer->normalize($province);

        $candidates = $this->collectCandidates($normCity['normalized'], $normProvince['normalized']);
        if ($candidates === []) {
            return [
                'order_id' => $orderId,
                'order_city' => $city,
                'order_province' => $province,
                'matched_city' => '',
                'matched_province' => '',
                'score_city' => 0.0,
                'score_province' => 0.0,
                'score_max' => 0.0,
                'match_source' => 'none',
                'confidence_level' => 'Fail',
                'confidence_tag' => '❌未命中',
                'notes' => '候选为空',
                'top_candidates' => [],
            ];
        }

        $evaluated = [];
        foreach ($candidates as $index) {
            $mapping = $this->mapping[$index];
            $scoreCity = Similarity::combined($normCity, $mapping['norm_city'], $this->config->baseWeight, $this->config->tokenWeight, $this->config->aliasBonus);
            $scoreProvince = Similarity::combined($normProvince, $mapping['norm_province'], $this->config->baseWeight, $this->config->tokenWeight, $this->config->aliasBonus);
            $scoreMax = max($scoreCity, $scoreProvince);

            $evaluated[] = [
                'index' => $index,
                'score_city' => $scoreCity,
                'score_province' => $scoreProvince,
                'score_max' => $scoreMax,
                'mapping' => $mapping,
            ];
        }

        usort($evaluated, static fn ($a, $b) => $b['score_max'] <=> $a['score_max']);
        $topCandidates = array_slice($evaluated, 0, min(3, count($evaluated)));

        if ($topCandidates === []) {
            return [
                'order_id' => $orderId,
                'order_city' => $city,
                'order_province' => $province,
                'matched_city' => '',
                'matched_province' => '',
                'score_city' => 0.0,
                'score_province' => 0.0,
                'score_max' => 0.0,
                'match_source' => 'none',
                'confidence_level' => 'Fail',
                'confidence_tag' => '❌未命中',
                'notes' => '无有效候选',
                'top_candidates' => [],
            ];
        }

        $best = $topCandidates[0];
        $matchSource = $this->determineSource($best['score_city'], $best['score_province']);
        $confidence = $this->determineConfidence($best['score_city'], $best['score_province'], $best['score_max']);
        $notes = [];

        if (isset($topCandidates[1])) {
            $diff = $best['score_max'] - $topCandidates[1]['score_max'];
            if ($diff < 0.03) {
                $confidence = 'Low';
                $notes[] = '多候选冲突';
            }
        }

        if ($best['score_city'] >= $this->config->highThreshold && $best['score_province'] < 0.5) {
            $notes[] = '省份待核';
        }

        if ($best['score_province'] >= $this->config->highThreshold && $best['score_city'] < 0.5) {
            $notes[] = '城市待核';
        }

        $confidenceTag = match ($confidence) {
            'High' => '✅高置信',
            'Mid' => '⚠中置信',
            'Low' => '⚠低置信',
            default => '❌未命中',
        };

        return [
            'order_id' => $orderId,
            'order_city' => $city,
            'order_province' => $province,
            'matched_city' => $best['mapping']['city_raw'],
            'matched_province' => $best['mapping']['province_raw'],
            'score_city' => round($best['score_city'], 4),
            'score_province' => round($best['score_province'], 4),
            'score_max' => round($best['score_max'], 4),
            'match_source' => $matchSource,
            'confidence_level' => $confidence,
            'confidence_tag' => $confidenceTag,
            'notes' => implode(';', $notes),
            'top_candidates' => array_map(static function ($candidate) {
                return [
                    'city' => $candidate['mapping']['city_raw'],
                    'province' => $candidate['mapping']['province_raw'],
                    'score_city' => round($candidate['score_city'], 4),
                    'score_province' => round($candidate['score_province'], 4),
                    'score_max' => round($candidate['score_max'], 4),
                ];
            }, $topCandidates),
        ];
    }

    private function determineSource(float $cityScore, float $provinceScore): string
    {
        $cityHit = $cityScore >= $this->config->passThreshold;
        $provinceHit = $provinceScore >= $this->config->passThreshold;

        if ($cityHit && $provinceHit) {
            return 'city+province';
        }

        if ($cityHit) {
            return 'city';
        }

        if ($provinceHit) {
            return 'province';
        }

        return 'none';
    }

    private function determineConfidence(float $cityScore, float $provinceScore, float $maxScore): string
    {
        $cityHit = $cityScore >= $this->config->passThreshold;
        $provinceHit = $provinceScore >= $this->config->passThreshold;
        $passes = $this->config->requireBothMatch ? ($cityHit && $provinceHit) : ($cityHit || $provinceHit);

        if (!$passes) {
            if ($maxScore >= $this->config->lowThreshold) {
                return 'Low';
            }
            return 'Fail';
        }

        if ($maxScore >= $this->config->highThreshold) {
            return 'High';
        }

        if ($maxScore >= $this->config->passThreshold) {
            return 'Mid';
        }

        if ($maxScore >= $this->config->lowThreshold) {
            return 'Low';
        }

        return 'Fail';
    }

    private function addToBucket(array &$buckets, string $normalized, int $index): void
    {
        $key = $this->prefixKey($normalized);
        $buckets[$key][] = $index;
    }

    /**
     * @return list<int>
     */
    private function collectCandidates(string $city, string $province): array
    {
        $candidates = [];
        $cityKey = $this->prefixKey($city);
        $provinceKey = $this->prefixKey($province);

        if (isset($this->cityBuckets[$cityKey])) {
            $candidates = array_merge($candidates, $this->cityBuckets[$cityKey]);
        }
        if (isset($this->provinceBuckets[$provinceKey])) {
            $candidates = array_merge($candidates, $this->provinceBuckets[$provinceKey]);
        }

        if ($candidates === []) {
            $candidates = array_keys($this->mapping);
        } else {
            $candidates = array_values(array_unique($candidates));
        }

        if (count($candidates) > $this->config->maxCandidates) {
            $candidates = array_slice($candidates, 0, $this->config->maxCandidates);
        }

        return $candidates;
    }

    private function prefixKey(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '__';
        }

        $key = mb_substr($value, 0, 2, 'UTF-8');
        return $key === '' ? '__' : $key;
    }

    private function calculateCompleteness(string $city, string $province): float
    {
        $score = 0.0;
        if ($city !== '') {
            $score += 1 + strlen($city) / 100;
        }
        if ($province !== '') {
            $score += 1 + strlen($province) / 100;
        }

        return $score;
    }
}
