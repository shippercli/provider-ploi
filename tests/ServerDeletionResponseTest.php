<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderPloi\PloiApiClient;
use ShipperCli\ProviderPloi\PloiServerLifecycleClient;

it('rejects missing foreign or contradictory deletion acknowledgements', function (array $body): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{"data":{"id":7,"status":"active"}}'), new Response(200, [], ($body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)))]))]);
    $client = new PloiServerLifecycleClient(new PloiApiClient('synthetic', $http));
    expect(fn () => $client->delete(7))->toThrow(RuntimeException::class, 'deletion acknowledgement');
})->with([
    [[]], [['message' => 'ok']], [['data' => ['id' => 8, 'status' => 'destroying']]],
    [['data' => ['id' => 7.5, 'status' => 'destroying']]],
    [['data' => ['id' => 7, 'status' => 'active']]],
    [['data' => ['id' => 7, 'status' => 'destroying'], 'success' => false]],
    [['data' => ['id' => 7, 'status' => 'destroying'], 'error' => 'synthetic-secret']],
    [['data' => ['id' => 7, 'status' => 'destroying'], 'message' => 'Failed: synthetic-secret']],
    [['data' => ['id' => 7, 'status' => 'destroying'], 'errors' => ['synthetic-secret']]],
]);

it('accepts the documented destroying acknowledgement without replaying delete', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":{"id":7,"status":"active"}}'), new Response(200, [], '{"data":{"id":"7","status":"destroying"}}'), new Response(404, [], '{}')]));
    $stack->push(Middleware::history($history));
    $client = new PloiServerLifecycleClient(new PloiApiClient('synthetic', new Client(['handler' => $stack])));
    $client->delete(7);
    expect(array_map(fn ($row) => $row['request']->getMethod(), $history))->toBe(['GET', 'DELETE', 'GET']);
});

function serverDeletionClient(array $responses, array &$history, int $wait = 0): PloiServerLifecycleClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new PloiServerLifecycleClient(new PloiApiClient('synthetic', new Client(['handler' => $stack])), $wait, static fn () => null);
}

it('does not report completed deletion while the server remains visible', function (): void {
    $history = [];
    $client = serverDeletionClient([
        new Response(200, [], '{"data":{"id":7,"status":"active"}}'),
        new Response(200, [], '{"data":{"id":7,"status":"destroying"}}'),
        new Response(200, [], '{"data":{"id":7,"status":"destroying"}}'),
    ], $history);
    expect(fn () => $client->delete(7))->toThrow(RuntimeException::class, 'remains unconfirmed');
    expect(array_map(fn ($row) => $row['request']->getMethod(), $history))->toBe(['GET', 'DELETE', 'GET']);
});

it('waits for already-destroying servers without replaying delete', function (): void {
    $history = [];
    $client = serverDeletionClient([
        new Response(200, [], '{"data":{"id":7,"status":"destroying"}}'),
        new Response(200, [], '{"data":{"id":7,"status":"destroying"}}'),
        new Response(404, [], '{}'),
    ], $history, 1);
    $client->delete(7);
    expect(array_map(fn ($row) => $row['request']->getMethod(), $history))->toBe(['GET', 'GET', 'GET']);
});

it('treats an already-absent server as idempotent completion without delete', function (): void {
    $history = [];
    $client = serverDeletionClient([new Response(404, [], '{}')], $history);
    $client->delete(7);
    expect(array_map(fn ($row) => $row['request']->getMethod(), $history))->toBe(['GET']);
});

it('propagates verification failures instead of treating them as absence', function (int $status, string $body, string $message): void {
    $history = [];
    $client = serverDeletionClient([
        new Response(200, [], '{"data":{"id":7,"status":"active"}}'),
        new Response(200, [], '{"data":{"id":7,"status":"destroying"}}'),
        new Response($status, [], $body),
    ], $history);
    expect(fn () => $client->delete(7))->toThrow(Exception::class, $message);
    expect(array_map(fn ($row) => $row['request']->getMethod(), $history))->toBe(['GET', 'DELETE', 'GET']);
})->with([
    [403, '{}', 'HTTP 403'], [500, '{}', 'HTTP 500'],
    [200, '{"data":{"id":8,"status":"destroying"}}', 'invalid response'],
]);


it('does not delete after an unreadable or contradictory initial read', function (int $status, string $body): void {
    $history = [];
    $client = serverDeletionClient([new Response($status, [], $body)], $history);
    expect(fn () => $client->delete(7))->toThrow(Exception::class);
    expect(array_map(fn ($row) => $row['request']->getMethod(), $history))->toBe(['GET']);
})->with([
    [403, '{}'], [500, '{}'], [200, '{}'],
    [200, '{"data":{"id":7,"status":"active"},"errors":["synthetic-error"]}'],
]);
