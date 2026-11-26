<?php

declare(strict_types=1);

namespace App\Exceptions\Valkey;

use App\Exceptions\ManagedResourceRuleException;

/**
 * A managed Valkey rule (ADR-023). The shape and its OpenAPI description
 * come from ManagedResourceRuleException.
 */
abstract class ValkeyRuleException extends ManagedResourceRuleException {}
