<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\UsageSignalsData;
use App\Data\Admin\UsageSignalsRequestData;
use App\Enums\UsageSignalType;
use App\Models\UsageSignalRecord;
use Carbon\CarbonImmutable;

final class GetUsageSignalsAction
{
    public function handle(UsageSignalsRequestData $data): UsageSignalsData
    {
        $now = now()->toImmutable();

        $from = $data->from ?? $now->startOfMonth();
        $to = $data->to ?? $now;

        $records = UsageSignalRecord::query()
            ->where('collected_at', '>=', $from)
            ->where('collected_at', '<', $to)
            ->orderBy('collected_at', 'desc')
            ->get();

        /** @var list<array{signal_type: UsageSignalType, count: int, scope: ?string, scope_id: ?string, tags: array<string, mixed>, collected_at: CarbonImmutable}> $signals */
        $signals = [];
        foreach ($records as $record) {
            /** @var UsageSignalType $signalType */
            $signalType = $record->signal_type;

            $signals[] = [
                'signal_type' => $signalType,
                'count' => $record->count,
                'scope' => $record->scope,
                'scope_id' => $record->scope_id,
                'tags' => $record->tags ?? [],
                'collected_at' => $record->collected_at,
            ];
        }

        return new UsageSignalsData(
            from: $from,
            to: $to,
            signals: $signals,
        );
    }
}
