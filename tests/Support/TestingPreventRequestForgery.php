<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\PreventRequestForgery;

final class TestingPreventRequestForgery extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
