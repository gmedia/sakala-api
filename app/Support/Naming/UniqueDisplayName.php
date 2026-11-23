<?php

declare(strict_types=1);

namespace App\Support\Naming;

/**
 * A display name made unique among names already taken: the base, then the
 * base followed by ` 2`, ` 3`, ..., cut so the whole fits. What a managed
 * database or bucket created for a project is called when nobody named it.
 */
final class UniqueDisplayName
{
    /** Separates the base from the number that makes it unique. */
    private const SUFFIX_SEPARATOR = ' ';

    /** The first number appended when the base is taken. */
    private const FIRST_SUFFIX = 2;

    /** @param  list<string>  $taken */
    public static function from(string $base, array $taken, int $maxLength): string
    {
        /** @var array<string, true> $lookup */
        $lookup = array_fill_keys($taken, true);
        $base = trim(mb_substr(trim($base), 0, $maxLength));
        $name = $base;

        for ($number = self::FIRST_SUFFIX; isset($lookup[$name]); $number++) {
            $suffix = self::SUFFIX_SEPARATOR.$number;
            $name = rtrim(mb_substr($base, 0, $maxLength - mb_strlen($suffix))).$suffix;
        }

        return $name;
    }
}
