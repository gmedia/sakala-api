<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use Illuminate\Foundation\Http\FormRequest;

final class ClaimAgentCommandRequest extends FormRequest
{
    /** @return array<string, array<string|string>> */
    public function rules(): array
    {
        return [];
    }
}
