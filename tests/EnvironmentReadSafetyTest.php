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
    $written = null;
    $response->shouldReceive('getJson')->times($valid ? 2 : 1)->andReturnUsing(function () use ($json, &$written) {
        return $written === null ? $json : (object) ['data' => (object) ['content' => $written]];
    });
    $environment->shouldReceive('get')->times($valid ? 2 : 1)->andReturn($response);
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


test('final environment update requires matching readback', function (string $mode): void {
    $client = m::mock(Ploi::class);
    $environment = m::mock(Environment::class);
    $client->shouldReceive('server->sites->environment')->once()->andReturn($environment);
    $stored = "APP_ENV=production\n";
    $reads = 0;
    $environment->shouldReceive('get')->andReturnUsing(function () use (&$stored, &$reads, $mode): Response {
        $reads++;
        $response = m::mock(Response::class);
        $json = $reads > 1 && $mode === 'unreadable' ? (object) [] : (object) ['data' => (object) ['content' => $stored]];
        $response->shouldReceive('getJson')->once()->andReturn($json);
        return $response;
    });
    $environment->shouldReceive('update')->once()->andReturnUsing(function (string $content) use (&$stored, $mode): Response {
        if ($mode !== 'ignored') {
            $stored = $content;
        }
        return m::mock(Response::class);
    });
    $provider = new class($client) extends PloiProvider {
        public function __construct(private Ploi $client) { parent::__construct(); }
        protected function getClient(): Ploi { return $this->client; }
    };
    $project = new class {
        public function environment(): object { return new class {
            public function variables(): array { return ['APP_NAME' => 'literal-${PROJECT_NAME}-secret', 'COUNT' => 7, 'EMPTY' => '']; }
        }; }
    };
    $result = (new ReflectionMethod($provider, 'applyEnvironment'))->invoke($provider, $project, new stdClass);
    expect($result['success'])->toBe($mode === 'valid')
        ->and($result['message'])->not->toContain('literal-${PROJECT_NAME}-secret');
    if ($mode === 'valid') {
        expect($reads)->toBe(2);
    }
})->with(['ignored', 'unreadable', 'valid']);
