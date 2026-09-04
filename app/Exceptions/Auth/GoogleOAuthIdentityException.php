<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Enums\GoogleOAuthFailure;
use RuntimeException;

final class GoogleOAuthIdentityException extends RuntimeException
{
    public function __construct(public readonly GoogleOAuthFailure $failure)
    {
        parent::__construct($failure->value);
    }
}
