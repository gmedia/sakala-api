<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

/** The storage node is still working on an earlier request for the bucket. */
final class BucketBusyException extends BucketRuleException
{
    public const CODE = 'BUCKET_BUSY';

    public const STATUS = 409;

    public const DESCRIPTION = 'The storage node is still working on an earlier request for this bucket (creating it, rotating its key, or changing its quota). Safe to retry once it finishes.';

    public static function rotationInProgress(): self
    {
        return new self('Penggantian secret key sebelumnya masih berjalan. Tunggu sampai selesai, lalu coba lagi.');
    }

    public static function quotaChangeInProgress(): self
    {
        return new self('Perubahan kuota sebelumnya masih berjalan. Tunggu sampai selesai, lalu coba lagi.');
    }

    public static function creationInProgress(): self
    {
        return new self('Server penyimpanan sedang membuat bucket ini. Tunggu sampai selesai, lalu coba lagi.');
    }
}
