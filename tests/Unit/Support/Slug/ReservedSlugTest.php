<?php

declare(strict_types=1);

use App\Support\Slug\ReservedSlug;

it('Return False untuk slug yang tidak terdaftar', function () {
    $validator = new ReservedSlug(['api']);
    expect($validator->isReserved('my-mine-gwe'))
        ->toBeFalse();
});

it('Return True untuk slug yang terdaftar', function () {
    $validator = new ReservedSlug(['api']);
    expect($validator->isReserved('api'))
        ->toBeTrue();
});
