<?php

namespace Apps\OrderMatching;

final class StatsCollector
{
    private int $total = 0;
    private int $eligible = 0;
    private int $high = 0;
    private int $mid = 0;
    private int $low = 0;
    private int $fail = 0;
    private int $missing = 0;
    private int $conflicts = 0;
    private array $failReasons = [];

    public function incrementTotal(): void
    {
        $this->total++;
    }

    public function incrementEligible(): void
    {
        $this->eligible++;
    }

    public function incrementMissing(): void
    {
        $this->missing++;
    }

    public function recordConfidence(string $confidence): void
    {
        $confidence = ucfirst(strtolower($confidence));
        match ($confidence) {
            'High' => $this->high++,
            'Mid' => $this->mid++,
            'Low' => $this->low++,
            default => $this->fail++,
        };
    }

    public function recordConflict(): void
    {
        $this->conflicts++;
    }

    public function recordFailReason(string $reason): void
    {
        $reason = $reason === '' ? 'unknown' : $reason;
        $this->failReasons[$reason] = ($this->failReasons[$reason] ?? 0) + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $failReasons = $this->failReasons;
        arsort($failReasons);

        return [
            'total_rows' => $this->total,
            'eligible_rows' => $this->eligible,
            'missing_address_rows' => $this->missing,
            'high' => $this->high,
            'mid' => $this->mid,
            'low' => $this->low,
            'fail' => $this->fail,
            'conflicts' => $this->conflicts,
            'fail_reasons_top' => array_slice($failReasons, 0, 5, true),
        ];
    }
}
