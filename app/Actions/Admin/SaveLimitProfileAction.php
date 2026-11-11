<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\LimitProfileData;
use App\Models\LimitProfile;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * Creates a limit profile, or changes one. A change applies at once to every
 * workspace running under the profile; limits already reached are not undone,
 * a lower limit only stops new projects and deployments.
 */
final class SaveLimitProfileAction
{
    public function create(User $actor, LimitProfileData $data): LimitProfile
    {
        return DB::transaction(function () use ($actor, $data): LimitProfile {
            $profile = LimitProfile::query()->create($data->attributes);

            AdminAudit::record($actor, 'limit_profile.created', $profile, $data->reason, ['values' => $data->attributes]);

            return $profile;
        });
    }

    public function update(User $actor, LimitProfile $profile, LimitProfileData $data): LimitProfile
    {
        return DB::transaction(function () use ($actor, $profile, $data): LimitProfile {
            /** @var LimitProfile $locked */
            $locked = LimitProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $locked->fill($data->attributes);
            $changes = $locked->getDirty();

            if ($changes !== []) {
                $before = array_intersect_key($locked->getOriginal(), $changes);
                $locked->save();

                AdminAudit::record($actor, 'limit_profile.updated', $locked, $data->reason, [
                    'from' => $before,
                    'to' => $changes,
                ]);
            }

            return $locked->loadCount('workspaces');
        });
    }
}
