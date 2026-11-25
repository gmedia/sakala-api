<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

/**
 * Creating a store or making one bigger would push the memory the
 * workspace's stores reserve above `max_valkey_memory_mb`.
 */
final class ValkeyMemoryLimitExceededException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_MEMORY_LIMIT_EXCEEDED';

    public const STATUS = 422;

    public const CONTEXT = ['limit_mb' => 'integer', 'current_mb' => 'integer', 'requested_mb' => 'integer'];

    public const DESCRIPTION = 'The store size would push the memory reserved by the workspace\'s Valkey stores above its limit. `limit_mb` is the limit, `current_mb` what the other stores reserve (without this one), `requested_mb` the size asked for.';

    public function __construct(
        public readonly int $limitMb,
        public readonly int $currentMb,
        public readonly int $requestedMb,
    ) {
        parent::__construct($limitMb === 0
            ? 'Workspace ini belum punya jatah memori Valkey. Minta admin mengaktifkannya lewat profil batas workspace.'
            : sprintf(
                'Ukuran %d MB melebihi sisa jatah memori Valkey workspace ini (%d dari %d MB sudah dipakai store lain). Pilih ukuran yang lebih kecil, kecilkan atau hapus store lain, atau minta admin menaikkan jatahnya.',
                $requestedMb,
                $currentMb,
                $limitMb,
            ));
    }

    /** @return array{limit_mb: int, current_mb: int, requested_mb: int} */
    public function context(): array
    {
        return ['limit_mb' => $this->limitMb, 'current_mb' => $this->currentMb, 'requested_mb' => $this->requestedMb];
    }
}
