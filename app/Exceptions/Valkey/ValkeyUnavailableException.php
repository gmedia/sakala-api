<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

/**
 * No node can ever host this store: none in the region advertises
 * `managed-valkey`, or the project the store is for runs on a node that does
 * not.
 */
final class ValkeyUnavailableException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_UNAVAILABLE';

    public const STATUS = 409;

    public const DESCRIPTION = 'No node that may host the store runs Valkey: none in the region, or the node the project (`project_id`) runs on has no Valkey role.';

    public function __construct()
    {
        parent::__construct('Server yang bisa menjalankan Valkey untuk permintaan ini belum tersedia. Coba lagi nanti, atau hubungi operator Sakala.');
    }
}
