<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Exceptions\GitHub\GithubRateLimitedException;
use App\Exceptions\GitHub\GithubUnavailableException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Both GitHub exceptions render a 503 with a stable `code` (docs/API_CONVENTIONS.md).
 * They share one response, because an operation that reads GitHub can answer either.
 */
final class GithubUnavailableExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && ($type->isInstanceOf(GithubUnavailableException::class) || $type->isInstanceOf(GithubRateLimitedException::class));
    }

    public function toResponse(Type $type): Response
    {
        $body = (new OpenApiObjectType)
            ->addProperty('message', (new StringType)->setDescription('Error overview, in Indonesian.'))
            ->addProperty('code', (new StringType)
                ->enum(['GITHUB_UNAVAILABLE', 'GITHUB_RATE_LIMITED'])
                ->setDescription('`GITHUB_UNAVAILABLE`: GitHub answered with an error, an unreadable body, or not at all. `GITHUB_RATE_LIMITED`: GitHub limits Sakala and there is no stored answer.'))
            ->addProperty('retry_after', (new IntegerType)->setDescription('Seconds to wait, also in the `Retry-After` header. Only with `GITHUB_RATE_LIMITED`.'))
            ->setRequired(['message', 'code']);

        return Response::make(503)
            ->setDescription('GitHub is unavailable or limits Sakala; safe to retry later')
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', 'GithubUnavailable', $this->components);
    }
}
