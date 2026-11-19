<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EnvironmentVariableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['project_id', 'key', 'encrypted_value', 'is_secret'])]
#[Hidden(['encrypted_value'])]
class EnvironmentVariable extends Model
{
    /** @use HasFactory<EnvironmentVariableFactory> */
    use HasFactory;

    /** Longest variable name the API accepts. */
    public const MAX_KEY_LENGTH = 128;

    /** Variable names the API accepts: uppercase letters, digits, and `_`, not starting with a digit. */
    public const KEY_PATTERN = '/^[A-Z_][A-Z0-9_]*$/';

    /** Whether a name is one a project variable may have. */
    public static function isValidKey(mixed $key): bool
    {
        return is_string($key) && strlen($key) <= self::MAX_KEY_LENGTH && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'encrypted_value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }
}
