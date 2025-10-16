<?php

declare(strict_types=1);

namespace OrderMatcher\Support;

final class ColumnHelper
{
    public static function indexFromLetter(string $letter): int
    {
        $letter = strtoupper(trim($letter));
        if ($letter === '') {
            return 0;
        }

        $index = 0;
        $length = strlen($letter);
        for ($i = 0; $i < $length; $i++) {
            $char = $letter[$i];
            if ($char < 'A' || $char > 'Z') {
                continue;
            }
            $index = $index * 26 + (ord($char) - 64);
        }

        return max($index - 1, 0);
    }
}
