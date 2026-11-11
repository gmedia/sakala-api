<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Concerns\PaginatesAdminList;
use Illuminate\Foundation\Http\FormRequest;

/** An admin list with pagination and search only. */
final class AdminPageRequest extends FormRequest
{
    use PaginatesAdminList;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'search' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function pageNumber(): int
    {
        return $this->page();
    }

    public function pageSize(): int
    {
        return $this->perPage();
    }

    public function searchTerm(): ?string
    {
        return $this->search();
    }
}
