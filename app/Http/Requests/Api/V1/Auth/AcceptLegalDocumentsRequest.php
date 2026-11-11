<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Concerns\ValidatesLegalAcceptance;
use Illuminate\Foundation\Http\FormRequest;

final class AcceptLegalDocumentsRequest extends FormRequest
{
    use ValidatesLegalAcceptance;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->legalAcceptanceRules();
    }
}
