<?php

declare(strict_types=1);

namespace App\Exceptions\GitHub;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class GithubRateLimitedException extends RuntimeException
{
    private const DEFAULT_RETRY_SECONDS = 60;

    private const MAX_RETRY_SECONDS = 3600;

    public function __construct(
        public readonly int $retryAfter = self::DEFAULT_RETRY_SECONDS,
    ) {
        parent::__construct('Sakala sedang mencapai batas permintaan ke GitHub. Ini bukan masalah di repository kamu. Coba lagi beberapa saat lagi.');
    }

    /**
     * GitHub answers 429, or 403 with rate-limit headers. A plain 403 means
     * access was refused and must stay what it is.
     */
    public static function fromResponse(Response $response): ?self
    {
        $status = $response->status();
        $retryHeader = $response->header('Retry-After');
        $exhausted = $response->header('X-RateLimit-Remaining') === '0';
        $saysSo = str_contains(mb_strtolower((string) $response->json('message', '')), 'rate limit');

        if ($status !== 429 && ! ($status === 403 && ($exhausted || $retryHeader !== '' || $saysSo))) {
            return null;
        }

        $reset = (int) $response->header('X-RateLimit-Reset');
        $seconds = match (true) {
            is_numeric($retryHeader) => (int) $retryHeader,
            $reset > 0 => $reset - now()->getTimestamp(),
            default => self::DEFAULT_RETRY_SECONDS,
        };

        return new self(max(1, min($seconds, self::MAX_RETRY_SECONDS)));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'GITHUB_RATE_LIMITED',
            'retry_after' => $this->retryAfter,
        ], HttpResponse::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) $this->retryAfter]);
    }
}
