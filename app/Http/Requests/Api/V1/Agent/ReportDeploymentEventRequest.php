<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Data\Agent\DeploymentEventReportItemData;
use App\Data\Agent\ReportDeploymentEventData;
use App\Enums\DeploymentEventLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ReportDeploymentEventRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $batchProvided = $this->exists('events');
        $singularProvided = $this->hasAny([
            'level',
            'type',
            'message',
            'metadata',
            'occurred_at',
        ]);

        if ($batchProvided && $singularProvided) {
            $this->merge(['mixed_report_formats' => true]);
        }

        if (! $batchProvided && $singularProvided) {
            $this->merge([
                'events' => [$this->only([
                    'level',
                    'type',
                    'message',
                    'metadata',
                    'occurred_at',
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
            'events' => [
                'required',
                'array',
                'min:1',
                'max:'.(int) config('sakala.pilot_limits.log_bounds.max_batch_lines', 500),
            ],
            'events.*' => ['required', 'array'],
            'events.*.level' => ['required', 'string', Rule::enum(DeploymentEventLevel::class)],
            'events.*.type' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D',
            ],
            'events.*.message' => [
                'required',
                'string',
                'max:'.(int) config('sakala.pilot_limits.log_bounds.max_line_length', 4096),
            ],
            'events.*.metadata' => ['sometimes', 'nullable', 'array'],
            'events.*.occurred_at' => ['required', 'date'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
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

    public function toData(): ReportDeploymentEventData
    {
        /** @var list<array<string, mixed>> $events */
        $events = $this->validated('events');

        /** @var list<DeploymentEventReportItemData> $items */
        $items = collect($events)
            ->map(function (array $event): DeploymentEventReportItemData {
                /** @var array<string, mixed>|null $metadata */
                $metadata = Arr::get($event, 'metadata');

                return new DeploymentEventReportItemData(
                    level: DeploymentEventLevel::from((string) $event['level']),
                    type: (string) $event['type'],
                    message: (string) $event['message'],
                    metadata: $metadata,
                    occurredAt: CarbonImmutable::parse((string) $event['occurred_at']),
                );
            })
            ->values()
            ->all();

        $idempotencyKey = $this->header('Idempotency-Key');

        return new ReportDeploymentEventData(
            items: $items,
            idempotencyKey: $idempotencyKey === null || trim($idempotencyKey) === ''
                ? null
                : trim($idempotencyKey),
            requestBytes: strlen($this->getContent()),
        );
    }
}
