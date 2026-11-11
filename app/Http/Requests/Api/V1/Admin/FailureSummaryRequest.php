<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Admin\TimeRangeData;
use App\Enums\AdminTimeRange;
use App\Http\Requests\Concerns\ResolvesAdminTimeRange;
use Illuminate\Foundation\Http\FormRequest;

final class FailureSummaryRequest extends FormRequest
{
    use ResolvesAdminTimeRange;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->timeRangeRules(array_column(AdminTimeRange::cases(), 'value'));
    }

    public function toData(): TimeRangeData
    {
        return $this->timeRange(AdminTimeRange::Last30Days);
    }
}
