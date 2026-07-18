<?php

declare(strict_types=1);

namespace App\Support\Slug;

use Illuminate\Support\Str;

class GenerateSlug
{
    public static function fromString(string $name): string
    {
        // Normalisasi slug nama ke format slug
        $slug = Str::slug($name);

        // Fallback untuk karakter Unicode
        if (empty($slug)) {
            $slug = 'project-'.Str::lower(Str::random(6));
        }

        // Batasi panjang slug hingga 50 karakter
        return Str::limit($slug, 50, '');
    }
}
