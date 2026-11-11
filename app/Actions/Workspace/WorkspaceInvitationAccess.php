<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Exceptions\Workspace\EmailVerificationRequiredException;
use App\Exceptions\Workspace\InvitationClosedException;
use App\Exceptions\Workspace\InvitationExpiredException;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Who may see an invitation, and in what order the answers are given. Reading,
 * accepting, and declining all go through prove(), so they agree:
 *
 * 1. Without the token from the email (compared in constant time) and without
 *    the invited address verified on the account: 404 with the body of an ID
 *    that does not exist, whatever the state, so an ID reveals nothing.
 * 2. An unverified account: 403 EMAIL_VERIFICATION_REQUIRED.
 * 3. Answered or revoked: 409 INVITATION_ALREADY_ACCEPTED, INVITATION_DECLINED,
 *    or INVITATION_REVOKED.
 * 4. Past its expiry: 409 INVITATION_EXPIRED.
 */
final class WorkspaceInvitationAccess
{
    /**
     * Returns whether the caller is the invited verified address, which an
     * owner invitation requires on top of the token.
     */
    public static function prove(User $user, WorkspaceInvitation $invitation, ?string $token): bool
    {
        $byToken = $token !== null && $token !== ''
            && hash_equals($invitation->token_hash, WorkspaceInvitation::hashToken($token));
        $byEmail = self::isInvitedAddress($user, $invitation);

        if (! $byToken && ! $byEmail) {
            self::throwNotFound($invitation);
        }

        if (! $user->hasVerifiedEmail()) {
            throw new EmailVerificationRequiredException;
        }

        self::ensureOpen($invitation);

        return $byEmail;
    }

    /** Called again under the row lock, since another request may have closed it meanwhile. */
    public static function ensureOpen(WorkspaceInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            throw new InvitationClosedException($invitation->status);
        }

        if ($invitation->isExpired()) {
            throw new InvitationExpiredException;
        }
    }

    /**
     * Throws the same exception, and so the same body, as route binding throws
     * for an ID that does not exist.
     */
    public static function throwNotFound(WorkspaceInvitation $invitation): never
    {
        throw (new ModelNotFoundException)->setModel(WorkspaceInvitation::class, [$invitation->id]);
    }

    public static function isInvitedAddress(User $user, WorkspaceInvitation $invitation): bool
    {
        return $user->hasVerifiedEmail() && Str::lower($user->email) === $invitation->email;
    }
}
