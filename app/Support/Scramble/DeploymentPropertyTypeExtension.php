<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Enums\FinalizationDeferredReason;
use App\Models\Deployment;
use Dedoc\Scramble\Infer\Extensions\Event\PropertyFetchEvent;
use Dedoc\Scramble\Infer\Extensions\PropertyTypeExtension;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\KeyedArrayType;
use Dedoc\Scramble\Support\Type\Literal\LiteralStringType;
use Dedoc\Scramble\Support\Type\NullType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\StringType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\Union;

final class DeploymentPropertyTypeExtension implements PropertyTypeExtension
{
    private function nullable(Type $type): Union
    {
        /** @var Union $union */
        $union = Union::wrap([
            $type,
            new NullType,
        ]);

        return $union;
    }

    private function finalizationDeferredReason(): Union
    {
        /** @var Union $union */
        $union = Union::wrap([
            ...array_map(
                static fn (FinalizationDeferredReason $reason): LiteralStringType => new LiteralStringType($reason->value),
                FinalizationDeferredReason::cases(),
            ),
            new NullType,
        ]);

        return $union;
    }

    private function requestedResources(): KeyedArrayType
    {
        return new KeyedArrayType([
            new ArrayItemType_(
                'memory_mb',
                $this->nullable(new IntegerType),
            ),
            new ArrayItemType_(
                'cpu_millis',
                $this->nullable(new IntegerType),
            ),
            new ArrayItemType_(
                'pids_limit',
                $this->nullable(new IntegerType),
            ),
        ]);
    }

    private function effectiveResources(): KeyedArrayType
    {
        return new KeyedArrayType([
            new ArrayItemType_(
                'resources',
                new KeyedArrayType([
                    new ArrayItemType_(
                        'memory_mb',
                        new IntegerType,
                    ),
                    new ArrayItemType_(
                        'cpu_millis',
                        new IntegerType,
                    ),
                    new ArrayItemType_(
                        'pids_limit',
                        new IntegerType,
                    ),
                ]),
            ),
            new ArrayItemType_(
                'timeouts',
                new KeyedArrayType([
                    new ArrayItemType_(
                        'build_timeout_seconds',
                        new IntegerType,
                    ),
                    new ArrayItemType_(
                        'start_timeout_seconds',
                        new IntegerType,
                    ),
                    new ArrayItemType_(
                        'command_timeout_seconds',
                        new IntegerType,
                    ),
                ]),
            ),
            new ArrayItemType_(
                'log_bounds',
                new KeyedArrayType([
                    new ArrayItemType_(
                        'max_line_length',
                        new IntegerType,
                    ),
                    new ArrayItemType_(
                        'max_batch_lines',
                        new IntegerType,
                    ),
                    new ArrayItemType_(
                        'max_total_bytes',
                        new IntegerType,
                    ),
                ]),
            ),
        ]);
    }

    private function appliedResources(): KeyedArrayType
    {
        return new KeyedArrayType([
            new ArrayItemType_(
                'memory_mb',
                new IntegerType,
            ),
            new ArrayItemType_(
                'cpu_millis',
                new IntegerType,
            ),
            new ArrayItemType_(
                'pids_limit',
                new IntegerType,
            ),
        ]);
    }

    public function shouldHandle(ObjectType $type): bool
    {
        return $type->name === Deployment::class;
    }

    public function getPropertyType(PropertyFetchEvent $event): ?Type
    {
        return match ($event->getName()) {
            'requested_resources' => $this->nullable(
                $this->requestedResources(),
            ),

            'effective_resources' => $this->nullable(
                $this->effectiveResources(),
            ),

            'applied_resources' => $this->nullable(
                $this->appliedResources(),
            ),

            /** @var Union $union */
            'finalization_deferred_reason' => $this->finalizationDeferredReason(),

            'started_at',
            'finished_at',
            'cancelled_at' => $this->nullable(
                new StringType,
            ),

            default => null,
        };
    }
}
