<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Schedule;

use App\Data\Schedule\ProjectScheduleData;
use App\Models\Project;
use App\Models\ProjectProcess;
use App\Models\ProjectSchedule;
use App\Services\Schedule\SchedulePolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A schedule of a project (ADR-022). The command follows the release
 * command's rule; the minimum interval of the workspace is checked when
 * saving (SaveProjectScheduleAction), with the final expression and timezone.
 */
class StoreProjectScheduleRequest extends FormRequest
{
    /** Fields a member may send. */
    public const FIELDS = ['name', 'command', 'cron_expression', 'timezone', 'timeout_seconds', 'enabled'];

    /** Longest cron expression, in characters. */
    public const MAX_EXPRESSION_LENGTH = 100;

    /** Longest timezone identifier, in characters. */
    public const MAX_TIMEZONE_LENGTH = 64;

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ProjectSchedule::MAX_NAME_LENGTH],
            /** Runs as `sh -c <command>` in a one-off container of the live image. One line of 1 to 1000 characters. */
            'command' => ['required', 'string', 'max:'.ProjectProcess::MAX_COMMAND_LENGTH, 'not_regex:/[\\x00-\\x1F\\x7F]/'],
            /**
             * Standard five-field cron expression (minute, hour, day of month, month, day of week), read in
             * `timezone`, for example `30 2 * * *`. Two slots may not be closer than the workspace's
             * `min_schedule_interval_minutes`.
             */
            'cron_expression' => [
                'required',
                'string',
                'max:'.self::MAX_EXPRESSION_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! SchedulePolicy::isValidExpression($value)) {
                        $fail('Ekspresi cron harus lima bagian: menit, jam, tanggal, bulan, dan hari, misalnya 30 2 * * * untuk setiap hari pukul 02.30.');
                    }
                },
            ],
            /** Timezone identifier the expression is read in, for example `Asia/Jakarta` (the default, WIB). */
            'timezone' => [
                'sometimes',
                'string',
                'max:'.self::MAX_TIMEZONE_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! SchedulePolicy::isValidTimezone($value)) {
                        $fail('Zona waktu tidak dikenal. Pakai nama seperti Asia/Jakarta.');
                    }
                },
            ],
            /** Seconds a run may take before it is stopped, 1 to 600; default 300. */
            'timeout_seconds' => ['sometimes', 'integer', 'between:1,'.SchedulePolicy::MAX_TIMEOUT_SECONDS],
            /** Whether slots run; default true. Only enabled schedules count towards `max_schedules`. */
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'command.not_regex' => 'Perintah jadwal harus satu baris. Gabungkan beberapa langkah dengan && atau pindahkan ke script di repository.',
            'timeout_seconds.between' => 'Batas waktu run antara :min dan :max detik.',
        ];
    }

    public function toData(): ProjectScheduleData
    {
        $validated = $this->validated();

        return new ProjectScheduleData(
            name: isset($validated['name']) ? $this->string('name')->toString() : null,
            command: isset($validated['command']) ? $this->string('command')->trim()->toString() : null,
            cronExpression: isset($validated['cron_expression']) ? preg_replace('/\s+/', ' ', $this->string('cron_expression')->trim()->toString()) : null,
            timezone: isset($validated['timezone']) ? $this->string('timezone')->toString() : null,
            timeoutSeconds: isset($validated['timeout_seconds']) ? $this->integer('timeout_seconds') : null,
            enabled: array_key_exists('enabled', $validated) ? $this->boolean('enabled') : null,
            provided: array_values(array_intersect(self::FIELDS, array_keys($validated))),
        );
    }
}
