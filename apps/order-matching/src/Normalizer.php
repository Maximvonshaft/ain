<?php

namespace Apps\OrderMatching;

final class Normalizer
{
    private const ALIAS_MAP = [
        'tirane' => 'tirana',
        'tiranë' => 'tirana',
        'tirana' => 'tirana',
        'prishtina' => 'pristina',
        'prishtinë' => 'pristina',
        'pristina' => 'pristina',
        'korce' => 'korca',
        'korçë' => 'korca',
        'korca' => 'korca',
        'shkoder' => 'shkodra',
        'shkodër' => 'shkodra',
        'durres' => 'durres',
        'durrës' => 'durres',
        'vlore' => 'vlora',
        'vlorë' => 'vlora',
    ];

    private const NOISE_WORDS = [
        'city',
        'municipality',
        'prefecture',
        'region',
        'county',
        'province',
        'district',
        'of',
        'the',
        'municipaliteti',
        'qarku',
        'bashkia',
    ];

    /**
     * @param string $value
     */
    public function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = $this->toLower($value);
        $value = $this->stripDiacritics($value);
        $value = preg_replace('/[\[\]\(\)\-_,.;:]/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = $this->replaceAliases($value);
        $value = $this->removeNoiseWords($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return string[]
     */
    public function tokens(string $value): array
    {
        return $this->tokensFromNormalized($this->normalize($value));
    }

    /**
     * @return string[]
     */
    public function tokensFromNormalized(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $parts = array_filter($parts, static fn (string $token): bool => $token !== '');

        return array_values(array_unique($parts));
    }

    private function toLower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private function stripDiacritics(string $value): string
    {
        if (class_exists('Transliterator')) {
            $transliterator = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($transliterator instanceof \Transliterator) {
                $value = $transliterator->transliterate($value);
            }
        }

        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted)) {
            $value = $converted;
        }

        $replacements = [
            'ë' => 'e',
            'é' => 'e',
            'è' => 'e',
            'ç' => 'c',
            'š' => 's',
            'ž' => 'z',
        ];

        return strtr($value, $replacements);
    }

    private function replaceAliases(string $value): string
    {
        $parts = preg_split('/\s+/u', $value) ?: [];
        $normalized = [];
        foreach ($parts as $part) {
            $normalized[] = self::ALIAS_MAP[$part] ?? $part;
        }

        return implode(' ', $normalized);
    }

    private function removeNoiseWords(string $value): string
    {
        $parts = preg_split('/\s+/u', $value) ?: [];
        $filtered = array_filter(
            $parts,
            static fn (string $part): bool => !in_array($part, self::NOISE_WORDS, true)
        );

        return implode(' ', $filtered);
    }
}
