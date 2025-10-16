<?php

namespace Apps\OrderMatching;

final class Score
{
    public function __construct(
        public readonly float $city,
        public readonly float $province,
        public readonly float $max,
        public readonly string $source,
        public readonly MappingEntry $entry,
        public readonly ?Variant $cityVariant,
        public readonly ?Variant $provinceVariant
    ) {
    }
}
