<?php

declare(strict_types=1);

use Illuminate\Http\Resources\Json\JsonResource;

arch('the API application remains independent from frontend and starter-kit frameworks')
    ->expect('App')
    ->not->toUse([
        'Inertia',
        'Laravel\\Fortify',
    ]);

arch('actions remain independent from the HTTP transport layer')
    ->expect('App\\Actions')
    ->not->toUse('App\\Http');

arch('API resources use the Laravel resource boundary')
    ->expect('App\\Http\\Resources')
    ->toExtend(JsonResource::class);

arch('API controllers do not validate requests inline')
    ->expect('App\\Http\\Controllers\\Api')
    ->not->toUse('Illuminate\\Support\\Facades\\Validator');
