<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Exceptions\Runtime\ProjectNotSleepingException;
use App\Exceptions\Runtime\ProjectSuspendedException;
use App\Exceptions\Runtime\ProjectWakeLimitExceededException;
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
 * The 409 answers of waking a project, with a stable `code`
 * (docs/API_CONVENTIONS.md). They share one response, because the console
 * wake can answer any of them.
 */
final class ProjectWakeConflictExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && ($type->isInstanceOf(ProjectWakeLimitExceededException::class)
                || $type->isInstanceOf(ProjectNotSleepingException::class)
                || $type->isInstanceOf(ProjectSuspendedException::class));
    }

    public function toResponse(Type $type): Response
    {
        $body = (new OpenApiObjectType)
            ->addProperty('message', (new StringType)->setDescription('Error overview, in Indonesian.'))
            ->addProperty('code', (new StringType)
                ->enum(['PROJECT_WAKE_LIMIT_EXCEEDED', 'PROJECT_NOT_SLEEPING', 'PROJECT_SUSPENDED'])
                ->setDescription('`PROJECT_WAKE_LIMIT_EXCEEDED`: the workspace already runs as many projects as it may. `PROJECT_NOT_SLEEPING`: only a sleeping project can be woken. `PROJECT_SUSPENDED`: an admin suspended the project. The public wake only answers `PROJECT_WAKE_LIMIT_EXCEEDED`.'))
            ->addProperty('limit', (new IntegerType)->setDescription('The workspace limit of running projects. Only with `PROJECT_WAKE_LIMIT_EXCEEDED`, and never on the public wake.'))
            ->addProperty('current', (new IntegerType)->setDescription('Projects the workspace runs now. Same presence as `limit`.'))
            ->addProperty('runtime_status', (new StringType)->setDescription('The current `runtime_status`. Only with `PROJECT_NOT_SLEEPING`.'))
            ->setRequired(['message', 'code']);

        return Response::make(409)
            ->setDescription('The project cannot be woken now')
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', 'ProjectWakeConflict', $this->components);
    }
}
