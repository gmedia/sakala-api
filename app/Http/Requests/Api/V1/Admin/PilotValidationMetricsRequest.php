<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\PilotValidationMetricsRequestData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class PilotValidationMetricsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function toData(): PilotValidationMetricsRequestData
    {
        return new PilotValidationMetricsRequestData(
            from: $this->input('from') !== null
                ? CarbonImmutable::parse($this->input('from'))
                : null,
            to: $this->input('to') !== null
                ? CarbonImmutable::parse($this->input('to'))
                : null
        );
    }
}
