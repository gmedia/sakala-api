<?php

declare(strict_types=1);

it('should redirect to console after successful GitHub login', function () {
    // 1. Simulate GitHub callback
    $_GET = ['code' => 'code=abc', 'state' => 'random_state'];

    // 2. Masukkan header Sanctum
    $_SERVER['HTTP_X_CSRF_TOKEN'] = 'tes_csrf_token';

    // 3. Jalankan redirect callback
    $response = callToRoute('auth.github.callback');

    expect($response[1])->toHaveHttpStatus(302);
    expect($response[2])->toContain('console.sakala.dev');

    // 4. Cek session persis-eykan
    $this->assertArrayHasKey('user_token', $_SESSION);
});
