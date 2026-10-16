<?php

use Mockery as m;
use Ploi\Ploi;
use Ploi\Resources\Site;
use Ploi\Resources\Server;
use ShipperCli\ProviderPloi\PloiProvider;

test('existing-server plans verify ownership without writes', function (string $mode): void {
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $sites = m::mock(Site::class);
    $client->shouldReceive('server')->with(123)->andReturn($server);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($sites);
    $sites->shouldNotReceive('create');
    $sites->shouldNotReceive('delete');
    if ($mode === 'error') {
        $sites->shouldReceive('page')->once()->andThrow(new RuntimeException('sensitive error'));
    } else {
        $sites->shouldReceive('page->getJson')->andReturn((object) ['data' => $mode === 'new' ? [] : [(object) ['id' => 55, 'domain' => 'preview.example.com']], 'meta' => (object) ['last_page' => 1]]);
        if ($mode !== 'new') {
            $server->shouldReceive('sites')->with(55)->once()->andReturn($sites);
            $marker = hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR));
            $env = match ($mode) {
                'owned' => 'SHIPPERCLI_OWNERSHIP='.$marker,
                'malformed' => 'SHIPPERCLI_OWNERSHIP="unterminated',
                default => 'SHIPPERCLI_OWNERSHIP=another-scope',
            };
            $sites->shouldReceive('environment->get->getJson')->once()->andReturn((object) ['data' => (object) ['content' => $env]]);
        }
    }
    $provider = new class($client) extends PloiProvider {
        public function __construct(private Ploi $fakeClient) { parent::__construct(['server_id' => '123']); }
        protected function getClient(): Ploi { return $this->fakeClient; }
    };
    $project = new class { public function name(): string { return 'api'; } };
    $profile = new class {
        public function name(): string { return 'preview'; }
        public function get(string $key): mixed { return $key === 'domain' ? 'preview.example.com' : null; }
    };
    $plan = $provider->plan($project, $profile);
    expect($plan['blocked'])->toBe(! in_array($mode, ['new', 'owned'], true));
    if ($plan['blocked']) {
        expect($plan['actions'])->toHaveCount(1)
            ->and($plan['actions'][0])->toStartWith('BLOCKED:')
            ->not->toContain('sensitive error');
    }
    m::close();
})->with(['new', 'owned', 'unowned', 'malformed', 'error']);
