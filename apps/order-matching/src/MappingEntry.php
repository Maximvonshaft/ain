<?php

namespace Apps\OrderMatching;

final class MappingEntry
{
    /** @var list<Variant> */
    private array $cityVariants;

    /** @var list<Variant> */
    private array $provinceVariants;

    public function __construct(
        private readonly int $rowNumber,
        private readonly string $city,
        private readonly string $province,
        array $cityAliases,
        array $provinceAliases,
        Normalizer $normalizer
    ) {
        $this->cityVariants = $this->buildVariants($city, $cityAliases, $normalizer);
        $this->provinceVariants = $this->buildVariants($province, $provinceAliases, $normalizer);
    }

    public function rowNumber(): int
    {
        return $this->rowNumber;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function province(): string
    {
        return $this->province;
    }

    /**
     * @return list<Variant>
     */
    public function cityVariants(): array
    {
        return $this->cityVariants;
    }

    /**
     * @return list<Variant>
     */
    public function provinceVariants(): array
    {
        return $this->provinceVariants;
    }

    /**
     * @return list<string>
     */
    public function cityBuckets(): array
    {
        return $this->variantBuckets($this->cityVariants);
    }

    /**
     * @return list<string>
     */
    public function provinceBuckets(): array
    {
        return $this->variantBuckets($this->provinceVariants);
    }

    /**
     * @param list<Variant> $variants
     * @return list<string>
     */
    private function variantBuckets(array $variants): array
    {
        $buckets = [];
        foreach ($variants as $variant) {
            $key = self::bucketKey($variant->normalized);
            if ($key !== '') {
                $buckets[$key] = true;
            }
        }

        return array_keys($buckets);
    }

    private static function bucketKey(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return substr($value, 0, min(2, strlen($value)));
    }

    /**
     * @param list<string> $aliases
     * @return list<Variant>
     */
    private function buildVariants(string $primary, array $aliases, Normalizer $normalizer): array
    {
        $variants = [];
        $seen = [];

        $primaryNormalized = $normalizer->normalize($primary);
        $variants[] = new Variant($primary, $primaryNormalized, $normalizer->tokensFromNormalized($primaryNormalized), false);
        $seen[$variants[0]->normalized] = true;

        foreach ($aliases as $alias) {
            $normalized = $normalizer->normalize($alias);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $variants[] = new Variant($alias, $normalized, $normalizer->tokensFromNormalized($normalized), true);
            $seen[$normalized] = true;
        }

        return $variants;
    }
}
