<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Enums\LegalDocument;
use Illuminate\Validation\Rule;

/**
 * `accept_terms` is the single required declaration on the form: the person is 18
 * or has a parent's or guardian's consent, and accepts the terms and acceptable
 * use policy. The client names the versions it showed, so acceptance is never
 * recorded for a document the person did not see: a stale page gets a validation
 * error instead.
 */
trait ValidatesLegalAcceptance
{
    /** @return array<string, array<int, mixed>> */
    protected function legalAcceptanceRules(): array
    {
        return [
            'accept_terms' => ['required', 'accepted'],
            'terms_version' => ['required', 'string', Rule::in([LegalDocument::Terms->currentVersion()])],
            'acceptable_use_version' => ['required', 'string', Rule::in([LegalDocument::AcceptableUse->currentVersion()])],
        ];
    }
}
