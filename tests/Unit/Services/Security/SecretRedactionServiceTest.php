<?php

declare(strict_types=1);

use App\Services\Security\SecretRedactionService;

test('redacts credentials embedded in basic auth urls', function (): void {
    $service = new SecretRedactionService;

    $redacted = $service->redactString(
        'cloning https://build-user:super-secret@example.test/repository.git',
    );

    expect($redacted)
        ->toBe('cloning https://[REDACTED]@example.test/repository.git')
        ->not->toContain('super-secret');
});
