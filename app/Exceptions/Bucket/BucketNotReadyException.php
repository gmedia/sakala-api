<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

use App\Enums\BucketStatus;

/**
 * The operation needs a bucket whose key exists on the node (ready or
 * locked), and this one is still being created, failed, or is being deleted.
 */
final class BucketNotReadyException extends BucketRuleException
{
    public const CODE = 'BUCKET_NOT_READY';

    public const STATUS = 409;

    public const CONTEXT = ['status' => 'string'];

    public const DESCRIPTION = 'The bucket is not ready or locked: it is being created (`provisioning`), failed, or is being deleted. `status` says which.';

    public function __construct(
        public readonly BucketStatus $status,
    ) {
        parent::__construct(match ($status) {
            BucketStatus::Provisioning => 'Bucket ini masih dibuat. Tunggu sampai siap, lalu coba lagi.',
            BucketStatus::Failed => 'Bucket ini gagal dibuat, jadi tidak bisa dipakai. Hapus lalu buat bucket baru.',
            default => 'Bucket ini sedang dihapus, jadi tidak bisa dipakai lagi.',
        });
    }

    /** @return array{status: string} */
    public function context(): array
    {
        return ['status' => $this->status->value];
    }
}
