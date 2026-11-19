<?php

declare(strict_types=1);

namespace App\Data\Agent;

/**
 * `pg_database_size()` of each database a database node hosts, from heartbeat
 * `metadata.databases`. A database missing from the list keeps its last size.
 */
final readonly class DatabaseSizesData
{
    /**
     * @param  array<string, int>  $bytesByName  Database name on the node => size in bytes. A repeated name keeps its last size.
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
