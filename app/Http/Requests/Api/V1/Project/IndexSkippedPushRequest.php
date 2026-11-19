<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\GetSkippedPushCollectionData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Reading skipped pushes takes the same rights as viewing the project.
 */
final class IndexSkippedPushRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('project'));
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            // Defaults to 20.
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
            // ISO 8601 date-time; only pushes skipped at or after it. The Console passes the oldest
            // deployment on its history page, so every push skipped after one of those comes back.
            'since' => ['sometimes', 'date'],
        ];
    }

    public function toData(): GetSkippedPushCollectionData
    {
        return new GetSkippedPushCollectionData(
            page: $this->integer('page', 1),
            perPage: $this->integer('per_page', self::DEFAULT_PER_PAGE),
            since: $this->filled('since') ? CarbonImmutable::parse($this->string('since')->toString()) : null,
        );
    }
}
