<?php

namespace Apps\OrderMatching;

use SplFileObject;

final class CsvReader
{
    /**
     * @return array<int, array<string, string|null>>
     */
    public function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('CSV file not found: %s', $path));
        }

        $file = new SplFileObject($path, 'r');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $rows = [];
        $headers = null;

        foreach ($file as $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->prepareHeaders($row);
                continue;
            }

            $rows[] = $this->associateRow($headers, $row);
        }

        return $rows;
    }

    /**
     * @param array<int, string|null> $row
     * @return array<int, string>
     */
    private function prepareHeaders(array $row): array
    {
        $headers = [];
        foreach ($row as $value) {
            $value = $value ?? '';
            $value = trim($value, "\xEF\xBB\xBF\x00\x20\t\n\r");
            $headers[] = $value;
        }

        return $headers;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string|null> $row
     * @return array<string, string|null>
     */
    private function associateRow(array $headers, array $row): array
    {
        $item = [];
        $count = max(count($headers), count($row));
        for ($i = 0; $i < $count; $i++) {
            $header = $headers[$i] ?? ('__col' . $i);
            $item[$header] = array_key_exists($i, $row) ? ($row[$i] !== null ? trim((string) $row[$i]) : null) : null;
        }

        return $item;
    }
}
