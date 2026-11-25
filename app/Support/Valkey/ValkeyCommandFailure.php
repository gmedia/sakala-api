<?php

declare(strict_types=1);

namespace App\Support\Valkey;

use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;

/**
 * Why the last command of a Valkey store failed, for people. The codes are
 * the agent's (`docs/AGENT_API.md#kode-kegagalan-valkey`) plus the control
 * plane's expiry codes. Only `valkey_size_below_usage` and `valkey_in_use`
 * are the user's to fix; every other summary speaks of the operator.
 */
final class ValkeyCommandFailure
{
    public const INVALID_RUNTIME_COMMAND = 'invalid_runtime_command';

    public const UNSUPPORTED_RUNTIME_COMMAND = 'unsupported_runtime_command';

    public const MODE_INVALID = 'valkey_mode_invalid';

    public const MEMORY_INVALID = 'valkey_memory_invalid';

    public const PASSWORD_INVALID = 'valkey_password_invalid';

    public const CAPACITY_EXCEEDED = 'valkey_capacity_exceeded';

    public const NOT_FOUND = 'valkey_not_found';

    public const CONFLICT = 'valkey_conflict';

    public const SIZE_BELOW_USAGE = 'valkey_size_below_usage';

    public const IN_USE = 'valkey_in_use';

    /**
     * Docker or the store did not answer. A change may already be written to
     * the store's files and take effect when it starts again, so for a
     * rotation the outcome is unknown.
     */
    public const UNAVAILABLE = 'valkey_unavailable';

    public const OPERATION_FAILED = 'valkey_operation_failed';

    /** DeployProject or RunJob: the store network is missing on the node, not the agent's, or the store is elsewhere. */
    public const NETWORK_UNAVAILABLE = 'valkey_network_unavailable';

    /**
     * Failure codes after which a RotateValkeyPassword may or may not have
     * changed the password: the store not answering (the change waits in its
     * files), and a lease that expired while the node held the command.
     *
     * @return list<string>
     */
    public static function uncertainRotationCodes(): array
    {
        return [self::UNAVAILABLE, ExpireAgentCommandsAction::CODE_LEASE_EXPIRED];
    }

    /**
     * Failure codes of a resend (attempt > 1) after which the same password
     * is sent again. The same as the uncertain ones: the store not answering
     * is also what passes.
     *
     * @return list<string>
     */
    public static function resendableRotationCodes(): array
    {
        return self::uncertainRotationCodes();
    }

    /** Codes the user can act on themselves. */
    public static function userFixable(string $code): bool
    {
        return in_array($code, [self::SIZE_BELOW_USAGE, self::IN_USE], true);
    }

    /**
     * `command`, `code`, `user_fixable`, `summary`, and `failed_at` when the
     * latest command of the store failed or expired; null otherwise.
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
            'user_fixable' => self::userFixable($code),
            'summary' => self::summary($latest->type, $code),
            'failed_at' => $latest->failed_at?->toAtomString(),
        ];
    }

    public static function summary(AgentCommandType $type, string $code): string
    {
        return match ($code) {
            self::CAPACITY_EXCEEDED => 'Server Valkey tidak punya cukup ruang memori untuk ukuran ini. Coba ukuran yang lebih kecil, atau hubungi operator Sakala.',
            self::SIZE_BELOW_USAGE => 'Data di Valkey ini lebih besar dari ukuran baru yang diminta, jadi ukurannya tidak diubah. Kosongkan sebagian data dulu, atau pilih ukuran yang lebih besar.',
            self::IN_USE => 'Masih ada container proyek yang tersambung ke Valkey ini, biasanya proyek yang sudah dilepas tetapi belum di-deploy ulang. Deploy ulang atau hentikan proyek itu, lalu hapus lagi.',
            self::UNAVAILABLE => 'Server Valkey sedang tidak menjawab atau store-nya tidak berjalan. Coba lagi beberapa saat lagi; perubahan yang sudah tercatat berlaku saat store menyala lagi.',
            self::NOT_FOUND => 'Valkey ini tidak ditemukan di servernya. Operator Sakala perlu memulihkannya.',
            ExpireAgentCommandsAction::CODE_LEASE_EXPIRED, ExpireAgentCommandsAction::CODE_QUEUE_EXPIRED => 'Server Valkey tidak menyelesaikan permintaan ini tepat waktu. Coba lagi beberapa saat lagi.',
            default => match ($type) {
                AgentCommandType::CreateValkey => 'Server Valkey gagal membuat store ini. Hapus lalu buat lagi; kalau terulang, hubungi operator Sakala.',
                default => 'Server Valkey gagal menjalankan permintaan terakhir. Coba lagi; kalau terulang, hubungi operator Sakala.',
            },
        };
    }
}
