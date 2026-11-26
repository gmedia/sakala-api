<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

use App\Models\Project;
use Illuminate\Support\Collection;

/** A store is deleted only once no project uses it, so no running application loses its data by accident. */
final class ValkeyInUseException extends ValkeyRuleException
{
    public const CODE = 'VALKEY_IN_USE';

    public const STATUS = 409;

    public const CONTEXT = ['projects' => 'array'];

    public const DESCRIPTION = 'The store is still attached to the projects listed in `projects` (`id`, `name`, `slug`); detach it first.';

    /** @param  Collection<int, Project>  $projects */
    public function __construct(
        private readonly Collection $projects,
    ) {
        parent::__construct('Valkey ini masih dipasang ke proyek. Lepas dulu dari semua proyek, lalu hapus.');
    }

    /** @return array{projects: list<array{id: string, name: string, slug: string}>} */
    public function context(): array
    {
        return [
            'projects' => array_values($this->projects
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug])
                ->all()),
        ];
    }
}
