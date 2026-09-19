<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @var array{path: string, payload: array<string, mixed>, token?: string} $job */
$job = json_decode(base64_decode($argv[1], true), true, 512, JSON_THROW_ON_ERROR);
$server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

if (isset($job['token'])) {
    $server['HTTP_AUTHORIZATION'] = 'Bearer '.$job['token'];
}

$request = Request::create($job['path'], 'POST', [], [], [], $server, json_encode($job['payload'], JSON_THROW_ON_ERROR));
$response = $app->make(Kernel::class)->handle($request);

echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)]);
