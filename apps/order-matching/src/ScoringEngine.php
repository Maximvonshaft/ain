<?php

namespace Apps\OrderMatching;

final class ScoringEngine
{
    private const BASE_WEIGHT = 0.7;
    private const TOKEN_WEIGHT = 0.3;
    private const ALIAS_REWARD = 0.05;

    public function __construct(private readonly bool $allowPartial)
    {
    }

    /**
     * @param list<MappingEntry> $candidates
     * @return array{best:?Score,top:list<Score>}
     */
    public function evaluate(
        string $normalizedCity,
        string $normalizedProvince,
        array $cityTokens,
        array $provinceTokens,
        array $candidates
    ): array {
        $best = null;
        $topScores = [];

        foreach ($candidates as $entry) {
            [$cityScore, $cityVariant] = $this->scoreVariants($normalizedCity, $cityTokens, $entry->cityVariants());
            [$provinceScore, $provinceVariant] = $this->scoreVariants($normalizedProvince, $provinceTokens, $entry->provinceVariants());

            $max = max($cityScore, $provinceScore);
            $source = $cityScore >= $provinceScore ? 'city' : 'province';

            if (!$this->allowPartial) {
                if ($cityScore === 0.0 || $provinceScore === 0.0) {
                    $max = min($cityScore, $provinceScore);
                    $source = 'both-required';
                }
            }

            $score = new Score($cityScore, $provinceScore, $max, $source, $entry, $cityVariant, $provinceVariant);
            $topScores[] = $score;

            if ($best === null || $score->max > $best->max) {
                $best = $score;
            }
        }

        usort($topScores, static fn (Score $a, Score $b): int => $b->max <=> $a->max);

        return [
            'best' => $best,
            'top' => array_slice($topScores, 0, 10),
        ];
    }

    /**
     * @param list<Variant> $variants
     * @param list<string> $orderTokens
     * @return array{0:float,1:?Variant}
     */
    private function scoreVariants(string $normalizedOrderValue, array $orderTokens, array $variants): array
    {
        $bestScore = 0.0;
        $bestVariant = null;

        foreach ($variants as $variant) {
            $score = $this->scorePair($normalizedOrderValue, $orderTokens, $variant);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestVariant = $variant;
            }
        }

        return [$bestScore, $bestVariant];
    }

    private function scorePair(string $normalizedOrderValue, array $orderTokens, Variant $variant): float
    {
        if ($normalizedOrderValue === '' || $variant->normalized === '') {
            return 0.0;
        }

        $base = $this->baseSimilarity($normalizedOrderValue, $variant->normalized);
        $token = $this->tokenSimilarity($orderTokens, $variant->tokens);
        $score = self::BASE_WEIGHT * $base + self::TOKEN_WEIGHT * $token;

        if ($variant->isAlias) {
            $score = min(1.0, $score + self::ALIAS_REWARD);
        }

        return $score;
    }

    private function baseSimilarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);
        return max(0.0, min(1.0, $percent / 100));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function tokenSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $setA = array_fill_keys($a, true);
        $setB = array_fill_keys($b, true);

        $intersection = count(array_intersect_key($setA, $setB));
        $union = count($setA + $setB);

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }
}
