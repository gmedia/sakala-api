<?php

declare(strict_types=1);

test('nginx resolves the php upstream while running, not once at startup', function (): void {
    $config = (string) file_get_contents(base_path('docker/nginx/default.conf'));

    expect($config)
        ->toContain('resolver 127.0.0.11')
        ->toMatch('/^\s*fastcgi_pass\s+\$\w+;/m')
        ->not->toMatch('/^\s*fastcgi_pass\s+[^$\s]/m');
});
