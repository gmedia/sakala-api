<?php

declare(strict_types=1);

namespace App\Actions\Legal;

use App\Enums\LegalAcceptanceSource;
use App\Models\OAuthAccount;
use App\Models\User;

/**
 * Acceptance from inside the console: the first one of an account that signed up
 * through a provider, or a later one after a version changed.
 */
final class AcceptLegalDocumentsAction
{
    public function __construct(
        private readonly RecordLegalAcceptanceAction $record,
    ) {}

    public function handle(User $user): User
    {
        $this->record->handle($user, $this->source($user));

        return $user;
    }

    private function source(User $user): LegalAcceptanceSource
    {
        if ($user->legalAcceptances()->exists()) {
            return LegalAcceptanceSource::Reacceptance;
        }

        $account = $user->oauthAccounts()->oldest('id')->first();

        return $account instanceof OAuthAccount
            ? LegalAcceptanceSource::from($account->provider->value)
            : LegalAcceptanceSource::Reacceptance;
    }
}
