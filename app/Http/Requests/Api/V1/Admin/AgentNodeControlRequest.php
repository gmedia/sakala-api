<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\AgentNodeControlData;
use App\Http\Requests\Concerns\HandlesIdempotencyKeyHeader;
use Illuminate\Foundation\Http\FormRequest;

abstract class AgentNodeControlRequest extends FormRequest
{
    use HandlesIdempotencyKeyHeader;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('agent')) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function toData(): AgentNodeControlData
    {
        return new AgentNodeControlData(
            reason: (string) $this->validated('reason'),
            idempotencyKey: $this->getIdempotencyKey(),
        );
    }
}
