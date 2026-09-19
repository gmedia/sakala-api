<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\DesiredWorkloadState;
use App\Enums\ReconcileWorkloadAction;

final readonly class ReconcileProjectData
{
    /**
     * @param  list<ReconcileWorkloadAction>  $actions
     */
    public function __construct(
        public string $reason,
        public DesiredWorkloadState $desiredState,
        public array $actions,
        public ?string $idempotencyKey = null,
    ) {}

    /** @return list<string> */
    public function actionValues(): array
    {
        return array_values(array_unique(array_map(
            static fn (ReconcileWorkloadAction $action): string => $action->value,
            $this->actions,
        )));
    }
}
