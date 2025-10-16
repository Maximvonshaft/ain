<?php

declare(strict_types=1);

namespace OrderMatcher\IO;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

final class SpreadsheetReader
{
    /**
     * @return array{header: ?array, rows: list<array<int, string>>}
     */
    public static function read(string $path, bool $hasHeader): array
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('文件不存在：%s', $path));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        switch ($extension) {
            case 'csv':
                $rows = self::readCsv($path);
                break;
            case 'xlsx':
                $rows = self::readXlsx($path);
                break;
            default:
                throw new RuntimeException(sprintf('暂不支持的文件类型：%s', $extension));
        }

        $header = null;
        if ($hasHeader && $rows !== []) {
            $header = array_shift($rows);
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * @return list<array<int, string>>
     */
    private static function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException(sprintf('无法打开 CSV 文件：%s', $path));
        }

        try {
            $encodingChecked = false;
            while (($line = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (!$encodingChecked) {
                    $encodingChecked = true;
                    if (isset($line[0])) {
                        $line[0] = self::removeUtf8Bom($line[0]);
                    }
                }
                $rows[] = array_map(static fn ($cell) => is_string($cell) ? trim($cell) : '', $line);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return list<array<int, string>>
     */
    private static function readXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException(sprintf('无法打开 XLSX 文件：%s', $path));
        }

        $sheetXml = self::locateWorksheetXml($zip);
        if ($sheetXml === null) {
            $zip->close();
            throw new RuntimeException('未找到工作表数据');
        }

        $sharedStrings = self::loadSharedStrings($zip);
        $sheetData = new SimpleXMLElement($sheetXml);

        $rows = [];
        if (!isset($sheetData->sheetData->row)) {
            $zip->close();
            return $rows;
        }

        foreach ($sheetData->sheetData->row as $row) {
            $current = [];
            foreach ($row->c as $cell) {
                $cellAttributes = $cell->attributes();
                if (!isset($cellAttributes['r'])) {
                    continue;
                }
                $columnIndex = self::columnIndexFromCell((string) $cellAttributes['r']);
                $value = self::extractCellValue($cell, $sharedStrings);
                $current[$columnIndex] = $value;
            }
            if ($current !== []) {
                $maxIndex = max(array_keys($current));
                $ordered = [];
                for ($i = 0; $i <= $maxIndex; $i++) {
                    $ordered[$i] = isset($current[$i]) ? trim($current[$i]) : '';
                }
                $rows[] = $ordered;
            }
        }

        $zip->close();
        return $rows;
    }

    private static function locateWorksheetXml(ZipArchive $zip): ?string
    {
        $primary = 'xl/worksheets/sheet1.xml';
        $xml = $zip->getFromName($primary);
        if (is_string($xml)) {
            return $xml;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name'])) {
                continue;
            }
            if (str_starts_with($stat['name'], 'xl/worksheets/sheet')) {
                $content = $zip->getFromIndex($i);
                if (is_string($content)) {
                    return $content;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function loadSharedStrings(ZipArchive $zip): array
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($sharedXml)) {
            return [];
        }

        $shared = new SimpleXMLElement($sharedXml);
        $strings = [];
        foreach ($shared->si as $si) {
            $text = '';
            if (isset($si->t)) {
                $text .= (string) $si->t;
            }
            if (isset($si->r)) {
                foreach ($si->r as $run) {
                    if (isset($run->t)) {
                        $text .= (string) $run->t;
                    }
                }
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * @param array<int, string> $sharedStrings
     */
    private static function extractCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        $value = '';
        if ($type === 's' && isset($cell->v)) {
            $index = (int) $cell->v;
            $value = $sharedStrings[$index] ?? '';
        } elseif (isset($cell->v)) {
            $value = (string) $cell->v;
        } elseif (isset($cell->is) && isset($cell->is->t)) {
            $value = (string) $cell->is->t;
        }

        return $value;
    }

    private static function columnIndexFromCell(string $cellRef): int
    {
        $letters = preg_replace('/\d+/u', '', $cellRef);
        $letters = strtoupper($letters ?? '');
        $index = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return max($index - 1, 0);
    }

    private static function removeUtf8Bom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }
        return $value;
    }
}
