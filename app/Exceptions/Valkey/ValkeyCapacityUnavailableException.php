<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

/**
 * Valkey nodes exist, but the one the store must go to has no room left in
 * its memory budget. The response does not say how much room there is.
 */
final class ValkeyCapacityUnavailableException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_CAPACITY_UNAVAILABLE';

    public const STATUS = 409;

    public const DESCRIPTION = 'No Valkey node that could take the store has room for its size in its memory budget right now. Try a smaller size or later.';

    public function __construct()
    {
        parent::__construct('Server Valkey sedang tidak punya ruang memori untuk ukuran ini. Coba ukuran yang lebih kecil, atau coba lagi nanti.');
    }
}
