<?php

declare(strict_types=1);

namespace OrderMatcher\Matching;

final class Similarity
{
    /**
     * @param array{normalized:string,tokens:array<int,string>,alias_applied:bool} $left
     * @param array{normalized:string,tokens:array<int,string>,alias_applied:bool} $right
     */
    public static function combined(array $left, array $right, float $baseWeight, float $tokenWeight, float $aliasBonus): float
    {
        $base = self::base($left['normalized'], $right['normalized']);
        $token = self::token($left['tokens'], $right['tokens']);
        $score = $base * $baseWeight + $token * $tokenWeight;
        if ($aliasBonus > 0 && ($left['alias_applied'] || $right['alias_applied'])) {
            $score = min(1.0, $score + $aliasBonus);
        }

        return $score;
    }

    public static function base(string $left, string $right): float
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return 0.0;
        }

        $percent = 0.0;
        similar_text($left, $right, $percent);
        $similarScore = $percent / 100;

        $maxLength = max(strlen($left), strlen($right));
        $levScore = 0.0;
        if ($maxLength > 0) {
            $distance = levenshtein($left, $right);
            if ($distance >= 0) {
                $levScore = 1.0 - ($distance / $maxLength);
            }
        }

        return max($similarScore, $levScore);
    }

    /**
     * @param array<int, string> $leftTokens
     * @param array<int, string> $rightTokens
     */
    public static function token(array $leftTokens, array $rightTokens): float
    {
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $leftSet = array_values(array_unique($leftTokens));
        $rightSet = array_values(array_unique($rightTokens));

        $intersection = array_intersect($leftSet, $rightSet);
        $union = array_unique(array_merge($leftSet, $rightSet));

        if (count($union) === 0) {
            return 0.0;
        }

        return count($intersection) / count($union);
    }
}
