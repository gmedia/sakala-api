<?php

declare(strict_types=1);

namespace App\Support\Database;

/** Database sizes for people: MB with one decimal, the unit the storage quota uses. */
final class DatabaseSize
{
    public const BYTES_PER_MEGABYTE = 1024 * 1024;

    public static function megabytes(int $bytes): string
    {
        return number_format($bytes / self::BYTES_PER_MEGABYTE, 1, ',', '.').' MB';
    }

    public static function bytesOf(int $megabytes): int
    {
        return $megabytes * self::BYTES_PER_MEGABYTE;
    }
}
