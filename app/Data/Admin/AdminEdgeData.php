<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Data\Edge\EdgeHealthData;
use App\Models\EdgeToken;

/** One Edge instance in the admin Edge list: its token, its health now, and the revision it should serve. */
final readonly class AdminEdgeData
{
    public function __construct(
        public EdgeToken $token,
        public EdgeHealthData $health,
        public string $currentRevision,
    ) {}
}
