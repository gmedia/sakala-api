<?php

declare(strict_types=1);

namespace App\Support\Scramble;

use App\Exceptions\Bucket\BucketRuleException;
use App\Exceptions\CustomDomain\CustomDomainRuleException;
use App\Exceptions\Database\DatabaseRuleException;
use App\Exceptions\ManagedResourceRuleException;
use App\Exceptions\Valkey\ValkeyRuleException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type as OpenApiType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Every managed database, bucket, Valkey, and custom domain rule answer, with its stable `code`
 * (docs/API_CONVENTIONS.md). The exception class declares the code, status,
 * description, and extra fields, so one extension covers them all;
 * MergeSameStatusResponsesExtension joins those of one status.
 */
final class ManagedResourceRuleExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(ManagedResourceRuleException::class)
            && ! in_array($type->name, [ManagedResourceRuleException::class, DatabaseRuleException::class, BucketRuleException::class, ValkeyRuleException::class, CustomDomainRuleException::class], true);
    }

    public function toResponse(Type $type): Response
    {
        /** @var class-string<ManagedResourceRuleException> $class */
        $class = $type instanceof ObjectType ? $type->name : ManagedResourceRuleException::class;

        $body = (new OpenApiObjectType)
            ->addProperty('message', (new StringType)->setDescription('Error overview, in Indonesian.'))
            ->addProperty('code', (new StringType)->enum([$class::CODE])->setDescription($class::DESCRIPTION));

        foreach ($class::CONTEXT as $field => $kind) {
            $body->addProperty($field, $this->typeOf($kind));
        }

        $body->setRequired(['message', 'code', ...array_keys($class::CONTEXT)]);

        return Response::make($class::STATUS)
            ->setDescription($class::DESCRIPTION)
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', class_basename($type->name), $this->components);
    }

    private function typeOf(string $kind): OpenApiType
    {
        return match ($kind) {
            'integer' => new IntegerType,
            'nullable-string' => (new StringType)->nullable(true),
            'array' => new ArrayType,
            default => new StringType,
        };
    }
}
