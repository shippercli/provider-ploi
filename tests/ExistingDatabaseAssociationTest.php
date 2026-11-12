<?php

declare(strict_types=1);

use Mockery as m;
use Ploi\Http\Response;
use Ploi\Ploi;
use Ploi\Resources\Database;
use Ploi\Resources\Repository;
use Ploi\Resources\Server;
use Ploi\Resources\Site;
use ShipperCli\ProviderPloi\PloiProvider;

test('apply fails closed when an existing database site association cannot be proven', function (mixed $linkedId): void {
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $sites = m::mock(Site::class);
    $site = m::mock(Site::class);
    $repository = m::mock(Repository::class);
    $databases = m::mock(Database::class);
    $serverResponse = m::mock(Response::class);
    $siteListResponse = m::mock(Response::class);
    $databaseListResponse = m::mock(Response::class);

    $serverResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => (object) ['id' => 123, 'status' => 'active'],
    ]);
    $siteListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 55, 'domain' => 'preview.example.com']],
        'meta' => (object) ['last_page' => 1],
    ]);
    $databaseListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) [
            'id' => 9,
            'name' => 'api_preview',
            'site' => (object) ['id' => $linkedId],
        ]],
        'meta' => (object) ['last_page' => 1],
    ]);

    $sites->shouldReceive('page')->with(1, 50)->once()->andReturn($siteListResponse);
    $databases->shouldReceive('page')->with(1, 50)->once()->andReturn($databaseListResponse);
    $repository->shouldReceive('install')->with('github', 'feature/test', 'ulties/shipper')->once();
    $site->shouldReceive('repository')->once()->andReturn($repository);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($sites);
    $server->shouldReceive('sites')->with(55)->once()->andReturn($site);
    $server->shouldReceive('databases')->withNoArgs()->once()->andReturn($databases);
    $server->shouldReceive('get')->once()->andReturn($serverResponse);
    $client->shouldReceive('server')->with(123)->twice()->andReturn($server);

    $provider = new class($client) extends PloiProvider
    {
        public function __construct(private readonly Ploi $fakeClient)
        {
            parent::__construct(['api_key' => 'token', 'server_id' => '123']);
        }

        // Keep this regression focused on database association after site ownership is established.
        protected function assertSiteOwnership(object $project, object $profile, int $serverId, int $siteId): void {}

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

        public function repository(): array
        {
            return ['provider' => 'github', 'name' => 'ulties/shipper'];
        }

        public function databases(): array
        {
            return [new class
            {
                public function name(): string
                {
                    return 'api_preview';
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
    $profile = new class
    {
        public function name(): string
        {
            return 'preview';
        }

        public function get(string $key): mixed
        {
            return $key === 'domain' ? 'preview.example.com' : null;
        }

        public function branch(): string
        {
            return 'feature/test';
        }
    };

    expect($provider->apply($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Existing Ploi database api_preview is not attached to site 55');
})->with([999, '55junk', 55.9, '55.9', '5.5e1', ' 55', '055']);

test('apply refuses an unmanaged or differently scoped Ploi site before mutations', function (string $content): void {
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $sites = m::mock(Site::class);
    $site = m::mock(Site::class);
    $repository = m::mock(Repository::class);
    $databases = m::mock(Database::class);
    $serverResponse = m::mock(Response::class);
    $siteListResponse = m::mock(Response::class);
    $databaseListResponse = m::mock(Response::class);

    $serverResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => (object) ['id' => 123, 'status' => 'active'],
    ]);
    $siteListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 55, 'domain' => 'preview.example.com']],
        'meta' => (object) ['last_page' => 1],
    ]);
    $databaseListResponse->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) [
            'id' => 9,
            'name' => 'api_preview',
            'site' => (object) ['id' => 999],
        ]],
        'meta' => (object) ['last_page' => 1],
    ]);

    $sites->shouldReceive('page')->with(1, 50)->once()->andReturn($siteListResponse);
    $databases->shouldNotReceive('page');
    $repository->shouldNotReceive('install');
    $site->shouldReceive('environment->get->getJson')->andReturn((object) ['data' => (object) ['content' => $content]]);
    $site->shouldNotReceive('repository');
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($sites);
    $server->shouldReceive('sites')->with(55)->once()->andReturn($site);
    $server->shouldNotReceive('databases');
    $server->shouldReceive('get')->once()->andReturn($serverResponse);
    $client->shouldReceive('server')->with(123)->times(3)->andReturn($server);

    $provider = new class($client) extends PloiProvider
    {
        public function __construct(private readonly Ploi $fakeClient)
        {
            parent::__construct(['api_key' => 'token', 'server_id' => '123']);
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

        public function repository(): array
        {
            return ['provider' => 'github', 'name' => 'ulties/shipper'];
        }

        public function databases(): array
        {
            return [new class
            {
                public function name(): string
                {
                    return 'api_preview';
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
    $profile = new class
    {
        public function name(): string
        {
            return 'preview';
        }

        public function get(string $key): mixed
        {
            return $key === 'domain' ? 'preview.example.com' : null;
        }

        public function branch(): string
        {
            return 'feature/test';
        }
    };

    expect($provider->apply($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('matching Shipper ownership evidence is missing');
})->with(['', 'APP_ENV=production', 'SHIPPERCLI_OWNERSHIP='.hash('sha256', json_encode(['other', 'preview'], JSON_THROW_ON_ERROR))]);
