<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\ProjectControlData;
use Illuminate\Foundation\Http\FormRequest;

abstract class ProjectControlRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function getIdempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && ! empty($key) ? $key : null;
    }

    public function toData(): ProjectControlData
    {
        return new ProjectControlData(
            reason: (string) $this->validated('reason'),
            idempotencyKey: $this->getIdempotencyKey()
        );
    }
}
