<?php

declare(strict_types=1);

namespace App\Data\Profile;

use Illuminate\Http\UploadedFile;

final readonly class ProfileData
{
    public function __construct(
        public ?string $name = null,
        public ?string $username = null,
        public ?UploadedFile $avatar = null,
    ) {}
}
