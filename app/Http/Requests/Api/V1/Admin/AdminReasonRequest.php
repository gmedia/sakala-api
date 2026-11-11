<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Concerns\RequiresAdminReason;
use Illuminate\Foundation\Http\FormRequest;

/** An admin action whose only input is the reason. */
final class AdminReasonRequest extends FormRequest
{
    use RequiresAdminReason;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->reasonRules();
    }
}
