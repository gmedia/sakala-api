<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Data\Valkey\ValkeyCredentialsData;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Exceptions\Valkey\ValkeyPasswordRotatingException;
use App\Models\ManagedValkey;
use App\Models\User;
use App\Services\Valkey\ValkeyConnectionEnvironment;
use App\Support\Valkey\ValkeyAudit;
use App\Support\Valkey\ValkeyPasswordWindow;

/**
 * Hands the store's password to a person (ADR-023: every reveal is
 * audited). The reveal is recorded before the password leaves: if the audit
 * row cannot be written, the caller gets an error and not the password. The
 * address and user agent are kept only for admins reading a workspace they
 * do not belong to, like database and bucket credentials.
 */
final class RevealValkeyCredentialsAction
{
    public function __construct(
        private readonly ValkeyConnectionEnvironment $environment,
    ) {}

    /**
     * @param  array{ip_address: string|null, user_agent: string|null}  $request
     *
     * @throws ValkeyNotReadyException
     * @throws ValkeyPasswordRotatingException while nobody knows which password works (ValkeyPasswordWindow).
     */
    public function handle(User $actor, ManagedValkey $valkey, array $request): ValkeyCredentialsData
    {
        if (! $valkey->status->isProvisioned()) {
            throw new ValkeyNotReadyException($valkey->status);
        }

        $credentials = $this->environment->credentials($valkey);

        if ($credentials === null) {
            throw new ValkeyNotReadyException($valkey->status);
        }

        if (ValkeyPasswordWindow::isOpen($valkey)) {
            throw new ValkeyPasswordRotatingException($valkey);
        }

        $member = $valkey->workspace?->roleOf($actor) !== null;

        ValkeyAudit::record($actor, ValkeyAudit::CREDENTIALS_VIEWED, $valkey, [
            'by_workspace_member' => $member,
        ], $member ? [] : $request);

        return $credentials;
    }
}
