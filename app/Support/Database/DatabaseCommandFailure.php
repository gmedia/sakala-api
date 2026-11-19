<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Actions\Agent\ExpireAgentCommandsAction;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Models\AgentCommand;

/**
 * Why the last command of a database failed, for people. The codes are the
 * agent's (sakala-agent docs/AGENT_API.md "Kode kegagalan database") plus the
 * control plane's expiry codes. Only a refused extension is the user's to fix;
 * the rest is the operator's, and the summary says so.
 */
final class DatabaseCommandFailure
{
    public const NAME_INVALID = 'database_name_invalid';

    public const PASSWORD_INVALID = 'database_password_invalid';

    public const EXTENSION_NOT_ALLOWED = 'database_extension_not_allowed';

    public const EXTENSION_UNAVAILABLE = 'database_extension_unavailable';

    public const NOT_FOUND = 'database_not_found';

    public const CONFLICT = 'database_conflict';

    public const ADMIN_UNAVAILABLE = 'database_admin_unavailable';

    public const OPERATION_FAILED = 'database_operation_failed';

    public const INVALID_RUNTIME_COMMAND = 'invalid_runtime_command';

    public const UNSUPPORTED_RUNTIME_COMMAND = 'unsupported_runtime_command';

    /** Codes the user can act on. */
    private const USER_FIXABLE = [self::EXTENSION_NOT_ALLOWED];

    /**
     * `command`, `code`, `user_fixable`, `summary`, and `failed_at` when the
     * latest command of the database failed or expired; null otherwise.
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
            'user_fixable' => in_array($code, self::USER_FIXABLE, true),
            'summary' => self::summary($latest->type, $code),
            'failed_at' => $latest->failed_at?->toAtomString(),
        ];
    }

    private static function summary(AgentCommandType $type, string $code): string
    {
        return match ($code) {
            self::EXTENSION_NOT_ALLOWED => 'Extension ini tidak termasuk daftar yang diizinkan. Pilih pgcrypto, citext, pg_trgm, unaccent, hstore, atau vector.',
            self::EXTENSION_UNAVAILABLE => 'Extension ini belum tersedia di server database. Operator Sakala perlu memasangnya di server dulu.',
            self::ADMIN_UNAVAILABLE => 'Server database sedang tidak bisa dihubungi. Coba lagi beberapa saat lagi.',
            ExpireAgentCommandsAction::CODE_LEASE_EXPIRED, ExpireAgentCommandsAction::CODE_QUEUE_EXPIRED => 'Server database tidak menyelesaikan permintaan ini tepat waktu. Coba lagi beberapa saat lagi.',
            default => match ($type) {
                AgentCommandType::CreateDatabase => 'Server database gagal membuat database ini. Hapus lalu buat lagi; kalau terulang, hubungi operator Sakala.',
                default => 'Server database gagal menjalankan permintaan terakhir. Coba lagi; kalau terulang, hubungi operator Sakala.',
            },
        };
    }
}
