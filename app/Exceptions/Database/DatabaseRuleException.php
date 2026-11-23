<?php

declare(strict_types=1);

namespace App\Exceptions\Database;

use App\Exceptions\ManagedResourceRuleException;

/**
 * A managed database rule (ADR-020). The shape and its OpenAPI description
 * come from ManagedResourceRuleException.
 */
abstract class DatabaseRuleException extends ManagedResourceRuleException {}
