<?php

declare(strict_types=1);

use Mockery as m;
use Ploi\Http\Response;
use Ploi\Ploi;
use Ploi\Resources\Database;
use Ploi\Resources\Environment;
use Ploi\Resources\Server;
use Ploi\Resources\Site;
use ShipperCli\ProviderPloi\PloiProvider;

test('ordinary destroy preserves adopted databases and refuses databases without ownership evidence', function (string $ownership, bool $expected, bool $deleteDatabase): void {
    $project = new class
    {
        public function name(): string { return 'api'; }
        public function databases(): array
        {
            return [new class
            {
                public function name(): string { return 'api_production'; }
                public function user(): string { return 'api'; }
            }];
        }
    };
    $profile = new class
    {
        public function name(): string { return 'production'; }
        public function get(string $key): mixed { return $key === 'domain' ? 'app.example.test' : null; }
    };

    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $sites = m::mock(Site::class);
    $site = m::mock(Site::class);
    $environment = m::mock(Environment::class);
    $databases = m::mock(Database::class);
    $database = m::mock(Database::class);
    $siteDelete = m::mock(Response::class);
    $siteDelete->shouldReceive('getJson')->times($expected ? 1 : 0)->andReturn((object) ['message' => 'Site deleted']);

    $sitePresent = m::mock(Response::class);
    $sitePresent->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 55, 'domain' => 'app.example.test']],
        'meta' => (object) ['last_page' => 1],
    ]);
    $siteAbsent = m::mock(Response::class);
    $siteAbsent->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 1],
    ]);
    $sites->shouldReceive('page')->with(1, 50)->times($expected ? 3 : 1)
        ->andReturn($sitePresent, $sitePresent, $siteAbsent);
    $site->shouldReceive('delete')->times($expected ? 1 : 0)->andReturn($siteDelete);

    $databasePresent = m::mock(Response::class);
    $databasePresent->shouldReceive('getJson')->andReturn((object) [
        'data' => [(object) ['id' => 9, 'name' => 'api_production', 'site' => (object) ['id' => 55]]],
        'meta' => (object) ['last_page' => 1],
    ]);
    $databaseAbsent = m::mock(Response::class);
    $databaseAbsent->shouldReceive('getJson')->andReturn((object) [
        'data' => [],
        'meta' => (object) ['last_page' => 1],
    ]);
    $databases->shouldReceive('page')->with(1, 50)->times($deleteDatabase ? 2 : 1)
        ->andReturn($databasePresent, $databaseAbsent);
    if ($deleteDatabase) {
        $databaseDelete = m::mock(Response::class);
        $databaseDelete->shouldReceive('getJson')->once()->andReturn(null);
        $database->shouldReceive('delete')->once()->andReturn($databaseDelete);
        $server->shouldReceive('databases')->with(9)->once()->andReturn($database);
    } else {
        $database->shouldNotReceive('delete');
    }

    $marker = hash('sha256', json_encode(['api', 'production', 'api_production'], JSON_THROW_ON_ERROR));
    $key = 'SHIPPERCLI_DATABASE_'.strtoupper(substr(hash('sha256', 'api_production'), 0, 24));
    $environmentResponse = m::mock(Response::class);
    $environmentResponse->shouldReceive('getJson')->once()->andReturn((object) [
        'data' => (object) ['content' => $ownership === 'missing' ? '' : "{$key}={$ownership}:{$marker}"],
    ]);
    $environment->shouldReceive('get')->once()->andReturn($environmentResponse);
    $site->shouldReceive('environment')->once()->andReturn($environment);
    $server->shouldReceive('sites')->withNoArgs()->times($expected ? 3 : 1)->andReturn($sites);
    $server->shouldReceive('sites')->with(55)->times($expected ? 2 : 1)->andReturn($site);
    $server->shouldReceive('databases')->withNoArgs()->times($deleteDatabase ? 2 : 1)->andReturn($databases);
    $client->shouldReceive('server')->with(123)->twice()->andReturn($server);

    $provider = new class(['api_key' => 'token', 'server_id' => 123], $client) extends PloiProvider
    {
        public function __construct(array $config, private readonly Ploi $fakeClient)
        {
            parent::__construct($config);
        }

        protected function assertSiteOwnership(object $project, object $profile, int $serverId, int $siteId): void {}

        protected function getClient(): Ploi
        {
            return $this->fakeClient;
        }
    };

    expect($provider->destroy($project, $profile))->toBe($expected);
    if (! $expected) {
        expect($provider->getLastError())->toContain('ownership state cannot be proven');
    }
})->with([
    ['adopted', true, false],
    ['missing', false, false],
    ['created', true, true],
]);
