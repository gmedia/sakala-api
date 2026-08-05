<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\GitHub\RepositoryParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class GithubRepositoryUrl implements ValidationRule
{
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        try {
            app(RepositoryParser::class)->parse($value);
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
    }
}
