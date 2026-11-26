<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

use App\Models\ManagedValkey;

/**
 * The store is in its password uncertain window (ValkeyPasswordWindow): a
 * rotation reached the node and nobody knows yet which password works, so
 * no deployment receives one and the owner cannot read it.
 */
final class ValkeyPasswordRotatingException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_PASSWORD_ROTATING';

    public const STATUS = 409;

    public const CONTEXT = ['valkey' => 'array'];

    public const DESCRIPTION = 'The password of the store (`valkey`: `id`, `name`) is being rotated and the node has not confirmed which password works, or the rotation ended `unknown`. Retry once `password_rotation.status` is `completed` or `failed`, or after a new rotation.';

    public function __construct(
        public readonly ManagedValkey $valkey,
    ) {
        parent::__construct(sprintf(
            'Password Valkey %s sedang diganti dan server Valkey belum memastikan password mana yang berlaku. Tunggu sampai penggantian selesai, lalu coba lagi.',
            $valkey->name,
        ));
    }

    /** @return array{valkey: array{id: string, name: string}} */
    public function context(): array
    {
        return ['valkey' => ['id' => $this->valkey->id, 'name' => $this->valkey->name]];
    }
}
