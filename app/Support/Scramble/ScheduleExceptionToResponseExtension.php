<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Enums\ScheduleRunReason;
use App\Exceptions\Schedule\ScheduleLimitExceededException;
use App\Exceptions\Schedule\ScheduleRunNotOpenException;
use App\Exceptions\Schedule\ScheduleRunRefusedException;
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
 * The schedule answers with a stable `code` (ADR-022,
 * docs/API_CONVENTIONS.md#jadwal): 409 `SCHEDULE_RUN_REFUSED` and
 * `SCHEDULE_RUN_NOT_OPEN`, and 422 `SCHEDULE_LIMIT_EXCEEDED`.
 */
final class ScheduleExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && ($type->isInstanceOf(ScheduleRunRefusedException::class)
                || $type->isInstanceOf(ScheduleRunNotOpenException::class)
                || $type->isInstanceOf(ScheduleLimitExceededException::class));
    }

    public function toResponse(Type $type): Response
    {
        if ($type instanceof ObjectType && $type->isInstanceOf(ScheduleLimitExceededException::class)) {
            $body = (new OpenApiObjectType)
                ->addProperty('message', (new StringType)->setDescription('Error overview, in Indonesian.'))
                ->addProperty('code', (new StringType)->enum([ScheduleLimitExceededException::CODE]))
                ->addProperty('limit', (new IntegerType)->setDescription('The workspace limit of enabled schedules.'))
                ->addProperty('current', (new IntegerType)->setDescription('Enabled schedules of the workspace now.'))
                ->setRequired(['message', 'code', 'limit', 'current']);

            return Response::make(422)
                ->setDescription('The workspace already has as many enabled schedules as it may')
                ->setContent('application/json', Schema::fromType($body));
        }

        $body = (new OpenApiObjectType)
            ->addProperty('message', (new StringType)->setDescription('Error overview, in Indonesian.'))
            ->addProperty('code', (new StringType)
                ->enum([ScheduleRunRefusedException::CODE, ScheduleRunNotOpenException::CODE])
                ->setDescription('`SCHEDULE_RUN_REFUSED`: Run now cannot start a run, see `reason`. `SCHEDULE_RUN_NOT_OPEN`: Stop run on a run that already ended.'))
            ->addProperty('reason', (new StringType)
                ->enum(array_map(static fn (ScheduleRunReason $reason): string => $reason->value, ScheduleRunReason::cases()))
                ->setDescription('Only with `SCHEDULE_RUN_REFUSED`: why a slot would be skipped now.'))
            ->setRequired(['message', 'code']);

        return Response::make(409)
            ->setDescription('The run cannot be started or stopped')
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', $type->isInstanceOf(ScheduleLimitExceededException::class) ? 'ScheduleLimitExceeded' : 'ScheduleRunConflict', $this->components);
    }
}
