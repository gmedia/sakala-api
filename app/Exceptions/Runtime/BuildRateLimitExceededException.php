<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use App\Services\Runtime\PilotRuntimeLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The workspace already created as many deployments as its build rate limit
 * allows in the rolling window. Every deployment builds an image, so every
 * deployment counts, whatever its status.
 */
final class BuildRateLimitExceededException extends RuntimeException
{
    public const CODE = 'BUILD_RATE_LIMIT_EXCEEDED';

    /** The limit name usage signals record for this rejection. */
    public const LIMIT_NAME = 'max_builds_per_hour';

    private const SECONDS_PER_MINUTE = 60;

    public function __construct(
        public readonly int $limit,
        /** Deployments of the workspace counted in the window. */
        public readonly int $current,
        /** Seconds until the window frees a slot; at least 1. */
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(sprintf(
            'Workspace ini sudah menjalankan %d build dalam %d menit terakhir, dan itu batasnya. Coba deploy %s.',
            $limit,
            intdiv(PilotRuntimeLimitService::BUILD_RATE_WINDOW_SECONDS, self::SECONDS_PER_MINUTE),
            $this->retryHint(),
        ));
    }

    /**
     * When a deployment fits again, for a sentence: minutes rounded up, or
     * seconds when that is under a minute.
     */
    public function retryHint(): string
    {
        if ($this->retryAfterSeconds < self::SECONDS_PER_MINUTE) {
            return sprintf('sekitar %d detik lagi', $this->retryAfterSeconds);
        }

        return sprintf('sekitar %d menit lagi', (int) ceil($this->retryAfterSeconds / self::SECONDS_PER_MINUTE));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'limit' => $this->limit,
            'current' => $this->current,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
