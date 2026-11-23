<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Enums\RollbackIneligibleReason;
use App\Enums\RuntimeStatus;
use App\Exceptions\Runtime\DeploymentNotRollbackTargetException;
use App\Exceptions\Runtime\ProjectNotRunningException;
use App\Exceptions\Runtime\ProjectRuntimeBusyException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * The 409 answers of rolling back and of restarting a project, each with its
 * stable `code` (docs/API_CONVENTIONS.md). MergeSameStatusResponsesExtension
 * joins those of one operation.
 */
final class RollbackAndRestartConflictExceptionToResponseExtension extends ExceptionToResponseExtension
{
    private const HANDLED = [
        DeploymentNotRollbackTargetException::class,
        ProjectNotRunningException::class,
        ProjectRuntimeBusyException::class,
    ];

    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && in_array($type->name, self::HANDLED, true);
    }

    public function toResponse(Type $type): Response
    {
        $class = $type instanceof ObjectType ? $type->name : ProjectRuntimeBusyException::class;

        [$code, $description, $extra] = match ($class) {
            DeploymentNotRollbackTargetException::class => [
                DeploymentNotRollbackTargetException::CODE,
                'The deployment cannot be rolled back to; `reason` says why.',
                ['reason' => (new StringType)
                    ->enum(array_map(static fn (RollbackIneligibleReason $reason): string => $reason->value, RollbackIneligibleReason::cases()))
                    ->setDescription('`not_succeeded`, `already_active`, `no_commit`, `branch_changed`, or `settings_unavailable` (no recorded settings).')],
            ],
            ProjectNotRunningException::class => [
                ProjectNotRunningException::CODE,
                'The project has no running or crashed live deployment to restart.',
                ['runtime_status' => (new StringType)
                    ->enum(array_map(static fn (RuntimeStatus $status): string => $status->value, RuntimeStatus::cases()))
                    ->setDescription('The current `runtime_status`.')],
            ],
            default => [
                ProjectRuntimeBusyException::CODE,
                'A deployment of the project is queued or running, or a restart, stop, sleep, or wake has not finished.',
                [],
            ],
        };

        $body = (new OpenApiObjectType)
            ->addProperty('message', (new StringType)->setDescription('Error overview, in Indonesian.'))
            ->addProperty('code', (new StringType)->enum([$code])->setDescription($description));

        foreach ($extra as $field => $schema) {
            $body->addProperty($field, $schema);
        }

        $body->setRequired(['message', 'code', ...array_keys($extra)]);

        return Response::make(409)
            ->setDescription($description)
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', class_basename($type->name), $this->components);
    }
}
