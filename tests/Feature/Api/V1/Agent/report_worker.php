<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

$root = dirname(__DIR__, 5);
require $root.'/vendor/autoload.php';

$mode = $_SERVER['argv'][1] ?? '';
$inputPath = $_SERVER['argv'][2] ?? '';
$index = $_SERVER['argv'][3] ?? '';
$goFile = $_SERVER['argv'][4] ?? '';
$readyFile = $_SERVER['argv'][5] ?? '';

try {
    /** @var Application $app */
    $app = require $root.'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    if ($mode !== 'report') {
        throw new RuntimeException('unsupported worker mode');
    }

    /** @var array{command_id: string, agent_id: string, token: string} $input */
    $input = json_decode((string) file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
    touch($readyFile);

    $deadline = microtime(true) + 60.0;
    while (! file_exists($goFile)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('start barrier timed out');
        }

        usleep(20_000);
    }

    $payload = json_encode([
        'stream' => 'system',
        'message' => 'concurrent-log-'.$index,
        'recorded_at' => '2026-09-07T10:00:00Z',
    ], JSON_THROW_ON_ERROR);

    $request = Request::create(
        '/api/agent/v1/commands/'.$input['command_id'].'/logs',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
            'HTTP_X_AGENT_ID' => $input['agent_id'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $payload,
    );

    $response = $app->make(HttpKernel::class)->handle($request);
    echo 'HTTP_'.$response->getStatusCode()."\n";
    exit(0);
} catch (Throwable $exception) {
    echo 'WORKER_ERROR: '.$exception->getMessage()."\n";
    exit(3);
}
