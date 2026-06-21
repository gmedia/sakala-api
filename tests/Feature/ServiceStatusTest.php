<?php

declare(strict_types=1);

test('the versioned API exposes service status', function () {
    $this->getJson('/api/v1')
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'service' => 'Sakala API',
                'status' => 'ok',
                'api_version' => 'v1',
            ],
        ]);
});

test('unknown API routes use a JSON response', function () {
    $this->getJson('/api/v1/unknown')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});

test('the OpenAPI document describes the versioned service status endpoint', function () {
    config()->set('scramble.enabled', true);

    $this->get('/docs/api')
        ->assertOk();

    $this->getJson('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('info.title', 'Sakala API')
        ->assertJsonStructure([
            'paths' => [
                '/v1' => ['get'],
            ],
        ]);
});
