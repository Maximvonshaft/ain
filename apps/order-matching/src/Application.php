<?php

namespace Apps\OrderMatching;

final class Application
{
    private Normalizer $normalizer;
    private CsvReader $csvReader;

    public function __construct()
    {
        $this->normalizer = new Normalizer();
        $this->csvReader = new CsvReader();
    }

    public function run(array $argv): void
    {
        $options = new CliOptions($argv);
        $orders = $this->csvReader->read($options->ordersPath());
        $mapping = $this->csvReader->read($options->mappingPath());

        if ($orders === []) {
            throw new \RuntimeException('订单表为空，无法执行匹配。');
        }

        if ($mapping === []) {
            throw new \RuntimeException('映射表为空，无法执行匹配。');
        }

        $this->validateColumns($orders[0], [
            $options->orderIdColumn(),
            $options->orderCityColumn(),
            $options->orderProvinceColumn(),
        ], '订单表');

        $this->validateColumns($mapping[0], [
            $options->mapCityColumn(),
            $options->mapProvinceColumn(),
        ], '映射表');

        $candidateIndex = new CandidateIndex();
        $entries = $this->buildMappingEntries($mapping, $options, $candidateIndex);
        if ($entries === []) {
            throw new \RuntimeException('映射表中未找到任何有效的市/省数据。');
        }

        $scoring = new ScoringEngine($options->allowPartial());
        $classifier = new ConfidenceClassifier($options->highThreshold(), $options->midThreshold(), $options->lowThreshold());
        $stats = new StatsCollector();

        $timestamp = date('c');
        $configId = $options->configId();

        $mainRows = [];
        $reviewRows = [];
        $failRows = [];
        $anomalyRows = [];

        foreach ($orders as $row) {
            $stats->incrementTotal();
            $orderId = $this->stringValue($row, $options->orderIdColumn());
            $orderCity = $this->stringValue($row, $options->orderCityColumn());
            $orderProvince = $this->stringValue($row, $options->orderProvinceColumn());

            if ($orderCity === '' && $orderProvince === '') {
                $stats->incrementMissing();
                $anomalyRows[] = [
                    'order_id' => $orderId,
                    'order_city' => $orderCity,
                    'order_province' => $orderProvince,
                    'issue' => '缺失地址',
                    'config_id' => $configId,
                    'timestamp' => $timestamp,
                ];
                continue;
            }

            $stats->incrementEligible();

            $normalizedCity = $this->normalizer->normalize($orderCity);
            $normalizedProvince = $this->normalizer->normalize($orderProvince);
            $cityTokens = $this->normalizer->tokensFromNormalized($normalizedCity);
            $provinceTokens = $this->normalizer->tokensFromNormalized($normalizedProvince);

            $candidates = $candidateIndex->candidates($normalizedCity, $normalizedProvince);
            $evaluation = $scoring->evaluate(
                $normalizedCity,
                $normalizedProvince,
                $cityTokens,
                $provinceTokens,
                $candidates
            );

            /** @var Score|null $best */
            $best = $evaluation['best'];
            /** @var list<Score> $topScores */
            $topScores = $evaluation['top'];

            if ($best === null) {
                $stats->recordConfidence('Fail');
                $stats->recordFailReason('no_candidate');
                $failRows[] = [
                    'order_id' => $orderId,
                    'order_city' => $orderCity,
                    'order_province' => $orderProvince,
                    'score_max' => '0',
                    'reason' => 'no_candidate',
                    'config_id' => $configId,
                    'timestamp' => $timestamp,
                ];
                continue;
            }

            $confidence = $classifier->classify($best->max);
            $passes = $this->passesThreshold($best, $options);
            $notes = $this->buildNotes($best, $topScores, $options, $stats, $passes);

            $stats->recordConfidence($confidence);

            if ($passes && in_array($confidence, ['High', 'Mid'], true)) {
                $mainRows[] = $this->buildMainRow($best, $orderId, $orderCity, $orderProvince, $normalizedCity, $normalizedProvince, $notes, $timestamp, $configId, $options, $confidence);
                continue;
            }

            if ($best->max >= $options->lowThreshold()) {
                $reviewRows = array_merge($reviewRows, $this->buildReviewRows($best, $topScores, $orderId, $orderCity, $orderProvince, $confidence, $notes, $configId, $timestamp));
                if (!$passes) {
                    $stats->recordFailReason($options->allowPartial() ? 'threshold_not_met' : 'both_required');
                }
                continue;
            }

            $stats->recordFailReason('score_below_low');
            $failRows[] = [
                'order_id' => $orderId,
                'order_city' => $orderCity,
                'order_province' => $orderProvince,
                'score_max' => $this->formatScore($best->max),
                'reason' => $passes ? 'low_confidence' : 'score_below_low',
                'config_id' => $configId,
                'timestamp' => $timestamp,
            ];
        }

        $summary = $stats->summary();
        $summary['timestamp'] = $timestamp;
        $summary['config_id'] = $configId;
        $summary['threshold'] = $options->threshold();
        $summary['high_threshold'] = $options->highThreshold();
        $summary['mid_threshold'] = $options->midThreshold();
        $summary['low_threshold'] = $options->lowThreshold();

        $exporter = new ResultExporter($options->outputDir(), $options->exportDetails());
        $exporter->export($mainRows, $reviewRows, $failRows, $anomalyRows, $summary);
    }

    /**
     * @param array<string, string|null> $row
     * @param list<string> $columns
     */
    private function validateColumns(array $row, array $columns, string $label): void
    {
        foreach ($columns as $column) {
            if (!array_key_exists($column, $row)) {
                throw new \RuntimeException(sprintf('%s缺少列：%s', $label, $column));
            }
        }
    }

    /**
     * @param array<int, array<string, string|null>> $mapping
     * @return list<MappingEntry>
     */
    private function buildMappingEntries(array $mapping, CliOptions $options, CandidateIndex $index): array
    {
        $entries = [];
        $rowOffset = 2; // header + 1-based
        foreach ($mapping as $idx => $row) {
            $city = $this->stringValue($row, $options->mapCityColumn());
            $province = $this->stringValue($row, $options->mapProvinceColumn());
            if ($city === '' && $province === '') {
                continue;
            }

            $cityAliases = $this->collectAliases($row, $options->mapCityAliasColumns());
            $provinceAliases = $this->collectAliases($row, $options->mapProvinceAliasColumns());

            $entry = new MappingEntry($idx + $rowOffset, $city, $province, $cityAliases, $provinceAliases, $this->normalizer);
            $index->add($entry);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, string|null> $row
     * @param list<string> $columns
     * @return list<string>
     */
    private function collectAliases(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $column) {
            $value = $this->stringValue($row, $column);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function passesThreshold(Score $score, CliOptions $options): bool
    {
        if ($options->allowPartial()) {
            return $score->max >= $options->threshold();
        }

        return $score->city >= $options->threshold() && $score->province >= $options->threshold();
    }

    /**
     * @param list<Score> $topScores
     * @return list<string>
     */
    private function buildNotes(Score $best, array $topScores, CliOptions $options, StatsCollector $stats, bool $passes): array
    {
        $notes = [];

        if (isset($topScores[1]) && ($best->max - $topScores[1]->max) < 0.03) {
            $notes[] = '多义冲突';
            $stats->recordConflict();
        }

        if ($best->city >= $options->highThreshold() && $best->province < 0.5) {
            $notes[] = '省份待核';
        }

        if ($best->province >= $options->highThreshold() && $best->city < 0.5) {
            $notes[] = '城市待核';
        }

        if ($best->cityVariant?->isAlias) {
            $notes[] = '城市别名命中';
        }

        if ($best->provinceVariant?->isAlias) {
            $notes[] = '省份别名命中';
        }

        if (!$passes) {
            $notes[] = $options->allowPartial() ? '未达阈值' : '市省需同时命中';
        }

        return array_values(array_unique($notes));
    }

    /**
     * @return array<string, string>
     */
    private function buildMainRow(
        Score $best,
        string $orderId,
        string $orderCity,
        string $orderProvince,
        string $normalizedCity,
        string $normalizedProvince,
        array $notes,
        string $timestamp,
        string $configId,
        CliOptions $options,
        string $confidence
    ): array {
        $row = [
            'order_id' => $orderId,
            'order_city' => $orderCity,
            'order_province' => $orderProvince,
            'matched_city' => $best->entry->city(),
            'matched_province' => $best->entry->province(),
            'score_city' => $this->formatScore($best->city),
            'score_province' => $this->formatScore($best->province),
            'score_max' => $this->formatScore($best->max),
            'source' => $best->source,
            'confidence' => $confidence,
            'notes' => implode('; ', $notes),
            'mapping_row' => (string) $best->entry->rowNumber(),
            'city_variant' => $best->cityVariant?->original ?? '',
            'province_variant' => $best->provinceVariant?->original ?? '',
            'timestamp' => $timestamp,
            'config_id' => $configId,
        ];

        if ($options->exportDetails()) {
            $row['normalized_city'] = $normalizedCity;
            $row['normalized_province'] = $normalizedProvince;
        }

        return $row;
    }

    /**
     * @param list<Score> $topScores
     * @return list<array<string, string>>
     */
    private function buildReviewRows(
        Score $best,
        array $topScores,
        string $orderId,
        string $orderCity,
        string $orderProvince,
        string $confidence,
        array $notes,
        string $configId,
        string $timestamp
    ): array {
        $rows = [];
        $candidates = array_slice($topScores, 0, 3);
        foreach ($candidates as $index => $candidate) {
            $rows[] = [
                'order_id' => $orderId,
                'order_city' => $orderCity,
                'order_province' => $orderProvince,
                'score_max' => $this->formatScore($best->max),
                'confidence' => $confidence,
                'notes' => implode('; ', $notes),
                'candidate_rank' => (string) ($index + 1),
                'candidate_city' => $candidate->entry->city(),
                'candidate_province' => $candidate->entry->province(),
                'candidate_score_city' => $this->formatScore($candidate->city),
                'candidate_score_province' => $this->formatScore($candidate->province),
                'candidate_score_max' => $this->formatScore($candidate->max),
                'candidate_source' => $candidate->source,
                'candidate_city_variant' => $candidate->cityVariant?->original ?? '',
                'candidate_province_variant' => $candidate->provinceVariant?->original ?? '',
                'mapping_row' => (string) $candidate->entry->rowNumber(),
                'config_id' => $configId,
                'timestamp' => $timestamp,
            ];
        }

        return $rows;
    }

    private function formatScore(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    /**
     * @param array<string, string|null> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? '';
        return is_string($value) ? trim($value) : (string) $value;
    }
}
