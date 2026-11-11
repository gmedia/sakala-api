<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;

final class UpdateFeedbackStatusAction
{
    public function handle(User $actor, Feedback $feedback, FeedbackStatus $status, string $reason): Feedback
    {
        return DB::transaction(function () use ($actor, $feedback, $status, $reason): Feedback {
            /** @var Feedback $locked */
            $locked = Feedback::query()->lockForUpdate()->findOrFail($feedback->id);
            $previous = $locked->status;

            if ($previous !== $status) {
                $locked->update(['status' => $status]);

                AdminAudit::record($actor, 'feedback.status_changed', $locked, $reason, [
                    'from' => $previous->value,
                    'to' => $status->value,
                ]);
            }

            return $locked->load([
                'user:id,name,username,email',
                'project' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'slug', 'deleted_at']),
            ]);
        });
    }
}
