<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\UsageSignalsRequestData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

final class UsageSignalsRequest extends FormRequest
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

    public function toData(): UsageSignalsRequestData
    {
        $from = $this->input('from') !== null
            ? CarbonImmutable::parse($this->input('from'))->utc()
            : null;

        $to = $this->input('to') !== null
            ? CarbonImmutable::parse($this->input('to'))->utc()
            : null;

        $now = now()->toImmutable()->utc();

        $effectiveFrom = $from ?? $now->startOfMonth();
        $effectiveTo = $to ?? $now;

        if ($effectiveFrom->greaterThan($effectiveTo)) {
            throw ValidationException::withMessages([
                'from' => 'The from date must be before or equal to the effective to date.',
            ]);
        }

        return new UsageSignalsRequestData(
            from: $from,
            to: $to,
        );
    }
}
