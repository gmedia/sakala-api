<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

/** The database node is still working on an earlier request for the database. */
final class DatabaseBusyException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_BUSY';

    public const STATUS = 409;

    public const DESCRIPTION = 'The database node is still working on an earlier request for this database (creating it, or rotating its password). Safe to retry once it finishes.';

    public static function rotationInProgress(): self
    {
        return new self('Penggantian password sebelumnya masih berjalan. Tunggu sampai selesai, lalu coba lagi.');
    }

    public static function creationInProgress(): self
    {
        return new self('Server database sedang membuat database ini. Tunggu sampai selesai, lalu hapus.');
    }
}
