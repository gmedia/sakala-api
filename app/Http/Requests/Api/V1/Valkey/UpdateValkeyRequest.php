<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Valkey;

use App\Models\ManagedValkey;
use Illuminate\Validation\Rule;

final class UpdateValkeyRequest extends StoreValkeyRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('valkey')) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            /** New name people see, unique among the workspace's live stores. Never changes anything on the node. */
            'name' => ['required_without:maxmemory_mb', 'string', 'max:'.ManagedValkey::MAX_NAME_LENGTH, $this->uniqueName()],
            /**
             * New `maxmemory` in MB: 32, 64, or 128. Needs a `ready` or `locked` store and no resize on its way.
             * Growth must fit the workspace's Valkey memory and the node. A persistent store refuses to shrink below
             * its data (`last_failure.code` `valkey_size_below_usage`); a cache store evicts keys instead.
             */
            'maxmemory_mb' => ['required_without:name', 'integer', Rule::in(ManagedValkey::SIZES_MB)],
            /** The mode is fixed once created. Sending it answers `422`. */
            'mode' => ['prohibited'],
        ];
    }

    public function newName(): ?string
    {
        $name = $this->validated('name');

        return is_string($name) ? trim($name) : null;
    }

    public function newMaxmemoryMb(): ?int
    {
        $size = $this->validated('maxmemory_mb');

        return $size === null ? null : (int) $size;
    }
}
