<?php

declare(strict_types=1);

use Mockery as m;
use Ploi\Http\Response;
use Ploi\Ploi;
use Ploi\Resources\Alias;
use Ploi\Resources\Certificate;
use Ploi\Resources\Database;
use Ploi\Resources\Deployment;
use Ploi\Resources\Environment;
use Ploi\Resources\Repository;
use Ploi\Resources\Server;
use Ploi\Resources\Site;
use ShipperCli\ProviderPloi\PloiProvider;
use ShipperCli\ProviderPloi\PloiPlugin;
use ShipperCli\ProviderPloi\ServerLifecycleClientInterface;

test('plugin exposes the ploi provider mapping', function (): void {
    expect((new PloiPlugin)->providers())->toBe(['ploi' => PloiProvider::class]);
});

test('provider declares capability states explicitly', function (): void {
    $capabilities = (new PloiProvider)->capabilities();

    expect($capabilities['app_deploy']['state'])->toBe('supported')
        ->and($capabilities['server_lifecycle']['state'])->toBe('partial')
        ->and($capabilities['rollback']['state'])->toBe('unsupported');
});

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
    $firstResponse = m::mock(Response::class);
    $secondResponse = m::mock(Response::class);

    $firstResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 2],
    ]);
    $secondResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [
            (object) ['id' => 321, 'name' => 'shipper-api-preview-api-pr-123'],
        ],
        'meta' => (object) ['last_page' => 2],
    ]);

    $serverResource->shouldReceive('page')->with(1, 50)->once()->andReturn($firstResponse);
    $serverResource->shouldReceive('page')->with(2, 50)->once()->andReturn($secondResponse);
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
        'name' => 'api-pr-123',
        'credential' => '42',
        'region' => 'eu-west',
        'plan' => 'small',
    ], 'destroy'));

    expect($provider->resolve(makePluginProject(), $profile, true))->toBe(321);
});

test('provider package verifies an existing server before deployment', function (): void {
    $lifecycleClient = new class implements ServerLifecycleClientInterface
    {
        public int $requestedServerId = 0;

        public function list(): array
        {
            return [];
        }

        public function get(int $serverId): object
        {
            $this->requestedServerId = $serverId;

            return (object) ['id' => $serverId, 'name' => 'production'];
        }

        public function create(string $name, int $credential, string $region, string $plan, array $options = []): int
        {
            throw new LogicException('Not used');
        }

        public function delete(int $serverId): void
        {
            throw new LogicException('Not used');
        }
    };
    $provider = new class($lifecycleClient) extends PloiProvider
    {
        public function __construct(ServerLifecycleClientInterface $lifecycleClient)
        {
            parent::__construct(['api_key' => 'token', 'server_id' => '123'], $lifecycleClient);
        }

        public function resolve(object $project, object $profile): int
        {
            return $this->resolveServerIdForProfile($project, $profile);
        }
    };

    expect($provider->resolve(makePluginProject(), makePluginProfile()))->toBe(123)
        ->and($lifecycleClient->requestedServerId)->toBe(123);
});

test('provider package fails clearly when an existing server cannot be loaded', function (): void {
    $lifecycleClient = new class implements ServerLifecycleClientInterface
    {
        public function list(): array
        {
            return [];
        }

        public function get(int $serverId): object
        {
            throw new RuntimeException("Ploi server {$serverId} was not found");
        }

        public function create(string $name, int $credential, string $region, string $plan, array $options = []): int
        {
            throw new LogicException('Not used');
        }

        public function delete(int $serverId): void
        {
            throw new LogicException('Not used');
        }
    };
    $provider = new class($lifecycleClient) extends PloiProvider
    {
        public function __construct(ServerLifecycleClientInterface $lifecycleClient)
        {
            parent::__construct(['api_key' => 'token', 'server_id' => '404'], $lifecycleClient);
        }

        public function resolve(object $project, object $profile): int
        {
            return $this->resolveServerIdForProfile($project, $profile);
        }
    };

    expect(fn (): int => $provider->resolve(makePluginProject(), makePluginProfile()))
        ->toThrow(RuntimeException::class, 'Ploi server 404 was not found');
});

test('provider package creates managed server when missing', function (): void {
    $client = m::mock(Ploi::class);
    $serverResource = m::mock(Server::class);
    $listResponse = m::mock(Response::class);
    $createResponse = m::mock(Response::class);

    $listResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 1],
    ]);
    $createResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => (object) ['id' => 654],
    ]);

    $serverResource->shouldReceive('page')->with(1, 50)->once()->andReturn($listResponse);
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
    $siteCollection = m::mock(Site::class);
    $siteResource = m::mock(Site::class);
    $databaseCollection = m::mock(Database::class);
    $listResponse = m::mock(Response::class);
    $siteListResponse = m::mock(Response::class);
    $databaseListResponse = m::mock(Response::class);
    $siteDeleteResponse = m::mock(Response::class);
    $deleteResponse = m::mock(Response::class);

    $listResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [
            (object) ['id' => 999, 'name' => 'shipper-api-preview-api-pr-999'],
        ],
        'meta' => (object) ['last_page' => 1],
    ]);
    $siteListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 55, 'domain' => 'preview.example.com']],
        'meta' => (object) ['last_page' => 1],
    ]);
    $databaseListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 1],
    ]);
    $siteDeleteResponse->shouldReceive('getJson')->andReturn((object) ['message' => 'Site deleted']);
    $deleteResponse->shouldReceive('getJson')->andReturn((object) [
        'message' => 'Server deleted successfully',
    ]);

    $listResource->shouldReceive('page')->with(1, 50)->twice()->andReturn($listResponse);
    $siteCollection->shouldReceive('page')->with(1, 50)->once()->andReturn($siteListResponse);
    $databaseCollection->shouldReceive('page')->with(1, 50)->once()->andReturn($databaseListResponse);
    $siteResource->shouldReceive('delete')->once()->andReturn($siteDeleteResponse);
    $serverResource->shouldReceive('sites')->withNoArgs()->once()->andReturn($siteCollection);
    $serverResource->shouldReceive('databases')->withNoArgs()->once()->andReturn($databaseCollection);
    $serverResource->shouldReceive('sites')->with(55)->once()->andReturn($siteResource);
    $serverResource->shouldReceive('delete')->once()->andReturn($deleteResponse);

    $client->shouldReceive('server')->withNoArgs()->twice()->andReturn($listResource);
    $client->shouldReceive('server')->with(999)->times(3)->andReturn($serverResource);

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

    $listResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [
            (object) ['id' => 777, 'name' => 'api-pr-777'],
        ],
        'meta' => (object) ['last_page' => 1],
    ]);

    $listResource->shouldReceive('page')->with(1, 50)->times(3)->andReturn($listResponse);
    $client->shouldReceive('server')->withNoArgs()->times(3)->andReturn($listResource);

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

test('provider package runs post-apply capabilities without relying on the core Ploi class', function (): void {
    $provider = new class extends PloiProvider
    {
        /** @var array<int, string> */
        public array $operations = [];

        protected function applyAliases(object $profile): array
        {
            $this->operations[] = 'aliases';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function applyDeployScript(object $project, object $profile): array
        {
            $this->operations[] = 'deploy-script';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function applyEnvironment(object $project, object $profile): array
        {
            $this->operations[] = 'environment';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function applySsl(object $project, object $profile): array
        {
            $this->operations[] = 'ssl';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function deploymentLogs(int $serverId, int $siteId): array
        {
            return ['deployment complete'];
        }
    };
    $serverId = new ReflectionProperty(PloiProvider::class, 'lastServerId');
    $serverId->setValue($provider, 123);
    $siteId = new ReflectionProperty(PloiProvider::class, 'lastSiteId');
    $siteId->setValue($provider, 456);

    $result = $provider->postApply(makePluginProject(), makePluginProfile());

    expect($result)->toBe([
        'success' => true,
        'message' => 'Ploi post-apply configuration completed',
        'logs' => ['deployment complete'],
    ])->and($provider->operations)->toBe(['aliases', 'deploy-script', 'environment', 'ssl']);
});

test('provider package lists sites across every page', function (): void {
    $lifecycleClient = m::mock(ServerLifecycleClientInterface::class);
    $lifecycleClient->shouldReceive('get')->with(123)->once()->andReturn((object) ['id' => 123]);
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $sites = m::mock(Site::class);
    $firstResponse = m::mock(Response::class);
    $secondResponse = m::mock(Response::class);
    $firstResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 2],
    ]);
    $secondResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 55, 'domain' => 'preview.example.com']],
        'meta' => (object) ['last_page' => 2],
    ]);
    $sites->shouldReceive('page')->with(1, 50)->once()->andReturn($firstResponse);
    $sites->shouldReceive('page')->with(2, 50)->once()->andReturn($secondResponse);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($sites);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
    $provider = new class($client, $lifecycleClient) extends PloiProvider
    {
        public function __construct(
            private readonly Ploi $fakeClient,
            ServerLifecycleClientInterface $lifecycleClient,
        ) {
            parent::__construct(['api_key' => 'token', 'server_id' => '123'], $lifecycleClient);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }
    };

    expect($provider->listSites(makePluginProject(), makePluginProfile()))->toBe([
        ['site_id' => 55, 'domain' => 'preview.example.com'],
    ]);
});

test('database deletion failure is reported after site deletion is attempted', function (): void {
    $lifecycleClient = m::mock(ServerLifecycleClientInterface::class);
    $lifecycleClient->shouldReceive('get')->with(123)->once()->andReturn((object) ['id' => 123]);
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $databases = m::mock(Database::class);
    $database = m::mock(Database::class);
    $site = m::mock(Site::class);
    $firstResponse = m::mock(Response::class);
    $secondResponse = m::mock(Response::class);
    $siteDeleteResponse = m::mock(Response::class);
    $firstResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 2],
    ]);
    $secondResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 9, 'name' => 'api_preview']],
        'meta' => (object) ['last_page' => 2],
    ]);
    $siteDeleteResponse->shouldReceive('getJson')->andReturn((object) ['message' => 'Site deleted']);
    $databases->shouldReceive('page')->with(1, 50)->once()->andReturn($firstResponse);
    $databases->shouldReceive('page')->with(2, 50)->once()->andReturn($secondResponse);
    $database->shouldReceive('delete')->once()->andThrow(new RuntimeException('permission denied'));
    $site->shouldReceive('delete')->once()->andReturn($siteDeleteResponse);
    $server->shouldReceive('databases')->withNoArgs()->once()->andReturn($databases);
    $server->shouldReceive('databases')->with(9)->once()->andReturn($database);
    $server->shouldReceive('sites')->with(55)->once()->andReturn($site);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
    $provider = new class($client, $lifecycleClient) extends PloiProvider
    {
        public function __construct(
            private readonly Ploi $fakeClient,
            ServerLifecycleClientInterface $lifecycleClient,
        ) {
            parent::__construct(['api_key' => 'token', 'server_id' => '123'], $lifecycleClient);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }
    };
    $project = new class
    {
        public function name(): string
        {
            return 'api';
        }

        public function databases(): array
        {
            return [new class
            {
                public function name(): string
                {
                    return '${PROJECT_NAME}_${PROFILE}';
                }

                public function user(): string
                {
                    return 'api';
                }

                public function type(): string
                {
                    return 'mysql';
                }
            }];
        }
    };

    expect($provider->deleteSiteWithDatabases($project, makePluginProfile(), 55))->toBeFalse()
        ->and($provider->getLastError())->toContain('Failed to delete database api_preview: permission denied');
});

test('create-mode retain cleanup deletes the profile site but retains the server', function (): void {
    $lifecycleClient = m::mock(ServerLifecycleClientInterface::class);
    $lifecycleClient->shouldReceive('list')->once()->andReturn([
        (object) ['id' => 999, 'name' => 'shipper-api-preview-api-pr-999'],
    ]);
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $siteCollection = m::mock(Site::class);
    $site = m::mock(Site::class);
    $databaseCollection = m::mock(Database::class);
    $siteListResponse = m::mock(Response::class);
    $databaseListResponse = m::mock(Response::class);
    $siteDeleteResponse = m::mock(Response::class);
    $siteListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 55, 'domain' => 'preview.example.com']],
        'meta' => (object) ['last_page' => 1],
    ]);
    $databaseListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 1],
    ]);
    $siteDeleteResponse->shouldReceive('getJson')->andReturn((object) ['message' => 'Site deleted']);
    $siteCollection->shouldReceive('page')->with(1, 50)->once()->andReturn($siteListResponse);
    $databaseCollection->shouldReceive('page')->with(1, 50)->once()->andReturn($databaseListResponse);
    $site->shouldReceive('delete')->once()->andReturn($siteDeleteResponse);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($siteCollection);
    $server->shouldReceive('databases')->withNoArgs()->once()->andReturn($databaseCollection);
    $server->shouldReceive('sites')->with(55)->once()->andReturn($site);
    $server->shouldNotReceive('delete');
    $client->shouldReceive('server')->with(999)->twice()->andReturn($server);
    $provider = new class($client, $lifecycleClient) extends PloiProvider
    {
        public function __construct(
            private readonly Ploi $fakeClient,
            ServerLifecycleClientInterface $lifecycleClient,
        ) {
            parent::__construct(['api_key' => 'token'], $lifecycleClient);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }
    };

    expect($provider->destroy(
        makePluginProject(),
        makePluginProfile(makePluginServer([
            'name' => 'api-pr-999',
            'credential' => '42',
            'region' => 'eu-west',
            'plan' => 'small',
        ], 'retain')),
    ))->toBeTrue();
});

test('existing-site apply refreshes repository and configures before deploy', function (): void {
    $operations = [];
    $lifecycleClient = m::mock(ServerLifecycleClientInterface::class);
    $lifecycleClient->shouldReceive('get')->with(123)->once()->andReturn((object) ['id' => 123, 'status' => 'active']);
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $siteCollection = m::mock(Site::class);
    $site = m::mock(Site::class);
    $repository = m::mock(Repository::class);
    $deployment = m::mock(Deployment::class);
    $installResponse = m::mock(Response::class);
    $deployResponse = m::mock(Response::class);
    $siteListResponse = m::mock(Response::class);
    $siteStatusResponse = m::mock(Response::class);
    $siteListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 55, 'domain' => 'preview.example.com']],
        'meta' => (object) ['last_page' => 1],
    ]);
    $siteStatusResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => (object) ['deploying' => false, 'status' => 'deployed'],
    ]);
    $siteCollection->shouldReceive('page')->with(1, 50)->once()->andReturn($siteListResponse);
    $repository->shouldReceive('install')->with('github', 'feature/test', 'ulties/shipper')->once()
        ->andReturnUsing(function () use (&$operations, $installResponse): Response {
            $operations[] = 'repository';

            return $installResponse;
        });
    $deployment->shouldReceive('deploy')->once()->andReturnUsing(function () use (&$operations, $deployResponse): Response {
        $operations[] = 'deploy';

        return $deployResponse;
    });
    $site->shouldReceive('repository')->once()->andReturn($repository);
    $site->shouldReceive('deployment')->once()->andReturn($deployment);
    $site->shouldReceive('get')->once()->andReturn($siteStatusResponse);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($siteCollection);
    $server->shouldReceive('sites')->with(55)->times(3)->andReturn($site);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
    $provider = new class($client, $lifecycleClient, $operations) extends PloiProvider
    {
        /** @var array<int, string> */
        public array $operations;

        /** @param array<int, string> $operations */
        public function __construct(
            private readonly Ploi $fakeClient,
            ServerLifecycleClientInterface $lifecycleClient,
            array &$operations,
        ) {
            $this->operations = &$operations;
            parent::__construct([
                'api_key' => 'token',
                'server_id' => '123',
                'deployment_timeout' => 1,
            ], $lifecycleClient);
        }

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }

        protected function applyAliases(object $profile): array
        {
            $this->operations[] = 'aliases';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function applyDeployScript(object $project, object $profile): array
        {
            $this->operations[] = 'deploy-script';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function applyEnvironment(object $project, object $profile): array
        {
            $this->operations[] = 'environment';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function applySsl(object $project, object $profile): array
        {
            $this->operations[] = 'ssl';

            return ['success' => true, 'message' => 'ok'];
        }

        protected function deploymentLogs(int $serverId, int $siteId): array
        {
            return ['old deployment failed'];
        }
    };

    $result = $provider->apply(makePluginProject(), makePluginProfile());

    expect($provider->getLastError())->toBe('')
        ->and($result)->toBeTrue()
        ->and($operations)->toBe(['repository', 'aliases', 'deploy-script', 'environment', 'ssl', 'deploy']);
});

test('create-mode readiness waits until the server becomes active', function (): void {
    $lifecycleClient = m::mock(ServerLifecycleClientInterface::class);
    $lifecycleClient->shouldReceive('get')->with(654)->twice()->andReturn(
        (object) ['id' => 654, 'status' => 'provisioning'],
        (object) ['id' => 654, 'status' => 'active'],
    );
    $provider = new class($lifecycleClient) extends PloiProvider
    {
        public function __construct(ServerLifecycleClientInterface $lifecycleClient)
        {
            parent::__construct([
                'api_key' => 'token',
                'deployment_timeout' => 2,
                'server_ready_poll_interval' => 0,
            ], $lifecycleClient);
        }

        public function waitUntilReady(int $serverId): void
        {
            $this->waitForServerReady($serverId);
        }
    };

    $provider->waitUntilReady(654);
    expect(true)->toBeTrue();
});

test('generated database credentials are merged into the site environment', function (): void {
    $capturedEnvironment = '';
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $site = m::mock(Site::class);
    $environment = m::mock(Environment::class);
    $getResponse = m::mock(Response::class);
    $updateResponse = m::mock(Response::class);
    $getResponse->shouldReceive('getJson')->andReturn((object) ['data' => (object) ['content' => 'APP_ENV=production']]);
    $environment->shouldReceive('get')->once()->andReturn($getResponse);
    $environment->shouldReceive('update')->once()->andReturnUsing(function (string $content) use (&$capturedEnvironment, $updateResponse): Response {
        $capturedEnvironment = $content;

        return $updateResponse;
    });
    $site->shouldReceive('environment')->once()->andReturn($environment);
    $server->shouldReceive('sites')->with(55)->once()->andReturn($site);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
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

        public function applyGeneratedEnvironment(object $project, object $profile): array
        {
            return $this->applyEnvironment($project, $profile);
        }
    };
    (new ReflectionProperty(PloiProvider::class, 'lastServerId'))->setValue($provider, 123);
    (new ReflectionProperty(PloiProvider::class, 'lastSiteId'))->setValue($provider, 55);
    (new ReflectionProperty(PloiProvider::class, 'generatedEnvironment'))->setValue($provider, [
        'DB_DATABASE' => 'api_preview',
        'DB_USERNAME' => 'api',
        'DB_PASSWORD' => 'generated-secret',
    ]);
    $emptyEnvironment = new class
    {
        public function mergeWith(object $other): object
        {
            return $this;
        }

        public function variables(): array
        {
            return [];
        }
    };
    $project = new class($emptyEnvironment)
    {
        public function __construct(private readonly object $environment) {}

        public function environment(): object
        {
            return $this->environment;
        }
    };
    $profile = new class($emptyEnvironment)
    {
        public function __construct(private readonly object $environment) {}

        public function environment(): object
        {
            return $this->environment;
        }
    };

    expect($provider->applyGeneratedEnvironment($project, $profile)['success'])->toBeTrue()
        ->and($capturedEnvironment)->toContain('DB_DATABASE=api_preview')
        ->and($capturedEnvironment)->toContain('DB_USERNAME=api')
        ->and($capturedEnvironment)->toContain('DB_PASSWORD=generated-secret');
});

test('validation rejects parsed resources that Ploi does not apply', function (): void {
    $project = new class
    {
        public function repository(): array
        {
            return ['provider' => 'github', 'name' => 'shippercli/cli'];
        }

        public function cron(): array
        {
            return [new stdClass];
        }

        public function phpVersion(): string
        {
            return '8.4';
        }
    };
    $provider = new PloiProvider(['api_key' => 'token', 'server_id' => '123']);

    expect($provider->validate($project, makePluginProfile()))
        ->toContain('Ploi provider does not yet support configured cron jobs')
        ->toContain('Ploi provider does not yet support configured php_version');
});

test('post-apply creates only missing aliases and passes force https to SSL', function (): void {
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $site = m::mock(Site::class);
    $alias = m::mock(Alias::class);
    $certificate = m::mock(Certificate::class);
    $aliasResponse = m::mock(Response::class);
    $aliasCreateResponse = m::mock(Response::class);
    $certificateResponse = m::mock(Response::class);
    $certificateCreateResponse = m::mock(Response::class);
    $aliasResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['domain' => 'www.example.com']],
    ]);
    $certificateResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 1],
    ]);
    $alias->shouldReceive('get')->once()->andReturn($aliasResponse);
    $alias->shouldReceive('create')->with(['api.example.com'])->once()->andReturn($aliasCreateResponse);
    $certificate->shouldReceive('page')->with(1, 50)->once()->andReturn($certificateResponse);
    $certificate->shouldReceive('create')->with('preview.example.com', 'letsencrypt', true)->once()->andReturn($certificateCreateResponse);
    $site->shouldReceive('alias')->once()->andReturn($alias);
    $site->shouldReceive('certificates')->once()->andReturn($certificate);
    $server->shouldReceive('sites')->with(55)->twice()->andReturn($site);
    $client->shouldReceive('server')->with(123)->twice()->andReturn($server);
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

        public function configureAliases(object $profile): array
        {
            return $this->applyAliases($profile);
        }

        public function configureSsl(object $project, object $profile): array
        {
            return $this->applySsl($project, $profile);
        }
    };
    (new ReflectionProperty(PloiProvider::class, 'lastServerId'))->setValue($provider, 123);
    (new ReflectionProperty(PloiProvider::class, 'lastSiteId'))->setValue($provider, 55);
    $profile = new class
    {
        public function aliases(): array
        {
            return ['www.example.com', 'api.example.com'];
        }

        public function get(string $key): mixed
        {
            return $key === 'domain' ? 'preview.example.com' : null;
        }
    };
    $project = new class
    {
        public function ssl(): object
        {
            return new class
            {
                public function enabled(): bool
                {
                    return true;
                }

                public function type(): string
                {
                    return 'letsencrypt';
                }

                public function forceHttps(): bool
                {
                    return true;
                }
            };
        }
    };

    expect($provider->configureAliases($profile)['success'])->toBeTrue()
        ->and($provider->configureSsl($project, $profile)['success'])->toBeTrue();
});

test('post-apply skips SSL create when the domain already has a certificate', function (): void {
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $site = m::mock(Site::class);
    $certificate = m::mock(Certificate::class);
    $response = m::mock(Response::class);
    $response->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 7, 'domain' => 'preview.example.com']],
        'meta' => (object) ['last_page' => 1],
    ]);
    $certificate->shouldReceive('page')->with(1, 50)->once()->andReturn($response);
    $certificate->shouldNotReceive('create');
    $site->shouldReceive('certificates')->once()->andReturn($certificate);
    $server->shouldReceive('sites')->with(55)->once()->andReturn($site);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
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

        public function configureSsl(object $project, object $profile): array
        {
            return $this->applySsl($project, $profile);
        }
    };
    (new ReflectionProperty(PloiProvider::class, 'lastServerId'))->setValue($provider, 123);
    (new ReflectionProperty(PloiProvider::class, 'lastSiteId'))->setValue($provider, 55);
    $profile = new class
    {
        public function get(string $key): mixed
        {
            return $key === 'domain' ? 'preview.example.com' : null;
        }
    };
    $project = new class
    {
        public function ssl(): object
        {
            return new class
            {
                public function enabled(): bool
                {
                    return true;
                }

                public function type(): string
                {
                    return 'letsencrypt';
                }

                public function forceHttps(): bool
                {
                    return false;
                }
            };
        }
    };

    expect($provider->configureSsl($project, $profile)['success'])->toBeTrue();
});
