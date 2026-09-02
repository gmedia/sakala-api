<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Actions\Agent\FailAgentCommandAction;
use Illuminate\Foundation\Http\FormRequest;

final class FailAgentCommandRequest extends FormRequest
{
    /** @return array<string, array<string|string>> */
    public function rules(): array
    {
        return [
            'error_code' => [
                'required',
                'string',
                'max:'.FailAgentCommandAction::MAX_ERROR_CODE_LENGTH,
            ],
            'error_message' => [
                'required',
                'string',
                'max:'.FailAgentCommandAction::MAX_ERROR_MESSAGE_LENGTH,
            ],
        ];
    }

    public function error_code(): string
    {
        return (string) $this->validated('error_code');
    }

    public function error_message(): string
    {
        return (string) $this->validated('error_message');
    }
}
