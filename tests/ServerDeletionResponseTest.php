<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderPloi\PloiApiClient;
use ShipperCli\ProviderPloi\PloiServerLifecycleClient;

it('rejects missing foreign or contradictory deletion acknowledgements', function (array $body): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], ($body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR)))]))]);
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
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":{"id":"7","status":"destroying"}}')]));
    $stack->push(Middleware::history($history));
    $client = new PloiServerLifecycleClient(new PloiApiClient('synthetic', new Client(['handler' => $stack])));
    $client->delete(7);
    expect($history)->toHaveCount(1)->and($history[0]['request']->getMethod())->toBe('DELETE');
});
