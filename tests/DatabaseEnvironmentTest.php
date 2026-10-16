<?php

use Mockery as m;
use Ploi\Resources\Server;
use Ploi\Resources\Database;
use Ploi\Http\Response;
use ShipperCli\ProviderPloi\PloiProvider;

function ploiDatabaseProject(): object
{
    return new class {
        public function name(): string { return 'api'; }
        public function databases(): array {
            return array_map(static fn (string $name): object => new class($name) {
                public function __construct(private string $database) {}
                public function name(): string { return $this->database; }
                public function user(): string { return '${PROJECT_NAME}_${PROFILE}_'.$this->database; }
            }, ['main', 'analytics']);
        }
    };
}

test('all new Ploi databases deliver distinct connection environments and retain ownership', function (): void {
    $server = m::mock(Server::class);
    $databases = m::mock(Database::class);
    $page = m::mock(Response::class);
    $page->shouldReceive('getJson')->andReturn((object) ['data' => [], 'meta' => (object) ['last_page' => 1]]);
    $databases->shouldReceive('page')->with(1, 50)->twice()->andReturn($page);
    $passwords = [];
    $databases->shouldReceive('create')->twice()->andReturnUsing(function (string $name, string $user, string $password, mixed $description, int $site) use (&$passwords): Response {
        expect($site)->toBe(55)->and($user)->toBe('api_production_'.$name);
        $passwords[$name] = $password;
        $created = m::mock(Response::class);
        $created->shouldReceive('getJson')->once()->andReturn((object) ['data' => (object) ['id' => count($passwords), 'name' => $name]]);
        return $created;
    });
    $server->shouldReceive('databases')->times(4)->andReturn($databases);
    $provider = new PloiProvider(['database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']]);
    (new ReflectionProperty($provider, 'generatedEnvironment'))->setValue($provider, ['SHIPPERCLI_OWNERSHIP' => 'retained-marker']);
    $profile = new class { public function name(): string { return 'production'; } };
    expect((new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $server, 55, ploiDatabaseProject(), $profile))->toBeTrue();
    $env = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
    expect($env['DB_PASSWORD'])->toBe($passwords['main'])
        ->and($env['ANALYTICS_DB_PASSWORD'])->toBe($passwords['analytics'])
        ->and($passwords['main'])->not->toBe($passwords['analytics'])
        ->and($env['SHIPPERCLI_OWNERSHIP'])->toBe('retained-marker');
});

test('ambiguous Ploi database mappings fail before database API calls', function (array $prefixes): void {
    $server = m::mock(Server::class);
    $server->shouldNotReceive('databases');
    $provider = new PloiProvider(['database_environment_prefixes' => $prefixes]);
    (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $server, 55, ploiDatabaseProject(), new stdClass);
})->with([[[]], [['main' => 'DB', 'analytics' => 'DB']]])->throws(InvalidArgumentException::class);

test('existing Ploi databases require available matching application credentials without rotation', function (string $content, bool $valid): void {
    $server = m::mock(Server::class);
    $databases = m::mock(Database::class);
    $site = m::mock(\Ploi\Resources\Site::class);
    $page = m::mock(Response::class);
    $page->shouldReceive('getJson')->andReturn((object) ['data' => [(object) ['id' => 9, 'name' => 'main', 'site' => (object) ['id' => 55]]], 'meta' => (object) ['last_page' => 1]]);
    $databases->shouldReceive('page')->with(1, 50)->once()->andReturn($page);
    $databases->shouldNotReceive('create');
    $server->shouldReceive('databases')->once()->andReturn($databases);
    $server->shouldReceive('sites')->with(55)->once()->andReturn($site);
    $site->shouldReceive('environment->get->getJson')->once()->andReturn((object) ['data' => (object) ['content' => $content]]);
    $project = new class {
        public function name(): string { return 'api'; }
        public function databases(): array { return [new class {
            public function name(): string { return 'main'; }
            public function user(): string { return 'api'; }
        }]; }
    };
    $provider = new PloiProvider;
    $result = (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $server, 55, $project, new stdClass);
    expect($result)->toBe($valid)
        ->and((new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider))->toBe([])
        ->and($provider->getLastError())->not->toContain('retained-secret');
})->with([
    ["DB_HOST=127.0.0.1\nDB_DATABASE=main\nDB_USERNAME=api\nDB_PASSWORD='retained-secret'", true],
    ["DB_HOST=127.0.0.1\nDB_DATABASE=main\nDB_USERNAME=api", false],
    ["DB_HOST=127.0.0.1\nDB_DATABASE=other\nDB_USERNAME=api\nDB_PASSWORD=retained-secret", false],
    ["DB_HOST=127.0.0.1\nDB_DATABASE=main\nDB_USERNAME=api\nDB_PASSWORD=''", false],
    ["DB_PASSWORD=\"unterminated", false],
]);


test('database creation refuses unconfirmed identities before recording credentials', function (mixed $json): void {
    $server = m::mock(Server::class);
    $databases = m::mock(Database::class);
    $page = m::mock(Response::class);
    $created = m::mock(Response::class);
    $page->shouldReceive('getJson')->andReturn((object) ['data' => [], 'meta' => (object) ['last_page' => 1]]);
    $databases->shouldReceive('page')->with(1, 50)->once()->andReturn($page);
    $databases->shouldReceive('create')->once()->andReturn($created);
    $created->shouldReceive('getJson')->once()->andReturn($json);
    $server->shouldReceive('databases')->twice()->andReturn($databases);
    $provider = new PloiProvider(['database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']]);
    $failure = null;
    try {
        (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $server, 55, ploiDatabaseProject(), new stdClass);
    } catch (RuntimeException $error) {
        $failure = $error;
    }
    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getMessage())->toBe('Ploi did not confirm the requested database creation identity.')
        ->and((new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider))->toBe([]);
})->with([
    [null], [(object) []],
    [(object) ['data' => (object) ['id' => 0, 'name' => 'main']]],
    [(object) ['data' => (object) ['id' => 9, 'name' => 'foreign']]],
    [(object) ['data' => (object) ['id' => 9, 'name' => 'main', 'site' => (object) ['id' => 56]]]],
    [(object) ['success' => false, 'data' => (object) ['id' => 9, 'name' => 'main']]],
    [(object) ['errors' => ['synthetic-secret'], 'data' => (object) ['id' => 9, 'name' => 'main']]],
]);
