<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A rule of a managed resource (a database, ADR-020, a bucket, ADR-021, or
 * a Valkey store, ADR-023) or of a custom domain (ADR-024)
 * the console reads by `code`; `message` is for people and may change. Every
 * concrete subclass declares CODE, STATUS, CONTEXT (field => OpenAPI type of
 * the extra fields), and DESCRIPTION, which
 * ManagedResourceRuleExceptionToResponseExtension documents.
 */
abstract class ManagedResourceRuleException extends RuntimeException
{
    public const CODE = '';

    public const STATUS = 409;

    /** @var array<string, string> Extra field => `integer`, `string`, `nullable-string`, or `array`. */
    public const CONTEXT = [];

    /** What the response says about the code, for the OpenAPI document. */
    public const DESCRIPTION = '';

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [];
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => static::CODE,
            ...$this->context(),
        ], static::STATUS);
    }
}
