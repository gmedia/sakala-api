<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use App\Enums\RollbackIneligibleReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A rollback to a deployment that cannot be gone back to
 * (DeploymentRollbackEligibility). `reason` says why.
 */
final class DeploymentNotRollbackTargetException extends RuntimeException
{
    public const CODE = 'DEPLOYMENT_NOT_ROLLBACK_TARGET';

    public function __construct(
        public readonly RollbackIneligibleReason $reason,
    ) {
        parent::__construct(match ($reason) {
            RollbackIneligibleReason::NotSucceeded => 'Deployment ini tidak berhasil, jadi proyek tidak bisa dikembalikan ke versi ini.',
            RollbackIneligibleReason::AlreadyActive => 'Versi ini sudah melayani proyek kamu, jadi tidak perlu dikembalikan.',
            RollbackIneligibleReason::NoCommit => 'Deployment ini tidak mencatat commit-nya, jadi proyek tidak bisa dikembalikan ke versi ini.',
            RollbackIneligibleReason::BranchChanged => 'Deployment ini memakai branch lain dari branch proyek sekarang, jadi proyek tidak bisa dikembalikan ke versi ini. Deploy branch proyek seperti biasa.',
            RollbackIneligibleReason::SettingsUnavailable => 'Pengaturan yang dipakai deployment ini tidak tercatat, jadi proyek tidak bisa dikembalikan ke versi ini.',
        });
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'reason' => $this->reason->value,
        ], Response::HTTP_CONFLICT);
    }
}
