<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Exceptions\Runtime\BuildRateLimitExceededException;
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
 * The 422 of a deployment over the workspace's build rate limit, with a
 * stable `code` (docs/API_CONVENTIONS.md).
 */
final class BuildRateLimitExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(BuildRateLimitExceededException::class);
    }

    public function toResponse(Type $type): Response
    {
        $body = (new OpenApiObjectType)
            ->addProperty('message', (new StringType)->setDescription('Error overview, in Indonesian, with when to try again.'))
            ->addProperty('code', (new StringType)
                ->enum([BuildRateLimitExceededException::CODE])
                ->setDescription('The workspace created as many deployments in the rolling hour as its limit allows. Every deployment builds, whatever its status.'))
            ->addProperty('limit', (new IntegerType)->setDescription('Deployments the workspace may create per rolling hour.'))
            ->addProperty('current', (new IntegerType)->setDescription('Deployments of the workspace counted in the rolling hour, deleted projects included. Can exceed `limit` after an admin lowered it.'))
            ->addProperty('retry_after_seconds', (new IntegerType)->setDescription('Seconds until the window frees a slot; at least 1.'))
            ->setRequired(['message', 'code', 'limit', 'current', 'retry_after_seconds']);

        return Response::make(422)
            ->setDescription('The workspace reached its build rate limit')
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', 'BuildRateLimitExceeded', $this->components);
    }
}
