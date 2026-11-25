<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

use App\Models\ManagedValkey;

/**
 * A project reaches its store only on the store's own node (the store's
 * network is local to it), and this project already runs, or must run, on
 * another node.
 */
final class ValkeyNodeMismatchException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_NODE_MISMATCH';

    public const STATUS = 409;

    public const CONTEXT = ['valkey' => 'array'];

    public const DESCRIPTION = 'The project runs, or must run, on another node than the Valkey store (`valkey`: `id`, `name`); a store is reachable only from projects on its own node.';

    public function __construct(
        public readonly ManagedValkey $valkey,
    ) {
        parent::__construct(sprintf(
            'Proyek ini berjalan di server lain, sedangkan Valkey %s hanya bisa dijangkau dari server tempat Valkey itu berada. Buat Valkey baru untuk proyek ini.',
            $valkey->name,
        ));
    }

    /** @return array{valkey: array{id: string, name: string}} */
    public function context(): array
    {
        return ['valkey' => ['id' => $this->valkey->id, 'name' => $this->valkey->name]];
    }
}
