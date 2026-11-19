<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

use App\Enums\DatabaseStatus;

/**
 * The operation needs a database whose credentials work on the node (ready or
 * locked), and this one is still being created, failed, or is being dropped.
 */
final class DatabaseNotReadyException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_NOT_READY';

    public const STATUS = 409;

    public const CONTEXT = ['status' => 'string'];

    public const DESCRIPTION = 'The database is not ready or locked: it is being created (`provisioning`), failed, or is being deleted. `status` says which.';

    public function __construct(
        public readonly DatabaseStatus $status,
    ) {
        parent::__construct(match ($status) {
            DatabaseStatus::Provisioning => 'Database ini masih dibuat. Tunggu sampai siap, lalu coba lagi.',
            DatabaseStatus::Failed => 'Database ini gagal dibuat, jadi tidak bisa dipakai. Hapus lalu buat database baru.',
            default => 'Database ini sedang dihapus, jadi tidak bisa dipakai lagi.',
        });
    }

    /** @return array{status: string} */
    public function context(): array
    {
        return ['status' => $this->status->value];
    }
}
