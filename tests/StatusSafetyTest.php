<?php

use Mockery as m;
use Ploi\Ploi;
use Ploi\Resources\Server;
use Ploi\Resources\Site;
use Ploi\Http\Response;
use ShipperCli\ProviderPloi\PloiProvider;

test('status reports observed site state without exposing raw resource fields', function (mixed $state, string $expected): void {
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $sites = m::mock(Site::class);
    $response = m::mock(Response::class);
    $response->shouldReceive('getJson')->once()->andReturn((object) ['data' => [(object) [
        'id' => 55, 'domain' => 'fixture.test', 'status' => $state,
        'env' => 'DB_PASSWORD=synthetic-secret', 'deploy_script' => 'echo synthetic-secret',
        'repository' => (object) ['token' => 'synthetic-secret'],
    ]], 'meta' => (object) ['last_page' => 1]]);
    $sites->shouldReceive('page')->with(1, 50)->once()->andReturn($response);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($sites);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
    $provider = new class($client) extends PloiProvider {
        public function __construct(private Ploi $client) { parent::__construct(['server_id' => 123]); }
        protected function getClient(): Ploi { return $this->client; }
        protected function resolveServerIdForProfile(object $project, object $profile, bool $createIfMissing = false): int { return 123; }
    };
    $profile = new class { public function get(string $key): mixed { return $key === 'domain' ? 'fixture.test' : null; } };
    $result = $provider->status(new stdClass, $profile);
    expect($result['state'])->toBe($expected)
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('synthetic-secret')
        ->and(array_keys(get_object_vars($result['site'])))->toBe(['id', 'domain', 'status']);
})->with([['active', 'active'], ['deploy-failed', 'deploy-failed'], [null, 'unknown'], [false, 'unknown'], ['   ', 'unknown']]);
