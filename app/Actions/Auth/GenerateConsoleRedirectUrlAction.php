<?php

declare(strict_types=1);

namespace App\Actions\Auth;

final class GenerateConsoleRedirectUrlAction
{
    public function handle(): string
    {
        $origin = config('services.console.allowed_origins')[0];
        $path = config('services.console.default_redirect_path');

        return rtrim($origin, '/').'/'.ltrim($path, '/');
    }
}
