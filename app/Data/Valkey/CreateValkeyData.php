<?php

declare(strict_types=1);

namespace App\Data\Valkey;

use App\Enums\ValkeyMode;
use App\Models\Project;

/** What a request to create a managed Valkey store asks for (ADR-023). */
final readonly class CreateValkeyData
{
    public function __construct(
        /** Name people see; null only with a project, whose name is used. */
        public ?string $name,
        public ValkeyMode $mode,
        /** `maxmemory` in MB, one of ManagedValkey::SIZES_MB. */
        public int $maxmemoryMb,
        /** Sakala region asked for; null follows the project, then the workspace default. */
        public ?string $region,
        /** A project of the workspace to attach the new store to at once. */
        public ?Project $project,
    ) {}
}
