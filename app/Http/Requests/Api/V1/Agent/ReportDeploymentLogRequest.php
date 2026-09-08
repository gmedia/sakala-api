<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Data\Agent\DeploymentLogReportItemData;
use App\Data\Agent\ReportDeploymentLogData;
use App\Enums\LogStream;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ReportDeploymentLogRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $batchProvided = $this->exists('logs');
        $singularProvided = $this->hasAny([
            'stream',
            'message',
            'recorded_at',
        ]);

        if ($batchProvided && $singularProvided) {
            $this->merge(['mixed_report_formats' => true]);
        }

        if (! $batchProvided && $singularProvided) {
            $this->merge([
                'logs' => [$this->only([
                    'stream',
                    'message',
                    'recorded_at',
                ])],
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mixed_report_formats' => ['prohibited'],
            'logs' => [
                'required',
                'array',
                'min:1',
                'max:'.(int) config('sakala.pilot_limits.log_bounds.max_batch_lines', 500),
            ],
            'logs.*' => ['required', 'array'],
            'logs.*.stream' => ['required', 'string', Rule::enum(LogStream::class)],
            'logs.*.message' => [
                'required',
                'string',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $maxBytes = (int) config('sakala.pilot_limits.log_bounds.max_line_length', 4096);

                    if (is_string($value) && strlen($value) > $maxBytes) {
                        $fail("The {$attribute} field must not be greater than {$maxBytes} bytes.");
                    }
                },
                'not_regex:/[\r\n]/',
            ],
            'logs.*.recorded_at' => ['required', 'date'],
        ];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $key = $this->header('Idempotency-Key');

            if ($key !== null && mb_strlen($key) > 191) {
                $validator->errors()->add(
                    'Idempotency-Key',
                    'The Idempotency-Key header may not be greater than 191 characters.',
                );
            }
        }];
    }

    public function toData(): ReportDeploymentLogData
    {
        /** @var list<array<string, mixed>> $logs */
        $logs = $this->validated('logs');

        /** @var list<DeploymentLogReportItemData> $items */
        $items = collect($logs)
            ->map(function (array $log): DeploymentLogReportItemData {
                return new DeploymentLogReportItemData(
                    stream: LogStream::from((string) $log['stream']),
                    message: (string) $log['message'],
                    recordedAt: CarbonImmutable::parse((string) $log['recorded_at']),
                );
            })
            ->values()
            ->all();

        $idempotencyKey = $this->header('Idempotency-Key');

        return new ReportDeploymentLogData(
            items: $items,
            idempotencyKey: $idempotencyKey === null || trim($idempotencyKey) === ''
                ? null
                : trim($idempotencyKey),
            requestBytes: strlen($this->getContent()),
        );
    }
}
