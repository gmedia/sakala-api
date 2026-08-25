<?php

declare(strict_types=1);

namespace App\Data\Feedback;

use App\Enums\FeedbackCategory;

final readonly class SubmitFeedbackData
{
    public function __construct(
        public FeedbackCategory $category,
        public string $message,
        public ?string $projectId = null,
        public ?string $deploymentId = null,
        public bool $consent = false,
    ) {}
}
