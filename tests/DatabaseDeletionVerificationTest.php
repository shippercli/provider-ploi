<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderPloi\PloiApiClient;
use ShipperCli\ProviderPloi\PloiProvider;

test('database deletion requires a readable fresh inventory proving absence', function (string $case): void {
    $history = [];
    $ack = $case === 'error' ? '{"errors":["synthetic-secret"]}' : '';
    $data = match ($case) {
        'visible' => [['id' => 9, 'name' => 'fixture']],
        'recreated' => [['id' => 10, 'name' => 'fixture']],
        'renamed' => [['id' => 9, 'name' => 'other']],
        default => [],
    };
    $inventory = $case === 'unreadable' ? '{}' : json_encode(['data' => $data, 'meta' => ['last_page' => 1]]);
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], $ack), new Response(200, [], $inventory)]));
    $stack->push(Middleware::history($history));
    $server = (new PloiApiClient('synthetic', new Client(['handler' => $stack])))->server(123);
    $method = new ReflectionMethod(PloiProvider::class, 'deleteDatabaseAndVerify');
    if ($case === 'absent') {
        $method->invoke(new PloiProvider, $server, 9, 'fixture');
        expect(array_map(fn ($entry) => $entry['request']->getMethod(), $history))->toBe(['DELETE', 'GET']);
    } else {
        expect(fn () => $method->invoke(new PloiProvider, $server, 9, 'fixture'))->toThrow(RuntimeException::class);
        expect(count($history))->toBe($case === 'error' ? 1 : 2);
    }
})->with(['absent', 'visible', 'recreated', 'renamed', 'unreadable', 'error']);
