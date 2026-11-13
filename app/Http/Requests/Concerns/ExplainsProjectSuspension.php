<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\ProjectStatus;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A member learns why a suspended project refuses the request; anyone else
 * keeps the generic 403 and learns nothing about the project.
 *
 * @mixin FormRequest
 */
trait ExplainsProjectSuspension
{
    protected function failedAuthorization(): void
    {
        $project = $this->route('project');

        if (
            $project instanceof Project
            && $project->status === ProjectStatus::Suspended
            && $this->user()->can('view', $project)
        ) {
            throw new ProjectSuspendedException;
        }

        parent::failedAuthorization();
    }
}
