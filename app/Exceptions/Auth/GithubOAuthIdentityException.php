<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use App\Enums\GithubOAuthFailure;
use RuntimeException;

final class GithubOAuthIdentityException extends RuntimeException
{
    public function __construct(public readonly GithubOAuthFailure $failure)
    {
        parent::__construct($failure->value);
    }
}
