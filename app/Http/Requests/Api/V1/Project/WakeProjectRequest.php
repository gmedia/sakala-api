<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Http\Requests\Concerns\ExplainsProjectSuspension;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Waking takes the same rights as deploying.
 */
final class WakeProjectRequest extends FormRequest
{
    use ExplainsProjectSuspension;

    public function authorize(): bool
    {
        return $this->user()->can('deploy', $this->route('project'));
    }
}
