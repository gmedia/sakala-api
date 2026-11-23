<?php

declare(strict_types=1);

namespace App\Support\Bucket;

use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;

/**
 * Why the last command of a bucket failed, for people. The codes are the
 * agent's (`buckets::error_codes`, contract section 3.5) plus the control
 * plane's expiry codes. None is the user's to fix in v1, so every summary
 * speaks of the operator.
 */
final class BucketCommandFailure
{
    public const INVALID_RUNTIME_COMMAND = 'invalid_runtime_command';

    public const UNSUPPORTED_RUNTIME_COMMAND = 'unsupported_runtime_command';

    public const NAME_INVALID = 'bucket_name_invalid';

    public const KEY_INVALID = 'bucket_key_invalid';

    public const QUOTA_INVALID = 'bucket_quota_invalid';

    public const CAPACITY_EXCEEDED = 'bucket_capacity_exceeded';

    public const ADMIN_UNAVAILABLE = 'bucket_admin_unavailable';

    public const NOT_FOUND = 'bucket_not_found';

    public const CONFLICT = 'bucket_conflict';

    public const OPERATION_FAILED = 'bucket_operation_failed';

    /**
     * The agent cannot tell whether RustFS applied the command (for example
     * the connection broke after the secret was sent): the same command must
     * be sent again. Name agreed with sakala-agent PR #138; replace here if
     * the agent release names it differently.
     */
    public const OUTCOME_UNKNOWN = 'bucket_outcome_unknown';

    /**
     * Failure codes after which a RotateBucketKey may or may not have changed
     * the secret on the node: the agent's own "outcome unknown", and a lease
     * that expired while the node held the command.
     *
     * @return list<string>
     */
    public static function uncertainRotationCodes(): array
    {
        return [self::OUTCOME_UNKNOWN, ExpireAgentCommandsAction::CODE_LEASE_EXPIRED];
    }

    /**
     * `command`, `code`, `user_fixable`, `summary`, and `failed_at` when the
     * latest command of the bucket failed or expired; null otherwise.
     *
     * @return array{command: string, code: string, user_fixable: bool, summary: string, failed_at: string|null}|null
     */
    public static function present(?AgentCommand $latest): ?array
    {
        if ($latest === null || ! in_array($latest->status, [AgentCommandStatus::Failed, AgentCommandStatus::Expired], true)) {
            return null;
        }

        $code = (string) $latest->error_code;

        return [
            'command' => $latest->type->value,
            'code' => $code,
            'user_fixable' => false,
            'summary' => self::summary($latest->type, $code),
            'failed_at' => $latest->failed_at?->toAtomString(),
        ];
    }

    private static function summary(AgentCommandType $type, string $code): string
    {
        return match ($code) {
            self::CAPACITY_EXCEEDED => 'Server penyimpanan tidak punya cukup ruang untuk kuota ini. Coba kuota yang lebih kecil, atau hubungi operator Sakala.',
            self::ADMIN_UNAVAILABLE => 'Server penyimpanan sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi.',
            self::NOT_FOUND => 'Bucket ini tidak ditemukan di server penyimpanan. Operator Sakala perlu memulihkannya.',
            self::OUTCOME_UNKNOWN => 'Server penyimpanan tidak bisa memastikan apakah permintaan terakhir sudah diterapkan. Sakala mengirimnya ulang; kalau terus terulang, hubungi operator Sakala.',
            ExpireAgentCommandsAction::CODE_LEASE_EXPIRED, ExpireAgentCommandsAction::CODE_QUEUE_EXPIRED => 'Server penyimpanan tidak menyelesaikan permintaan ini tepat waktu. Coba lagi beberapa saat lagi.',
            default => match ($type) {
                AgentCommandType::CreateBucket => 'Server penyimpanan gagal membuat bucket ini. Hapus lalu buat lagi; kalau terulang, hubungi operator Sakala.',
                default => 'Server penyimpanan gagal menjalankan permintaan terakhir. Coba lagi; kalau terulang, hubungi operator Sakala.',
            },
        };
    }
}
