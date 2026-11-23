<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

use App\Models\Project;
use Illuminate\Support\Collection;

/** A bucket is deleted only once no project uses it, so no running application loses its files by accident. */
final class BucketInUseException extends BucketRuleException
{
    public const CODE = 'BUCKET_IN_USE';

    public const STATUS = 409;

    public const CONTEXT = ['projects' => 'array'];

    public const DESCRIPTION = 'The bucket is still attached to the projects listed in `projects` (`id`, `name`, `slug`); detach it first.';

    /** @param  Collection<int, Project>  $projects */
    public function __construct(
        private readonly Collection $projects,
    ) {
        parent::__construct('Bucket ini masih dipasang ke proyek. Lepas dulu dari semua proyek, lalu hapus.');
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
