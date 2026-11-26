<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

/**
 * The store is locked: its `default` user is disabled. Attaching it would
 * hand a project a password that cannot log in.
 */
final class ValkeyLockedException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_LOCKED';

    public const STATUS = 409;

    public const DESCRIPTION = 'The store is locked, so it cannot be attached to a project.';

    public function __construct()
    {
        parent::__construct('Valkey ini dikunci admin Sakala, jadi belum bisa dipasang ke proyek. Hubungi admin untuk membukanya.');
    }
}
