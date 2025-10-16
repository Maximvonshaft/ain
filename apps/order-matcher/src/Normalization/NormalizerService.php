<?php

declare(strict_types=1);

namespace OrderMatcher\Normalization;

use Normalizer;

final class NormalizerService
{
    private array $aliasMap;
    private array $noisePatterns;
    private string $preserveCharacters;

    public function __construct(array $aliasMap, array $noiseWords, string $preserveCharacters)
    {
        $this->noisePatterns = $this->prepareNoisePatterns($noiseWords);
        $this->preserveCharacters = $preserveCharacters;
        $this->aliasMap = $this->prepareAliasMap($aliasMap);
    }

    public function normalize(string $value): array
    {
        $original = $value;
        $base = $this->normalizeBase($value);
        $aliasApplied = false;
        if ($base !== '' && isset($this->aliasMap[$base])) {
            $base = $this->aliasMap[$base];
            $aliasApplied = true;
        }

        $base = $this->collapseSpaces($base);
        $tokens = $base === '' ? [] : array_values(array_unique(preg_split('/\s+/u', $base, -1, PREG_SPLIT_NO_EMPTY)));

        return [
            'original' => $original,
            'normalized' => $base,
            'tokens' => $tokens,
            'alias_applied' => $aliasApplied,
        ];
    }

    public function normalizeWithoutAlias(string $value): string
    {
        return $this->collapseSpaces($this->normalizeBase($value));
    }

    private function prepareAliasMap(array $aliasMap): array
    {
        $prepared = [];
        foreach ($aliasMap as $alias => $canonical) {
            $normalizedAlias = $this->normalizeWithoutAlias((string) $alias);
            $normalizedCanonical = $this->normalizeWithoutAlias((string) $canonical);
            if ($normalizedAlias === '') {
                continue;
            }

            if ($normalizedCanonical === '') {
                $normalizedCanonical = $normalizedAlias;
            }

            $prepared[$normalizedAlias] = $normalizedCanonical;
        }

        return $prepared;
    }

    private function prepareNoisePatterns(array $noiseWords): array
    {
        $patterns = [];
        foreach ($noiseWords as $word) {
            $word = trim(mb_strtolower((string) $word, 'UTF-8'));
            if ($word === '') {
                continue;
            }

            $patterns[] = '/\b' . preg_quote($word, '/') . '\b/u';
        }

        return $patterns;
    }

    private function normalizeBase(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = $this->stripAccents($value);

        foreach ($this->noisePatterns as $pattern) {
            $value = preg_replace($pattern, ' ', $value) ?? $value;
        }

        $preserve = preg_quote($this->preserveCharacters, '/');
        $value = preg_replace('/[^\p{L}\p{N}' . $preserve . ']+/u', ' ', $value) ?? $value;

        return $value;
    }

    private function collapseSpaces(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function stripAccents(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_D);
            if (is_string($normalized)) {
                $value = $normalized;
            }
            $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
        }

        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }

        return $value;
    }
}
