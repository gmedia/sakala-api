<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

/** The Valkey node is still working on an earlier request for the store. */
final class ValkeyBusyException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_BUSY';

    public const STATUS = 409;

    public const DESCRIPTION = 'The Valkey node is still working on an earlier request for this store (creating it, rotating its password, or resizing it). Safe to retry once it finishes.';

    public static function rotationInProgress(): self
    {
        return new self('Penggantian password sebelumnya masih berjalan. Tunggu sampai selesai, lalu coba lagi.');
    }

    public static function resizeInProgress(): self
    {
        return new self('Perubahan ukuran sebelumnya masih berjalan. Tunggu sampai selesai, lalu coba lagi.');
    }

    public static function creationInProgress(): self
    {
        return new self('Server Valkey sedang membuat store ini. Tunggu sampai selesai, lalu coba lagi.');
    }
}
