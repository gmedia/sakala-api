<?php

declare(strict_types=1);

namespace App\Exceptions\Agent;

use RuntimeException;

final class ReportIdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Idempotency-Key has already been used for a different report payload.');
    }
}
