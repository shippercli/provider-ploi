<?php

use ShipperCli\ProviderPloi\PloiProvider;

test('status resolves later-page sites and refuses unreadable site inventories', function (string $mode): void {
    $client = \Mockery::mock(\Ploi\Ploi::class);
    $server = \Mockery::mock(\Ploi\Resources\Server::class);
    $sites = \Mockery::mock(\Ploi\Resources\Site::class);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($sites);
    foreach ([1, 2] as $page) {
        $response = \Mockery::mock(\Ploi\Http\Response::class);
        $data = $page === 1 ? [] : [(object) ['id' => 55, 'domain' => 'preview.example.com']];
        if ($mode === 'invalid-identity' && $page === 2) $data = [(object) ['domain' => 'preview.example.com']];
        $payload = $mode === 'unreadable' && $page === 2 ? (object) [] : (object) ['data' => $data, 'meta' => (object) ['last_page' => 2]];
        $response->shouldReceive('getJson')->once()->andReturn($payload);
        $sites->shouldReceive('page')->with($page, 50)->once()->andReturn($response);
    }
    $provider = new class($client) extends PloiProvider {
        public function __construct(private \Ploi\Ploi $fakeClient) { parent::__construct(); }
        protected function getClient(): \Ploi\Ploi { return $this->fakeClient; }
        protected function resolveServerIdForProfile(object $project, object $profile, bool $createIfMissing = false): int { return 123; }
    };
    $profile = new class { public function get(string $key): mixed { return $key === 'domain' ? 'preview.example.com' : null; } };
    if ($mode === 'valid') {
        $status = $provider->status(new stdClass, $profile);
        expect($status['state'])->toBe('deployed')->and($status['site']->id)->toBe(55);
    } else {
        expect(fn () => $provider->status(new stdClass, $profile))->toThrow(RuntimeException::class);
    }
    \Mockery::close();
})->with(['valid', 'unreadable', 'invalid-identity']);

function paginationResource(array $pages): object
{
    return new class($pages) {
        public array $requested = [];
        public function __construct(private array $pages) {}
        public function page(int $page, int $perPage): object {
            $this->requested[] = $page;
            return new class($this->pages[$page - 1]) {
                public function __construct(private mixed $value) {}
                public function getJson(): mixed { return $this->value; }
            };
        }
    };
}

test('malformed Ploi pages fail instead of silently losing inventory', function (): void {
    $invalid = [
        (object) ['data' => [], 'meta' => (object) []],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 0]],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 1.5]],
        (object) ['data' => [], 'meta' => (object) ['last_page' => '1e2']],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 1, 'current_page' => 2]],
        (object) ['data' => ['invalid'], 'meta' => (object) ['last_page' => 1]],
        (object) ['data' => ['named' => (object) ['id' => 1]], 'meta' => (object) ['last_page' => 1]],
    ];
    $method = new ReflectionMethod(PloiProvider::class, 'paginatedData');
    foreach ($invalid as $payload) {
        try {
            $method->invoke(new PloiProvider, paginationResource([$payload]));
            throw new LogicException('Invalid inventory was accepted.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('Ploi returned');
        }
    }
});

test('later-page failures discard partial Ploi inventory', function (): void {
    $resource = paginationResource([
        (object) ['data' => [(object) ['id' => 1]], 'meta' => (object) ['last_page' => 2]],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 1]],
    ]);
    expect(fn () => (new ReflectionMethod(PloiProvider::class, 'paginatedData'))->invoke(new PloiProvider, $resource))
        ->toThrow(RuntimeException::class, 'invalid pagination metadata');
    expect($resource->requested)->toBe([1, 2]);
});

test('valid complete Ploi pagination preserves every resource', function (): void {
    $first = (object) ['id' => 1];
    $second = (object) ['id' => 2];
    $resource = paginationResource([
        (object) ['data' => [$first], 'meta' => (object) ['last_page' => 2, 'current_page' => 1]],
        (object) ['data' => [$second], 'meta' => (object) ['last_page' => 2, 'current_page' => 2]],
    ]);
    expect((new ReflectionMethod(PloiProvider::class, 'paginatedData'))->invoke(new PloiProvider, $resource))->toBe([$first, $second]);
    expect($resource->requested)->toBe([1, 2]);
});
