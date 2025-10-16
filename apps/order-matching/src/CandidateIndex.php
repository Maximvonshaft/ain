<?php

namespace Apps\OrderMatching;

final class CandidateIndex
{
    /** @var array<string, list<MappingEntry>> */
    private array $cityBuckets = [];

    /** @var array<string, list<MappingEntry>> */
    private array $provinceBuckets = [];

    /** @var list<MappingEntry> */
    private array $allEntries = [];

    public function add(MappingEntry $entry): void
    {
        $this->allEntries[] = $entry;

        foreach ($entry->cityBuckets() as $bucket) {
            $this->cityBuckets[$bucket][] = $entry;
        }

        foreach ($entry->provinceBuckets() as $bucket) {
            $this->provinceBuckets[$bucket][] = $entry;
        }
    }

    /**
     * @return list<MappingEntry>
     */
    public function candidates(string $cityNormalized, string $provinceNormalized): array
    {
        $candidates = [];
        $keys = [];

        $cityBucket = $this->bucketKey($cityNormalized);
        if ($cityBucket !== '') {
            $keys[] = ['city', $cityBucket];
        }

        $provinceBucket = $this->bucketKey($provinceNormalized);
        if ($provinceBucket !== '') {
            $keys[] = ['province', $provinceBucket];
        }

        $seen = [];
        foreach ($keys as [$type, $key]) {
            $bucket = $type === 'city' ? ($this->cityBuckets[$key] ?? []) : ($this->provinceBuckets[$key] ?? []);
            foreach ($bucket as $entry) {
                $hash = spl_object_hash($entry);
                if (!isset($seen[$hash])) {
                    $candidates[] = $entry;
                    $seen[$hash] = true;
                }
            }
        }

        if ($candidates === []) {
            return $this->allEntries;
        }

        return $candidates;
    }

    private function bucketKey(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        return substr($value, 0, min(2, strlen($value)));
    }
}
