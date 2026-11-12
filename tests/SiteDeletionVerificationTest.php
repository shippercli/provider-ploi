<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderPloi\PloiApiClient;
use ShipperCli\ProviderPloi\PloiProvider;

test('site deletion requires safe acknowledgement and fresh absence evidence', function (string $case): void {
    $history = [];
    $before = ['data' => $case === 'already-absent' ? [] : [['id' => 55, 'domain' => 'fixture.test']], 'meta' => ['last_page' => 1]];
    $ack = match ($case) {
        'error' => ['message' => 'Deleted', 'errors' => ['synthetic-secret']],
        'false' => ['message' => 'Deleted', 'success' => false],
        'missing-message' => new stdClass,
        'failed-message' => ['message' => 'failed synthetic-secret'],
        default => ['message' => 'Site has been deleted'],
    };
    $data = match ($case) {
        'visible' => [['id' => 55, 'domain' => 'fixture.test']],
        'recreated' => [['id' => 56, 'domain' => 'FIXTURE.test']],
        'renamed' => [['id' => 55, 'domain' => 'other.test']],
        default => [],
    };
    $after = $case === 'unreadable' ? new stdClass : ['data' => $data, 'meta' => ['last_page' => 1]];
    $stack = HandlerStack::create(new MockHandler(array_map(fn ($body) => new Response(200, [], json_encode($body)), [$before, $ack, $after])));
    $stack->push(Middleware::history($history));
    $server = (new PloiApiClient('synthetic', new Client(['handler' => $stack])))->server(123);
    $failure = null;
    try {
        (new ReflectionMethod(PloiProvider::class, 'deleteSiteAndVerify'))->invoke(new PloiProvider, $server, 55);
    } catch (RuntimeException $exception) { $failure = $exception; }
    if (in_array($case, ['absent', 'already-absent'], true)) {
        expect($failure)->toBeNull();
    } else {
        expect($failure)->toBeInstanceOf(RuntimeException::class)
            ->and($failure->getMessage())->not->toContain('synthetic-secret');
    }
    $methods = array_map(fn ($entry) => $entry['request']->getMethod(), $history);
    expect($methods)->toBe($case === 'already-absent' ? ['GET'] :
        (in_array($case, ['error', 'false', 'missing-message', 'failed-message'], true) ? ['GET', 'DELETE'] : ['GET', 'DELETE', 'GET']));
})->with(['absent', 'already-absent', 'visible', 'recreated', 'renamed', 'unreadable', 'error', 'false', 'missing-message', 'failed-message']);
