<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Data\Admin\TimeRangeData;
use App\Enums\AdminTimeRange;
use Closure;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `range` plus `from` and `to` (WIB dates, `Y-m-d`) for `range=custom`.
 *
 * @mixin FormRequest
 */
trait ResolvesAdminTimeRange
{
    /** A custom range covers at most this many days. */
    public const MAX_CUSTOM_RANGE_DAYS = 366;

    /**
     * @param  list<string>  $ranges
     * @return array<string, array<int, mixed>>
     */
    protected function timeRangeRules(array $ranges): array
    {
        return [
            'range' => ['sometimes', 'string', Rule::in($ranges)],
            'from' => ['required_if:range,custom', 'prohibited_unless:range,custom', 'date_format:Y-m-d'],
            'to' => [
                'required_if:range,custom',
                'prohibited_unless:range,custom',
                'date_format:Y-m-d',
                'after_or_equal:from',
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Plain DateTime: it answers false for a malformed date, which
                    // date_format reports, where Carbon would throw.
                    $from = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->input('from'));
                    $to = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);

                    if ($from !== false && $to !== false && $from->diff($to)->days + 1 > self::MAX_CUSTOM_RANGE_DAYS) {
                        $fail('Rentang waktu paling panjang '.self::MAX_CUSTOM_RANGE_DAYS.' hari.');
                    }
                },
            ],
        ];
    }

    protected function timeRange(AdminTimeRange $default): TimeRangeData
    {
        $range = AdminTimeRange::tryFrom($this->string('range')->toString()) ?? $default;

        return TimeRangeData::resolve(
            $range,
            $this->filled('from') ? $this->string('from')->toString() : null,
            $this->filled('to') ? $this->string('to')->toString() : null,
        );
    }
}
