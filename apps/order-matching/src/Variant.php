<?php

namespace Apps\OrderMatching;

final class Variant
{
    /**
     * @param list<string> $tokens
     */
    public function __construct(
        public readonly string $original,
        public readonly string $normalized,
        public readonly array $tokens,
        public readonly bool $isAlias
    ) {
    }
}
