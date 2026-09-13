<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

final class SuspendProjectRequest extends ProjectControlRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(
            'suspend',
            $this->route('project'),
        );
    }
}
