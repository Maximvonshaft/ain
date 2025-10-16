<?php

namespace Apps\OrderMatching;

final class ConfidenceClassifier
{
    public function __construct(
        private readonly float $high,
        private readonly float $mid,
        private readonly float $low
    ) {
    }

    public function classify(float $score): string
    {
        if ($score >= $this->high) {
            return 'High';
        }
        if ($score >= $this->mid) {
            return 'Mid';
        }
        if ($score >= $this->low) {
            return 'Low';
        }

        return 'Fail';
    }
}
