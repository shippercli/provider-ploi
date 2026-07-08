<?php

declare(strict_types=1);

use Mockery as m;
use Ploi\Http\Response;
use Ploi\Ploi;
use Ploi\Resources\Server;
use ShipperCli\ProviderPloi\PloiProvider;

function makePluginProject(): object
{
    return new class
    {
        public function name(): string
        {
            return 'api';
        }

        public function path(): string
        {
            return './api';
        }

        public function repository(): array
        {
            return [
                'provider' => 'github',
                'name' => 'ulties/shipper',
            ];
        }

        public function webDirectory(): string
        {
            return '/public';
        }

        public function projectRoot(): string
        {
            return '/';
        }

        public function databases(): array
        {
            return [];
        }
    };
}

function makePluginServer(array $spec = [], ?string $cleanup = null): object
{
    return new class($spec, $cleanup)
    {
        public function __construct(
            private readonly array $spec,
            private readonly ?string $cleanup,
        ) {}

        public function mode(): string
        {
            return 'create';
        }

        public function id(): ?string
        {
            return null;
        }

        public function cleanup(): ?string
        {
            return $this->cleanup;
        }

        public function ttl(): ?string
        {
            return '72h';
        }

        public function spec(): array
        {
            return $this->spec;
        }
    };
}

function makePluginProfile(?object $server = null): object
{
    return new class($server)
    {
        public function __construct(private readonly ?object $server) {}

        public function name(): string
        {
            return 'preview';
        }

        public function branch(): string
        {
            return 'feature/test';
        }

        public function get(string $key): mixed
        {
            return match ($key) {
                'domain' => 'preview.example.com',
                default => null,
            };
        }

        public function server(): ?object
        {
            return $this->server;
        }
    };
}

afterEach(function (): void {
    m::close();
});

test('provider package validates required create lifecycle fields', function (): void {
    $provider = new PloiProvider([
        'api_key' => 'token',
    ]);

    $errors = $provider->validate(
        makePluginProject(),
        makePluginProfile(makePluginServer([
            'name' => 'api-preview',
        ], 'destroy')),
    );

    expect($errors)->toContain('Ploi create server mode requires infrastructure.server.spec.credential (or provider_id/provider) as digits');
    expect($errors)->toContain('Ploi create server mode requires infrastructure.server.spec.region');
    expect($errors)->toContain('Ploi create server mode requires infrastructure.server.spec.plan (or size)');
});

test('provider package plan shows managed server lifecycle actions', function (): void {
    $provider = new PloiProvider([
        'api_key' => 'token',
    ]);

    $plan = $provider->plan(
        makePluginProject(),
        makePluginProfile(makePluginServer([
            'name' => 'api-pr-123',
            'credential' => '42',
            'region' => 'eu-west',
            'plan' => 'small',
        ], 'destroy')),
    );

    expect($plan['server_mode'])->toBe('create');
    expect($plan['server_id'])->toBeNull();
    expect($plan['actions'])->toContain('Create server: api-pr-123');
    expect($plan['actions'])->toContain('Mark created server as managed: shipper-api-preview-api-pr-123');
    expect($plan['actions'])->toContain('Cleanup policy for created server: destroy');
});

test('provider package resolves existing managed server by name', function (): void {
    $client = m::mock(Ploi::class);
    $serverResource = m::mock(Server::class);
    $response = m::mock(Response::class);

    $response->shouldReceive('getJson')->andReturn((object) [
        'data' => [
            (object) ['id' => 321, 'name' => 'shipper-api-preview-api-pr-123'],
        ],
    ]);

    $serverResource->shouldReceive('get')->once()->andReturn($response);
    $client->shouldReceive('server')->withNoArgs()->once()->andReturn($serverResource);

    $provider = new class($client) extends PloiProvider
    {
        public function __construct(private readonly Ploi $fakeClient)
        {
            parent::__construct(['api_key' => 'token']);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }

        public function resolve(object $project, object $profile, bool $createIfMissing = false): int
        {
            return $this->resolveServerIdForProfile($project, $profile, $createIfMissing);
        }
    };

    $profile = makePluginProfile(makePluginServer([
        'name' => 'api-pr-123',
        'credential' => '42',
        'region' => 'eu-west',
        'plan' => 'small',
    ], 'destroy'));

    expect($provider->resolve(makePluginProject(), $profile, true))->toBe(321);
});

test('provider package creates managed server when missing', function (): void {
    $client = m::mock(Ploi::class);
    $serverResource = m::mock(Server::class);
    $listResponse = m::mock(Response::class);
    $createResponse = m::mock(Response::class);

    $listResponse->shouldReceive('getJson')->andReturn((object) ['data' => []]);
    $createResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => (object) ['id' => 654],
    ]);

    $serverResource->shouldReceive('get')->once()->andReturn($listResponse);
    $serverResource->shouldReceive('create')
        ->once()
        ->with('shipper-api-preview-api-pr-456', 42, 'eu-west', 'small', m::type('array'))
        ->andReturn($createResponse);

    $client->shouldReceive('server')->withNoArgs()->twice()->andReturn($serverResource);

    $provider = new class($client) extends PloiProvider
    {
        public function __construct(private readonly Ploi $fakeClient)
        {
            parent::__construct(['api_key' => 'token']);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }

        public function resolve(object $project, object $profile, bool $createIfMissing = false): int
        {
            return $this->resolveServerIdForProfile($project, $profile, $createIfMissing);
        }
    };

    $profile = makePluginProfile(makePluginServer([
        'name' => 'api-pr-456',
        'credential' => '42',
        'region' => 'eu-west',
        'plan' => 'small',
        'php_version' => '8.3',
    ], 'destroy'));

    expect($provider->resolve(makePluginProject(), $profile, true))->toBe(654);
});

test('provider package deletes managed created server on destroy cleanup', function (): void {
    $client = m::mock(Ploi::class);
    $listResource = m::mock(Server::class);
    $serverResource = m::mock(Server::class);
    $listResponse = m::mock(Response::class);
    $deleteResponse = m::mock(Response::class);

    $listResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [
            (object) ['id' => 999, 'name' => 'shipper-api-preview-api-pr-999'],
        ],
    ]);
    $deleteResponse->shouldReceive('getJson')->andReturn((object) [
        'message' => 'Server deleted successfully',
    ]);

    $listResource->shouldReceive('get')->once()->andReturn($listResponse);
    $serverResource->shouldReceive('delete')->once()->andReturn($deleteResponse);

    $client->shouldReceive('server')->withNoArgs()->once()->andReturn($listResource);
    $client->shouldReceive('server')->with(999)->once()->andReturn($serverResource);

    $provider = new class($client) extends PloiProvider
    {
        public function __construct(private readonly Ploi $fakeClient)
        {
            parent::__construct(['api_key' => 'token']);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }
    };

    $result = $provider->destroy(
        makePluginProject(),
        makePluginProfile(makePluginServer([
            'name' => 'api-pr-999',
            'credential' => '42',
            'region' => 'eu-west',
            'plan' => 'small',
        ], 'destroy')),
    );

    expect($result)->toBeTrue();
});

test('provider package refuses deleting unmanaged created server', function (): void {
    $client = m::mock(Ploi::class);
    $listResource = m::mock(Server::class);
    $listResponse = m::mock(Response::class);

    $listResponse->shouldReceive('getJson')->twice()->andReturn((object) [
        'data' => [
            (object) ['id' => 777, 'name' => 'api-pr-777'],
        ],
    ]);

    $listResource->shouldReceive('get')->twice()->andReturn($listResponse);
    $client->shouldReceive('server')->withNoArgs()->twice()->andReturn($listResource);

    $provider = new class($client) extends PloiProvider
    {
        public function __construct(private readonly Ploi $fakeClient)
        {
            parent::__construct(['api_key' => 'token']);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }
    };

    $result = $provider->destroy(
        makePluginProject(),
        makePluginProfile(makePluginServer([
            'name' => 'api-pr-777',
            'credential' => '42',
            'region' => 'eu-west',
            'plan' => 'small',
        ], 'destroy')),
    );

    expect($result)->toBeFalse();
    expect($provider->getLastError())->toBe('Refusing to delete unmanaged server: api-pr-777');
});
