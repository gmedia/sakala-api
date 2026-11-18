<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Models\EdgeToken;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues, rotates, and revokes Edge tokens. Only the HMAC is stored; the
 * plaintext token exists once, in the return value of issue(). Every change
 * writes an audit event without the token.
 */
final class ManageEdgeTokenAction
{
    public const ISSUED_AUDIT_ACTION = 'edge_token.issued';

    public const ROTATED_AUDIT_ACTION = 'edge_token.rotated';

    public const REVOKED_AUDIT_ACTION = 'edge_token.revoked';

    /**
     * Gives the named Edge instance a new token: a new record, or a new secret
     * for an existing one, which stops the old token at once and lifts a
     * revocation.
     *
     * @return array{token: EdgeToken, plaintext: string}
     */
    public function issue(string $name, string $reason): array
    {
        return DB::transaction(function () use ($name, $reason): array {
            $plaintext = Str::random(EdgeToken::TOKEN_LENGTH);
            $existing = EdgeToken::query()->where('name', $name)->lockForUpdate()->first();
            $token = $existing ?? new EdgeToken(['name' => $name]);

            $token->fill([
                'token_hash' => EdgeToken::hash($plaintext),
                'token_prefix' => Str::substr($plaintext, 0, EdgeToken::PREFIX_LENGTH),
                'revoked_at' => null,
            ])->save();

            AdminAudit::recordFromConsole(
                $existing === null ? self::ISSUED_AUDIT_ACTION : self::ROTATED_AUDIT_ACTION,
                $token,
                $reason,
                ['name' => $name, 'token_prefix' => $token->token_prefix],
            );

            return ['token' => $token, 'plaintext' => $plaintext];
        });
    }

    /** Revokes the named token; null when there is none. Revoking twice changes nothing. */
    public function revoke(string $name, string $reason): ?EdgeToken
    {
        return DB::transaction(function () use ($name, $reason): ?EdgeToken {
            $token = EdgeToken::query()->where('name', $name)->lockForUpdate()->first();

            if ($token === null || $token->isRevoked()) {
                return $token;
            }

            $token->forceFill(['revoked_at' => now()])->save();

            AdminAudit::recordFromConsole(self::REVOKED_AUDIT_ACTION, $token, $reason, ['name' => $name]);

            return $token;
        });
    }
}
