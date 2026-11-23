<?php

declare(strict_types=1);

namespace App\Support\Bucket;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use App\Models\ManagedBucket;
use App\Support\Attachment\CredentialRotation;

/**
 * The key uncertain window of a bucket (contract section 13): while it is
 * open, nobody can say whether the stored secret or the rotation's new one
 * works on the node, so no deployment receives either and the owner cannot
 * read the secret. It is open while the newest RotateBucketKey
 *
 * - has been claimed by the node (it may switch at any moment), or
 * - is a resend (attempt > 1) that is still open, or
 * - ended `unknown`, until a later CreateBucket (`buckets:reconcile`) or a
 *   new rotation completes and puts a known secret back on the node.
 *
 * A first attempt still pending and not claimed does not open it: the old
 * secret certainly works, and an offline storage node must not block deploys.
 */
final class BucketKeyWindow
{
    public static function isOpen(ManagedBucket $bucket): bool
    {
        $latest = self::latestRotation($bucket);

        if ($latest === null) {
            return false;
        }

        if ($latest->status->isOpen()) {
            return $latest->status !== AgentCommandStatus::Pending || CredentialRotation::attempt($latest) > 1;
        }

        return CredentialRotation::uncertain($latest) && ! self::settledAfter($bucket, $latest);
    }

    private static function latestRotation(ManagedBucket $bucket): ?AgentCommand
    {
        if ($bucket->relationLoaded('latestKeyRotation')) {
            return $bucket->latestKeyRotation;
        }

        return $bucket->latestKeyRotation()->first();
    }

    /** A CreateBucket that completed after the unknown rotation set the stored secret on the node again. */
    private static function settledAfter(ManagedBucket $bucket, AgentCommand $rotation): bool
    {
        return $bucket->commands()
            ->where('type', AgentCommandType::CreateBucket)
            ->where('status', AgentCommandStatus::Succeeded)
            ->where('created_at', '>=', $rotation->created_at)
            ->whereKeyNot($rotation->id)
            ->exists();
    }
}
