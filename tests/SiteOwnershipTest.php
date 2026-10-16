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
        $collection = m::mock(Site::class);
        $server->shouldReceive('sites')->withNoArgs()->twice()->andReturn($collection);
        $collection->shouldReceive('page->getJson')->andReturn(
            (object) ['data' => [(object) ['id' => 55, 'domain' => 'fixture.test']], 'meta' => (object) ['last_page' => 1]],
            (object) ['data' => [], 'meta' => (object) ['last_page' => 1]],
        );
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
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', json_encode(['other', 'preview'], JSON_THROW_ON_ERROR)), false],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', json_encode(['api', 'production'], JSON_THROW_ON_ERROR)), false],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)), true],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', 'api|preview'), false],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR))."\nSHIPPERCLI_OWNERSHIP=other", false],
    ["COMMENT=\"multiline\nSHIPPERCLI_OWNERSHIP=".hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR))."\ntext\"", false],
    ['SHIPPERCLI_OWNERSHIP="unterminated', false],
    ['SHIPPERCLI_OWNERSHIP='.hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR))."\nBROKEN=\"unfinished", false],
    ['export SHIPPERCLI_OWNERSHIP = "'.hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)).'" # managed', true],
]);

test('ownership markers distinguish scopes containing delimiters', function (): void {
    $provider = new PloiProvider();
    $named = fn (string $name) => new class($name) {
        public function __construct(private string $value) {}
        public function name(): string { return $this->value; }
    };
    $marker = new ReflectionMethod(PloiProvider::class, 'siteOwnershipMarker');

    expect($marker->invoke($provider, $named('api|preview'), $named('test')))
        ->not->toBe($marker->invoke($provider, $named('api'), $named('preview|test')));
});
