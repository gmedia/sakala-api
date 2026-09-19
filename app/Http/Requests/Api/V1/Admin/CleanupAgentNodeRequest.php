<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AgentNodeCleanupData;
use App\Enums\RuntimeCleanupTarget;
use App\Http\Requests\Concerns\HandlesIdempotencyKeyHeader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CleanupAgentNodeRequest extends FormRequest
{
    use HandlesIdempotencyKeyHeader;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('agent')) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
            'targets' => ['required', 'array', 'min:1'],
            'targets.*' => ['required', 'string', 'distinct', Rule::enum(RuntimeCleanupTarget::class)],
            // The approval gate is decided by the control plane, never by the client.
            'approved' => ['prohibited'],
        ];
    }

    public function toData(): AgentNodeCleanupData
    {
        /** @var list<string> $targets */
        $targets = $this->validated('targets');

        return new AgentNodeCleanupData(
            reason: (string) $this->validated('reason'),
            targets: array_map(
                static fn (string $target): RuntimeCleanupTarget => RuntimeCleanupTarget::from($target),
                $targets,
            ),
            idempotencyKey: $this->getIdempotencyKey(),
        );
    }
}
