<?php

use Mockery as m;
use Ploi\Ploi;
use Ploi\Resources\Site;
use Ploi\Resources\Server;
use Ploi\Resources\Database;
use ShipperCli\ProviderPloi\PloiProvider;

test('site cleanup requires ownership of the exact project and profile', function (string $content, bool $owned): void {
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $site = m::mock(Site::class);
    $site->shouldReceive('environment->get->getJson')->once()->andReturn((object) ['data' => (object) ['content' => $content]]);
    $client->shouldReceive('server')->with(123)->times($owned ? 2 : 1)->andReturn($server);
    $server->shouldReceive('sites')->with(55)->times($owned ? 2 : 1)->andReturn($site);
    if ($owned) {
        $databases = m::mock(Database::class);
        $databases->shouldReceive('page->getJson')->andReturn((object) ['data' => [], 'meta' => (object) ['last_page' => 1]]);
        $server->shouldReceive('databases')->once()->andReturn($databases);
        $site->shouldReceive('delete->getJson')->once()->andReturn((object) ['message' => 'Deleted']);
    } else {
        $server->shouldNotReceive('databases');
        $site->shouldNotReceive('delete');
    }
    $provider = new class($client) extends PloiProvider {
        public function __construct(private Ploi $fakeClient) { parent::__construct(); }
        protected function getClient(): Ploi { return $this->fakeClient; }
    };
    $project = new class { public function name(): string { return 'api'; } };
    $profile = new class { public function name(): string { return 'preview'; } };
    $result = (new ReflectionMethod(PloiProvider::class, 'deleteSiteAndDatabases'))->invoke($provider, $project, $profile, 123, 55);
    expect($result)->toBe($owned);
})->with([
    ['', false],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', 'other|preview'), false],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', 'api|production'), false],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', 'api|preview'), true],
]);
