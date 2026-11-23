<?php

declare(strict_types=1);

namespace App\Exceptions\Bucket;

use App\Exceptions\ManagedResourceRuleException;

/**
 * A managed bucket rule (ADR-021). The shape and its OpenAPI description
 * come from ManagedResourceRuleException.
 */
abstract class BucketRuleException extends ManagedResourceRuleException {}
