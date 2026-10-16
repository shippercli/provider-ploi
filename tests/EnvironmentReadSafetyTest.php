<?php

use Mockery as m;
use Ploi\Ploi;
use Ploi\Http\Response;
use Ploi\Resources\Environment;
use ShipperCli\ProviderPloi\PloiProvider;

test('environment updates require readable complete existing content', function (mixed $json, bool $valid): void {
    $client = m::mock(Ploi::class);
    $environment = m::mock(Environment::class);
    $client->shouldReceive('server->sites->environment')->once()->andReturn($environment);
    $response = m::mock(Response::class);
    $response->shouldReceive('getJson')->once()->andReturn($json);
    $environment->shouldReceive('get')->once()->andReturn($response);
    $written = null;
    if ($valid) {
        $environment->shouldReceive('update')->once()->andReturnUsing(function (string $content) use (&$written): Response {
            $written = $content;
            return m::mock(Response::class);
        });
    } else {
        $environment->shouldNotReceive('update');
    }
    $provider = new class($client) extends PloiProvider {
        public function __construct(private Ploi $client) { parent::__construct(); }
        protected function getClient(): Ploi { return $this->client; }
    };
    $project = new class {
        public function environment(): object { return new class {
            public function variables(): array { return ['APP_NAME' => 'fixture']; }
        }; }
    };
    $result = (new ReflectionMethod($provider, 'applyEnvironment'))->invoke($provider, $project, new stdClass);
    expect($result['success'])->toBe($valid);
    if ($valid) {
        expect(\Dotenv\Dotenv::parse($written)['APP_NAME'])->toBe('fixture');
        if (($json->data->content ?? '') !== '') {
            expect($written)->toContain('APP_ENV=production');
        }
    }
})->with([
    [null, false], [(object) [], false],
    [(object) ['data' => (object) ['content' => 123]], false],
    [(object) ['data' => (object) ['content' => 'BROKEN="unfinished']], false],
    [(object) ['success' => false, 'data' => (object) ['content' => '']], false],
    [(object) ['errors' => ['synthetic-secret'], 'data' => (object) ['content' => '']], false],
    [(object) ['data' => (object) ['content' => '']], true],
    [(object) ['data' => (object) ['content' => "APP_ENV=production\n"]], true],
]);
