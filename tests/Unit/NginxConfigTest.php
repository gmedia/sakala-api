<?php

declare(strict_types=1);

test('nginx resolves the php upstream while running, not once at startup', function (): void {
    $config = (string) file_get_contents(base_path('docker/nginx/default.conf'));

    // A shared `upstream ... resolve` block is avoided on purpose: a re-resolve
    // during a failing connection stops nginx from retrying (504).
    expect($config)
        ->toMatch('/^\s*resolver\s+127\.0\.0\.11\s+valid=1s\b/m')
        ->toMatch('/^\s*fastcgi_pass\s+\$\w+;/m')
        ->not->toMatch('/^\s*fastcgi_pass\s+[^$\s]/m')
        ->not->toMatch('/^\s*upstream\s/m');
});

test('nginx fails over to another api container instead of waiting on a stopped one', function (): void {
    $config = (string) file_get_contents(base_path('docker/nginx/default.conf'));

    // A POST waiting in a stopping container's accept queue is reset unread,
    // so it may be retried, but only early: the 2-second limit keeps a slow
    // POST that timed out from being replayed.
    expect($config)
        ->toMatch('/^\s*fastcgi_connect_timeout\s+1s;/m')
        ->toMatch('/^\s*fastcgi_next_upstream\s+error\s+timeout\s+non_idempotent;/m')
        ->toMatch('/^\s*fastcgi_next_upstream_timeout\s+2s;/m');
});

test('php-fpm finishes in-flight requests when the container stops', function (): void {
    $config = (string) file_get_contents(base_path('docker/php/php-fpm.d/zz-sakala.conf'));
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));

    expect($config)->toMatch('/^process_control_timeout\s*=\s*25s$/m')
        ->and($dockerfile)->toContain('COPY docker/php/php-fpm.d/zz-sakala.conf /usr/local/etc/php-fpm.d/zz-sakala.conf');
});
