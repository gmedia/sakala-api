<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Collection;

/**
 * Scramble keys an operation's responses by status and keeps only the last
 * one of a status, so a business error with a stable `code` that shares its
 * status with another answer (a 422 next to validation errors) would silently
 * disappear. This merges such a group into one response whose JSON body is
 * any of theirs. Groups without a `code` body are left as Scramble made them.
 * Runs after Scramble's own response extension.
 */
final class MergeSameStatusResponsesExtension extends OperationExtension
{
    private const JSON = 'application/json';

    /** The property that marks a business error body (docs/API_CONVENTIONS.md). */
    private const CODE_PROPERTY = 'code';

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        /** @var Collection<string, Collection<int, Response|Reference>> $byStatus */
        $byStatus = collect($operation->responses ?? [])
            ->groupBy(static fn (Response|Reference $response): string => (string) self::resolve($response)->code);

        $operation->responses = $byStatus
            ->map(static fn (Collection $group): array => self::shouldMerge($group) ? [self::merge($group)] : $group->all())
            ->flatten(1)
            ->all();
    }

    /** @param Collection<int, Response|Reference> $group */
    private static function shouldMerge(Collection $group): bool
    {
        return $group->count() > 1
            && self::bodies($group)->contains(static fn (Type $body): bool => $body instanceof ObjectType && $body->hasProperty(self::CODE_PROPERTY));
    }

    /** @param Collection<int, Response|Reference> $group */
    private static function merge(Collection $group): Response
    {
        $resolved = $group->map(self::resolve(...));
        $bodies = self::bodies($group)
            ->unique(static fn (Type $body): string => (string) json_encode($body->toArray()))
            ->values();

        return Response::make($resolved->first()?->code)
            ->setDescription($resolved->pluck('description')->unique()->implode("\n\n"))
            ->setContent(self::JSON, Schema::fromType($bodies->count() > 1 ? (new AnyOf)->setItems($bodies->all()) : $bodies->first()));
    }

    /**
     * @param  Collection<int, Response|Reference>  $group
     * @return Collection<int, Type>
     */
    private static function bodies(Collection $group): Collection
    {
        return $group
            ->map(self::resolve(...))
            ->filter(static fn (Response $response): bool => array_key_exists(self::JSON, $response->content))
            ->map(static function (Response $response): ?Type {
                $schema = $response->getContent(self::JSON);

                return $schema instanceof Reference ? $schema->resolve()->type : $schema->type;
            })
            ->filter()
            ->values();
    }

    private static function resolve(Response|Reference $response): Response
    {
        return $response instanceof Reference ? $response->resolve() : $response;
    }
}
