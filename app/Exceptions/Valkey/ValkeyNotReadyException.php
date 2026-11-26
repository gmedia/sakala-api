<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

use App\Enums\ValkeyStatus;

/**
 * The operation needs a store that exists on its node (ready or locked), and
 * this one is still being created, failed, or is being deleted.
 */
final class ValkeyNotReadyException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_NOT_READY';

    public const STATUS = 409;

    public const CONTEXT = ['status' => 'string'];

    public const DESCRIPTION = 'The store is not ready or locked: it is being created (`provisioning`), failed, or is being deleted. `status` says which.';

    public function __construct(
        public readonly ValkeyStatus $status,
    ) {
        parent::__construct(match ($status) {
            ValkeyStatus::Provisioning => 'Valkey ini masih dibuat. Tunggu sampai siap, lalu coba lagi.',
            ValkeyStatus::Failed => 'Valkey ini gagal dibuat, jadi tidak bisa dipakai. Hapus lalu buat yang baru.',
            default => 'Valkey ini sedang dihapus, jadi tidak bisa dipakai lagi.',
        });
    }

    /** @return array{status: string} */
    public function context(): array
    {
        return ['status' => $this->status->value];
    }
}
