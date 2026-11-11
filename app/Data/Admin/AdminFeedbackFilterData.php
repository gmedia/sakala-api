<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;

final readonly class AdminFeedbackFilterData
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?string $search = null,
        public ?FeedbackStatus $status = null,
        public ?FeedbackCategory $category = null,
    ) {}
}
