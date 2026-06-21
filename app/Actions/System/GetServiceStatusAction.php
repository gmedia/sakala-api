<?php

declare(strict_types=1);

namespace App\Actions\System;

use App\Data\System\ServiceStatusData;

final class GetServiceStatusAction
{
    public function handle(): ServiceStatusData
    {
        return new ServiceStatusData(
            service: (string) config('app.name'),
            status: 'ok',
            apiVersion: 'v1',
        );
    }
}
