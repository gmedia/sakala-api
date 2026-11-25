<?php

declare(strict_types=1);

namespace App\Exceptions\CustomDomain;

use App\Exceptions\ManagedResourceRuleException;

/**
 * A custom domain rule (ADR-024). The shape and its OpenAPI description
 * come from ManagedResourceRuleException, like the managed resources.
 */
abstract class CustomDomainRuleException extends ManagedResourceRuleException {}
