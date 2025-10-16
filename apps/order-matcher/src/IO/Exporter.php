<?php

declare(strict_types=1);

namespace OrderMatcher\IO;

use RuntimeException;

final class Exporter
{
    /**
     * @param list<string> $headers
     * @param list<array<int|string, mixed>> $rows
     */
    public static function writeCsv(string $path, array $headers, array $rows): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('无法创建目录：%s', $dir));
            }
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('无法写入文件：%s', $path));
        }

        try {
            fputcsv($handle, $headers, ',', '"', '\\');
            foreach ($rows as $row) {
                $line = [];
                foreach ($headers as $header) {
                    $line[] = isset($row[$header]) ? (string) $row[$header] : '';
                }
                fputcsv($handle, $line, ',', '"', '\\');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException(sprintf('无法创建目录：%s', $dir));
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('JSON 编码失败');
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException(sprintf('无法写入文件：%s', $path));
        }
    }
}
