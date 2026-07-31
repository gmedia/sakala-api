<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\GitHub\RepositoryParser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class GithubRepositoryUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        //
        try {
            app(RepositoryParser::class)->parse($value);
        } catch (\Throwable $th) {
            $fail('The :attribute is not a valid GitHub repository URL.');
        }
    }
}
