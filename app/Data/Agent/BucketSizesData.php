<?php

declare(strict_types=1);

namespace App\Data\Agent;

/** Bucket usage a storage node reported in one heartbeat (`metadata.buckets`, ADR-021). */
final readonly class BucketSizesData
{
    /**
     * @param  array<string, int>  $bytesByName  Bucket name on the node => usage in bytes. A repeated name keeps its last value.
     */
    public function __construct(
        public array $bytesByName,
    ) {}

    /**
     * @param  list<array{name: string, size_bytes: int}>  $items
     */
    public static function fromReport(array $items): self
    {
        $bytesByName = [];

        foreach ($items as $item) {
            $bytesByName[$item['name']] = (int) $item['size_bytes'];
        }

        return new self($bytesByName);
    }
}
