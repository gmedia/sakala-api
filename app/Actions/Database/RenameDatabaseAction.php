<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Models\ManagedDatabase;
use App\Models\User;
use App\Support\Database\DatabaseAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Changes the name people see. The names on the node never change. */
final class RenameDatabaseAction
{
    public function handle(User $actor, ManagedDatabase $database, string $name): ManagedDatabase
    {
        return DB::transaction(function () use ($actor, $database, $name): ManagedDatabase {
            /** @var ManagedDatabase $locked */
            $locked = ManagedDatabase::query()->whereKey($database->id)->lockForUpdate()->firstOrFail();

            if ($locked->name === $name) {
                return $locked;
            }

            if (ManagedDatabase::query()->where('workspace_id', $locked->workspace_id)->where('name', $name)->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => __('validation.attributes.name')])]]);
            }

            $from = $locked->name;
            $locked->update(['name' => $name]);

            DatabaseAudit::record($actor, DatabaseAudit::RENAMED, $locked, ['from' => $from, 'to' => $name]);

            return $locked;
        });
    }
}
