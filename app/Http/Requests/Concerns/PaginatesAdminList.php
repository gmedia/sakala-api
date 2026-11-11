<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `page` and `per_page` for admin lists, which are always paginated on the
 * server.
 *
 * @mixin FormRequest
 */
trait PaginatesAdminList
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** @return array<string, array<int, string>> */
    protected function paginationRules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.self::MAX_PER_PAGE],
        ];
    }

    protected function page(): int
    {
        return $this->integer('page', 1);
    }

    protected function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }

    protected function search(): ?string
    {
        $search = trim($this->string('search')->toString());

        return $search === '' ? null : $search;
    }
}
