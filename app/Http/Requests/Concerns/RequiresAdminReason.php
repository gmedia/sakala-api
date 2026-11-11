<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Every admin mutation carries a reason, stored in its audit event.
 *
 * @mixin FormRequest
 */
trait RequiresAdminReason
{
    /** @return array<string, array<int, string>> */
    protected function reasonRules(): array
    {
        return [
            /** Why the admin is doing this. Stored in the audit event. */
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function reason(): string
    {
        return trim($this->string('reason')->toString());
    }
}
