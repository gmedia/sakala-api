<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

final class UnsuspendProjectRequest extends ProjectControlRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(
            'unsuspend',
            $this->route('project'),
        );
    }
}
