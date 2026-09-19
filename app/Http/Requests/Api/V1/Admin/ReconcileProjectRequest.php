<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\ReconcileProjectData;
use App\Enums\DesiredWorkloadState;
use App\Enums\ReconcileWorkloadAction;
use App\Http\Requests\Concerns\HandlesIdempotencyKeyHeader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReconcileProjectRequest extends FormRequest
{
    use HandlesIdempotencyKeyHeader;

    public function authorize(): bool
    {
        return $this->user()?->can('reconcile', $this->route('project')) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'desired_state' => ['required', 'string', Rule::enum(DesiredWorkloadState::class)],
            // Empty means "report drift only"; mutations must be listed explicitly.
            'actions' => ['present', 'array'],
            'actions.*' => ['required', 'string', 'distinct', Rule::enum(ReconcileWorkloadAction::class)],
        ];
    }

    public function toData(): ReconcileProjectData
    {
        /** @var list<string> $actions */
        $actions = $this->validated('actions');

        return new ReconcileProjectData(
            reason: (string) $this->validated('reason'),
            desiredState: DesiredWorkloadState::from((string) $this->validated('desired_state')),
            actions: array_map(
                static fn (string $action): ReconcileWorkloadAction => ReconcileWorkloadAction::from($action),
                $actions,
            ),
            idempotencyKey: $this->getIdempotencyKey(),
        );
    }
}
