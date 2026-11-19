<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Data\Database\DatabaseCredentialsData;
use App\Exceptions\Database\DatabaseNotReadyException;
use App\Models\ManagedDatabase;
use App\Models\User;
use App\Services\Database\DatabaseConnectionEnvironment;
use App\Support\Database\DatabaseAudit;

/**
 * Hands the connection details to a person. The reveal is recorded before the
 * password leaves: if the audit row cannot be written, the caller gets an
 * error and not the secret. The address and user agent are kept only for
 * admins reading a workspace they do not belong to, like environment variable
 * reveals.
 */
final class RevealDatabaseCredentialsAction
{
    public function __construct(
        private readonly DatabaseConnectionEnvironment $environment,
    ) {}

    /**
     * @param  array{ip_address: string|null, user_agent: string|null}  $request
     *
     * @throws DatabaseNotReadyException
     */
    public function handle(User $actor, ManagedDatabase $database, array $request): DatabaseCredentialsData
    {
        if (! $database->status->isProvisioned()) {
            throw new DatabaseNotReadyException($database->status);
        }

        $member = $database->workspace?->roleOf($actor) !== null;

        DatabaseAudit::record($actor, DatabaseAudit::CREDENTIALS_VIEWED, $database, ['by_workspace_member' => $member], $member ? [] : $request);

        return $this->environment->credentials($database->loadMissing('node'));
    }
}
