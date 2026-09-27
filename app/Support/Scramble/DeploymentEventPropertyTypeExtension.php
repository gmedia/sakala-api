<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Enums\DeploymentEventLevel;
use App\Models\DeploymentEvent;
use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Extensions\PropertyTypeExtension;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;

final class DeploymentEventPropertyTypeExtension implements PropertyTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return $type->name === DeploymentEvent::class;
    }

    public function getPropertyType(PropertyFetchEvent $event): ?Type
    {
        return match ($event->getName()) {
            'level' => $this->level(),

            'metadata' => $this->nullable(
                $this->metadata(),
            ),

            default => null,
        };
    }

    private function nullable(Type $type): Union
    {
        /** @var Union $union */
        $union = Union::wrap([
            $type,
            new NullType,
        ]);

        return $union;
    }

    private function level(): Union
    {
        /** @var Union $union */
        $union = Union::wrap([
            ...array_map(
                static fn (DeploymentEventLevel $level): LiteralStringType => new LiteralStringType($level->value),
                DeploymentEventLevel::cases(),
            ),
        ]);

        return $union;
    }

    private function metadata(): ObjectType
    {
        return new ObjectType('stdClass');
    }
}
