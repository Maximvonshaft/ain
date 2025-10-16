<?php

namespace Apps\OrderMatching;

final class ResultExporter
{
    public function __construct(private readonly string $outputDir, private readonly bool $exportDetails)
    {
    }

    /**
     * @param list<array<string, string>> $mainRows
     * @param list<array<string, string>> $reviewRows
     * @param list<array<string, string>> $failRows
     * @param list<array<string, string>> $anomalyRows
     * @param array<string, mixed> $summary
     */
    public function export(array $mainRows, array $reviewRows, array $failRows, array $anomalyRows, array $summary): void
    {
        $this->prepareDirectory();
        $timestamp = date('Ymd_His');

        $this->writeCsv('命中订单_' . $timestamp . '.csv', $this->mainHeader(), $mainRows);
        $this->writeCsv('低置信度待人工复核_' . $timestamp . '.csv', $this->reviewHeader(), $reviewRows);
        $this->writeCsv('未命中订单_' . $timestamp . '.csv', $this->failHeader(), $failRows);
        $this->writeCsv('异常订单_' . $timestamp . '.csv', $this->anomalyHeader(), $anomalyRows);

        $summaryPath = $this->path('运行摘要_' . $timestamp . '.json');
        file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->printSummary($summary, $summaryPath);
    }

    private function prepareDirectory(): void
    {
        if (!is_dir($this->outputDir)) {
            if (!@mkdir($this->outputDir, 0775, true) && !is_dir($this->outputDir)) {
                $error = error_get_last();
                $reason = $error['message'] ?? 'unknown';
                throw new \RuntimeException(sprintf('无法创建输出目录：%s (%s)', $this->outputDir, $reason));
            }
        }
    }

    /**
     * @param list<string> $header
     * @param list<array<string, string>> $rows
     */
    private function writeCsv(string $filename, array $header, array $rows): void
    {
        $path = $this->path($filename);
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('无法写入文件：%s', $path));
        }

        fputcsv($handle, $header);
        foreach ($rows as $row) {
            $ordered = [];
            foreach ($header as $column) {
                $ordered[] = $row[$column] ?? '';
            }
            fputcsv($handle, $ordered);
        }

        fclose($handle);
    }

    /**
     * @return list<string>
     */
    private function mainHeader(): array
    {
        $header = [
            'order_id',
            'order_city',
            'order_province',
            'matched_city',
            'matched_province',
            'score_city',
            'score_province',
            'score_max',
            'source',
            'confidence',
            'notes',
            'mapping_row',
            'city_variant',
            'province_variant',
            'timestamp',
            'config_id',
        ];

        if ($this->exportDetails) {
            $header[] = 'normalized_city';
            $header[] = 'normalized_province';
        }

        return $header;
    }

    /**
     * @return list<string>
     */
    private function reviewHeader(): array
    {
        return [
            'order_id',
            'order_city',
            'order_province',
            'score_max',
            'confidence',
            'notes',
            'candidate_rank',
            'candidate_city',
            'candidate_province',
            'candidate_score_city',
            'candidate_score_province',
            'candidate_score_max',
            'candidate_source',
            'candidate_city_variant',
            'candidate_province_variant',
            'mapping_row',
            'config_id',
            'timestamp',
        ];
    }

    /**
     * @return list<string>
     */
    private function failHeader(): array
    {
        return [
            'order_id',
            'order_city',
            'order_province',
            'score_max',
            'reason',
            'config_id',
            'timestamp',
        ];
    }

    /**
     * @return list<string>
     */
    private function anomalyHeader(): array
    {
        return [
            'order_id',
            'order_city',
            'order_province',
            'issue',
            'config_id',
            'timestamp',
        ];
    }

    private function path(string $filename): string
    {
        return rtrim($this->outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function printSummary(array $summary, string $summaryPath): void
    {
        $lines = [
            '运行完成 ✅',
            '输出目录: ' . $this->outputDir,
            '摘要文件: ' . $summaryPath,
            '数据统计:',
        ];

        $metrics = [
            'total_rows' => '总行数',
            'eligible_rows' => '参与匹配行数',
            'missing_address_rows' => '缺失地址',
            'high' => '高置信命中',
            'mid' => '中置信命中',
            'low' => '低置信灰度',
            'fail' => '未命中',
            'conflicts' => '多义冲突',
        ];

        foreach ($metrics as $key => $label) {
            if (isset($summary[$key])) {
                $lines[] = sprintf('  - %s: %s', $label, (string) $summary[$key]);
            }
        }

        $failReasons = $summary['fail_reasons_top'] ?? [];
        if (is_array($failReasons) && $failReasons !== []) {
            $lines[] = 'Top 未命中原因:';
            foreach ($failReasons as $reason => $count) {
                $lines[] = sprintf('  - %s (%d)', (string) $reason, (int) $count);
            }
        }

        fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
    }
}
