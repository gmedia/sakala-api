<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

use App\Models\Project;
use Illuminate\Support\Collection;

/** A database is deleted only once no project uses it, so no running application loses it by accident. */
final class DatabaseInUseException extends DatabaseRuleException
{
    public const CODE = 'DATABASE_IN_USE';

    public const STATUS = 409;

    public const CONTEXT = ['projects' => 'array'];

    public const DESCRIPTION = 'The database is still attached to the projects listed in `projects` (`id`, `name`, `slug`); detach it first.';

    /** @param  Collection<int, Project>  $projects */
    public function __construct(
        private readonly Collection $projects,
    ) {
        parent::__construct('Database ini masih dipasang ke proyek. Lepas dulu dari semua proyek, lalu hapus.');
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
