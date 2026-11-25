<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\CustomDomain;

use App\Data\CustomDomain\ParsedCustomDomainData;
use App\Exceptions\CustomDomain\InvalidCustomDomainNameException;
use App\Models\Project;
use App\Services\CustomDomain\CustomDomainNameParser;
use App\Services\CustomDomain\CustomDomainPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A custom domain to add to a project (ADR-024). The name is normalized
 * (CustomDomainNameParser) and refused with the reason when it cannot be a
 * custom domain.
 */
final class StoreProjectDomainRequest extends FormRequest
{
    private ?ParsedCustomDomainData $parsed = null;

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * The domain, for example `www.contoh.com` or `contoh.com`. Case, a trailing dot, a scheme, a path, and a
             * port are dropped; an international name is stored as punycode (`xn--`). Refused: IP addresses,
             * wildcards, one-label names, public suffixes such as `ac.id` or `my.id`, unknown suffixes, and names
             * under Sakala's own zones.
             */
            'hostname' => [
                'required',
                'string',
                // Room for a scheme, path, or port that are dropped; the final name is at most 253 characters.
                'max:'.(CustomDomainPolicy::MAX_HOSTNAME_LENGTH * 2),
                function (string $attribute, mixed $value, Closure $fail): void {
                    try {
                        $this->parsed = app(CustomDomainNameParser::class)->parse((string) $value);
                    } catch (InvalidCustomDomainNameException $exception) {
                        $fail($exception->getMessage());
                    }
                },
            ],
        ];
    }

    public function toData(): ParsedCustomDomainData
    {
        return $this->parsed ?? app(CustomDomainNameParser::class)->parse($this->string('hostname')->toString());
    }
}
