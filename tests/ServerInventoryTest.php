<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderPloi\PloiApiClient;
use ShipperCli\ProviderPloi\PloiServerLifecycleClient;

function serverInventoryClient(array $pages): PloiServerLifecycleClient
{
    $responses = array_map(fn (array $page) => new Response(200, [], json_encode($page, JSON_THROW_ON_ERROR)), $pages);
    return new PloiServerLifecycleClient(new PloiApiClient('synthetic', new Client([
        'handler' => HandlerStack::create(new MockHandler($responses)),
    ])));
}

it('rejects incomplete or ambiguous server inventories', function (array $page): void {
    expect(fn () => serverInventoryClient([$page])->list())->toThrow(RuntimeException::class, 'Ploi server inventory');
})->with([
    [['data' => []]],
    [['data' => [], 'meta' => ['last_page' => 0]]],
    [['data' => [], 'meta' => ['last_page' => 1, 'total' => 2]]],
    [['data' => [], 'meta' => ['last_page' => 1, 'total' => null]]],
    [['data' => [], 'meta' => ['last_page' => 1.5]]],
    [['data' => [], 'meta' => ['last_page' => '1e2']]],
    [['data' => [], 'meta' => ['last_page' => 1, 'current_page' => 2]]],
    [['data' => ['invalid'], 'meta' => ['last_page' => 1]]],
    [['data' => [['id' => 1]], 'meta' => ['last_page' => 1]]],
    [['data' => [['id' => 1.5, 'name' => 'fixture']], 'meta' => ['last_page' => 1]]],
    [['data' => [['id' => 1, 'name' => 'fixture'], ['id' => 1, 'name' => 'other']], 'meta' => ['last_page' => 1]]],
]);

it('discards partial server inventory if a later page becomes unreadable', function (): void {
    $client = serverInventoryClient([
        ['data' => [['id' => 1, 'name' => 'first']], 'meta' => ['last_page' => 2]],
        ['data' => [], 'meta' => ['last_page' => 1]],
    ]);
    expect(fn () => $client->list())->toThrow(RuntimeException::class, 'Ploi server inventory');
});

it('returns all valid server pages without losing identities', function (): void {
    $client = serverInventoryClient([
        ['data' => [['id' => 1, 'name' => 'first']], 'meta' => ['last_page' => 2, 'current_page' => 1, 'total' => 2]],
        ['data' => [['id' => '2', 'name' => 'second']], 'meta' => ['last_page' => 2, 'current_page' => 2, 'total' => 2]],
    ]);
    $servers = $client->list();
    expect(array_column($servers, 'name'))->toBe(['first', 'second']);
});


it('refuses multiple servers with one managed discovery name', function (): void {
    $lifecycle = serverInventoryClient([['data' => [['id' => 1, 'name' => 'fixture'], ['id' => 2, 'name' => 'fixture']], 'meta' => ['last_page' => 1]]]);
    $provider = new \ShipperCli\ProviderPloi\PloiProvider([], $lifecycle);
    expect(fn () => (new ReflectionMethod($provider, 'findServerIdByName'))->invoke($provider, 'fixture'))
        ->toThrow(RuntimeException::class, 'multiple servers');
});


it('does not coerce invalid get or create server identities', function (mixed $id): void {
    expect(fn () => serverInventoryClient([['data' => ['id' => $id]]])->get(1))
        ->toThrow(RuntimeException::class, 'invalid response');
    expect(fn () => serverInventoryClient([['data' => ['id' => $id]]])->create('fixture', 42, 'region', 'plan'))
        ->toThrow(RuntimeException::class, 'invalid response');
})->with([1.5, true, '1x', '1e2', '0', null]);

it('rejects a repeated server identity across different pages', function (): void {
    $client = serverInventoryClient([
        ['data' => [['id' => 1, 'name' => 'first']], 'meta' => ['last_page' => 2]],
        ['data' => [['id' => '1', 'name' => 'second']], 'meta' => ['last_page' => 2]],
    ]);
    expect(fn () => $client->list())->toThrow(RuntimeException::class, 'duplicate identity');
});

it('rejects contradictory server inventory and creation envelopes', function (array $flags, string $operation): void {
    $page = array_merge(['data' => [['id' => 1, 'name' => 'fixture']], 'meta' => ['last_page' => 1]], $flags);
    $created = array_merge(['data' => ['id' => 123]], $flags);
    expect(fn () => $operation === 'list'
        ? serverInventoryClient([$page])->list()
        : serverInventoryClient([$created])->create('fixture', 42, 'region', 'plan'))
        ->toThrow(RuntimeException::class, 'contradictory');
})->with([
    'error' => [['error' => 'synthetic-private-error']],
    'errors' => [['errors' => ['synthetic-private-error']]],
    'success false' => [['success' => false]],
    'success string' => [['success' => 'true']],
    'success null' => [['success' => null]],
])->with(['list', 'create']);

it('preserves valid server envelopes with explicitly empty error fields', function (): void {
    $flags = ['success' => true, 'error' => null, 'errors' => []];
    $page = array_merge(['data' => [['id' => 1, 'name' => 'fixture']], 'meta' => ['last_page' => 1]], $flags);
    expect(serverInventoryClient([$page])->list())->toHaveCount(1);
    expect(serverInventoryClient([array_merge(['data' => ['id' => 123]], $flags)])
        ->create('fixture', 42, 'region', 'plan'))->toBe(123);
});
