<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderPloi\PloiApiClient;
use ShipperCli\ProviderPloi\PloiProvider;

it('uses the protected adapter for production SDK resources and rotated tokens', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"data":[{"id":12}]}'),
        new Response(200, [], '{"data":[{"id":13}]}'),
    ]));
    $stack->push(Middleware::history($history));
    $sdk = new PloiApiClient('first-synthetic-token', new Client([
        'handler' => $stack, 'base_uri' => 'https://ploi.io/api/',
        'allow_redirects' => true, 'http_errors' => true, 'timeout' => 99,
    ]));
    expect($sdk->server(7)->sites()->get()->getData()[0]->id)->toBe(12);
    $sdk->setApiToken('second-synthetic-token');
    expect($sdk->server(7)->sites()->get()->getData()[0]->id)->toBe(13)
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer first-synthetic-token')
        ->and($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer second-synthetic-token')
        ->and((string) $history[0]['request']->getUri())->toBe('https://ploi.io/api/servers/7/sites?page=1')
        ->and($history[0]['options']['allow_redirects'])->toBeFalse()
        ->and($history[0]['options']['http_errors'])->toBeFalse()
        ->and($history[0]['options']['timeout'])->toBe(30);
    $provider = new PloiProvider(['api_key' => 'synthetic']);
    expect((new ReflectionMethod($provider, 'getClient'))->invoke($provider))->toBeInstanceOf(PloiApiClient::class);
});

it('preserves SDK HTTP exception classes without echoed bodies', function (int $status, string $class): void {
    $stack = HandlerStack::create(new MockHandler([new Response($status, [], 'synthetic-secret')]));
    $sdk = new PloiApiClient('synthetic', new Client(['handler' => $stack]));
    $failure = null;
    try { $sdk->makeAPICall('fixture'); } catch (Throwable $exception) { $failure = $exception; }
    expect($failure)->toBeInstanceOf($class)
        ->and($failure->getMessage())->toContain('HTTP '.$status)->not->toContain('synthetic-secret');
})->with([
    [401, \Ploi\Exceptions\Http\Unauthenticated::class],
    [404, \Ploi\Exceptions\Http\NotFound::class],
    [405, \Ploi\Exceptions\Http\NotAllowed::class],
    [422, \Ploi\Exceptions\Http\NotValid::class],
    [429, \Ploi\Exceptions\Http\TooManyAttempts::class],
    [500, \Ploi\Exceptions\Http\InternalServerError::class],
    [503, \Ploi\Exceptions\Http\PerformingMaintenance::class],
]);

it('keeps underlying transport error text out of diagnostics', function (): void {
    $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.test');
    $stack = HandlerStack::create(new MockHandler([new \GuzzleHttp\Exception\ConnectException('synthetic-secret', $request)]));
    $sdk = new PloiApiClient('synthetic-token', new Client(['handler' => $stack]));
    $failure = null;
    try { $sdk->makeAPICall('fixture'); } catch (RuntimeException $exception) { $failure = $exception; }
    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure->getMessage())->toContain('transport failed')->not->toContain('synthetic-secret')
        ->and($failure->getPrevious())->toBeNull();
});

it('rejects malformed or nonobject JSON before SDK decoding', function (string $body, string $method): void {
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
    $sdk = new PloiApiClient('synthetic', new Client(['handler' => $stack]));
    $failure = null;
    try { $sdk->makeAPICall('fixture', $method); } catch (Throwable $exception) { $failure = $exception; }
    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure->getMessage())->toBe('Ploi API returned invalid JSON.')
        ->and($failure->getPrevious())->toBeNull();
})->with(['null', '42', 'true', '"synthetic-secret"', '[]', '[{"id":1}]', '{"error":"synthetic-secret"', '{"value":"'."\xff".'"}'])
    ->with(['get', 'post', 'patch', 'delete']);

it('preserves documented empty deletion bodies and HTTP no-content responses', function (string $method, int $status): void {
    $stack = HandlerStack::create(new MockHandler([new Response($status)]));
    $sdk = new PloiApiClient('synthetic', new Client(['handler' => $stack]));
    expect($sdk->makeAPICall('fixture', $method)->getJson())->toBeNull();
})->with([['delete', 200], ['delete', 204], ['post', 204], ['patch', 204]]);

it('does not accept empty successful JSON reads', function (): void {
    $stack = HandlerStack::create(new MockHandler([new Response(200)]));
    $sdk = new PloiApiClient('synthetic', new Client(['handler' => $stack]));
    expect(fn () => $sdk->makeAPICall('fixture'))->toThrow(RuntimeException::class, 'invalid JSON');
});

it('preserves validated nonseekable response bytes for SDK decoding', function (): void {
    $body = new \GuzzleHttp\Psr7\NoSeekStream(\GuzzleHttp\Psr7\Utils::streamFor('{"data":{"id":123}}'));
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body)]));
    $sdk = new PloiApiClient('synthetic', new Client(['handler' => $stack]));
    expect($sdk->makeAPICall('fixture')->getJson()->data->id)->toBe(123);
});
