<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

use App\Models\ManagedValkey;

/**
 * A project uses one Valkey store at a time: the variables have fixed names,
 * so a second store would have nowhere to go.
 */
final class ProjectValkeyAlreadyAttachedException extends ValkeyRuleException
{
    public const CODE = 'PROJECT_VALKEY_ALREADY_ATTACHED';

    public const STATUS = 409;

    public const CONTEXT = ['valkey' => 'array'];

    public const DESCRIPTION = 'The project already uses another Valkey store, named in `valkey` (`id`, `name`). A project uses one store at a time.';

    public function __construct(
        private readonly ManagedValkey $attached,
    ) {
        parent::__construct(sprintf(
            'Proyek ini sudah memakai Valkey %s. Lepas dulu Valkey itu dari proyek, lalu pasang yang ini.',
            $attached->name,
        ));
    }

    /** @return array{valkey: array{id: string, name: string}} */
    public function context(): array
    {
        return ['valkey' => ['id' => $this->attached->id, 'name' => $this->attached->name]];
    }
}
