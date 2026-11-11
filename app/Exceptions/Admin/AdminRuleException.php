<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An admin API business rule the console reads by `code`; `message` is for
 * people and may change.
 */
abstract class AdminRuleException extends RuntimeException
{
    abstract public function errorCode(): string;

    abstract public function status(): int;

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [];
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode(),
            ...$this->context(),
        ], $this->status());
    }
}
