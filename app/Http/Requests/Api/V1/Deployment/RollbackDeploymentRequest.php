<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Deployment;

use App\Http\Requests\Concerns\ExplainsProjectSuspension;
use App\Http\Requests\Concerns\HandlesIdempotencyKeyHeader;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A rollback takes the same rights as deploying. No body; the optional
 * `Idempotency-Key` header makes a retry return the same deployment.
 */
final class RollbackDeploymentRequest extends FormRequest
{
    use ExplainsProjectSuspension;
    use HandlesIdempotencyKeyHeader;

    public function authorize(): bool
    {
        return $this->user()->can('deploy', $this->route('project'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
