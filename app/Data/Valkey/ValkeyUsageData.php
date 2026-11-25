<?php

declare(strict_types=1);

namespace App\Data\Valkey;

/** One store's reading from a heartbeat (`metadata.valkeys[]`, ADR-023). */
final readonly class ValkeyUsageData
{
    public function __construct(
        public string $valkeyId,
        public string $status,
        public int $restartCount,
        public ?int $usedMemory,
        public ?int $evictedKeys,
        public ?int $errorstatOom,
        public ?int $connectedClients,
        public ?string $aofLastWriteStatus,
    ) {}

    /**
     * @param  list<array{valkey_id: string, status: string, restart_count: int, used_memory: int|null, evicted_keys: int|null, errorstat_oom: int|null, connected_clients: int|null, aof_last_write_status: string|null}>  $items
     * @return array<string, self> Keyed by lowercase store ID; a repeated ID keeps its last reading.
     */
    public static function fromReport(array $items): array
    {
        $byId = [];

        foreach ($items as $item) {
            $id = strtolower($item['valkey_id']);
            $byId[$id] = new self(
                valkeyId: $id,
                status: $item['status'],
                restartCount: (int) $item['restart_count'],
                usedMemory: $item['used_memory'],
                evictedKeys: $item['evicted_keys'],
                errorstatOom: $item['errorstat_oom'],
                connectedClients: $item['connected_clients'],
                aofLastWriteStatus: $item['aof_last_write_status'],
            );
        }

        return $byId;
    }
}
