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


test('resolved Ploi database name collisions stop before database API calls', function (): void {
    $project = new class {
        public function name(): string { return 'api'; }
        public function databases(): array { return array_map(static fn (string $name): object => new class($name) {
            public function __construct(private string $databaseName) {}
            public function name(): string { return $this->databaseName; }
            public function user(): string { return 'fixture_user'; }
        }, ['${PROJECT_NAME}_${PROFILE}', 'api_production']); }
    };
    $profile = new class { public function name(): string { return 'production'; } };
    $server = m::mock(Server::class);
    $server->shouldNotReceive('databases');
    $provider = new PloiProvider(['database_environment_prefixes' => ['${PROJECT_NAME}_${PROFILE}' => 'DB', 'api_production' => 'OTHER_DB']]);
    expect(fn () => (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $server, 55, $project, $profile))
        ->toThrow(InvalidArgumentException::class, 'Resolved Ploi database names must be unique.');
    expect($provider->validate($project, $profile))->toContain('Resolved Ploi database names must be unique.');
    expect(fn () => $provider->plan($project, $profile))->toThrow(InvalidArgumentException::class, 'Resolved Ploi database names must be unique.');
    expect($provider->apply($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Resolved Ploi database names must be unique.');
    expect($provider->destroy($project, $profile))->toBeFalse()
        ->and($provider->getLastError())->toContain('Resolved Ploi database names must be unique.');
});

test('explicit Ploi connection passwords reach creation and generated environment unchanged', function (): void {
    $secret = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@';
    $profile = new class($secret) {
        public function __construct(private string $secret) {}
        public function name(): string { return 'production'; }
        public function environment(): object { return new class($this->secret) {
            public function __construct(private string $secret) {}
            public function variables(): array { return ['DB_PASSWORD' => $this->secret]; }
        }; }
    };
    $project = new class {
        public function name(): string { return 'api'; }
        public function databases(): array { return ploiDatabaseProject()->databases(); }
        public function environment(): object { return new class {
            public function variables(): array { return ['DB_PASSWORD' => 'project-default-secret']; }
        }; }
    };
    $server = m::mock(Server::class);
    $databases = m::mock(Database::class);
    $page = m::mock(Response::class);
    $page->shouldReceive('getJson')->andReturn((object) ['data' => [], 'meta' => (object) ['last_page' => 1]]);
    $databases->shouldReceive('page')->twice()->andReturn($page);
    $passwords = [];
    $databases->shouldReceive('create')->twice()->andReturnUsing(function ($name, $user, $password) use (&$passwords): Response {
        $passwords[$name] = $password;
        $response = m::mock(Response::class);
        $response->shouldReceive('getJson')->once()->andReturn((object) ['data' => (object) ['id' => count($passwords), 'name' => $name]]);
        return $response;
    });
    $server->shouldReceive('databases')->times(4)->andReturn($databases);
    $provider = new PloiProvider(['database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']]);
    expect((new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $server, 55, $project, $profile))->toBeTrue();
    $environment = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
    expect($passwords['main'])->toBe($secret)->and($environment['DB_PASSWORD'])->toBe($secret)
        ->and($passwords['analytics'])->not->toBe($secret)
        ->and($environment['ANALYTICS_DB_PASSWORD'])->toBe($passwords['analytics']);
});

test('invalid explicit Ploi passwords stop before provisioning any database', function (mixed $password): void {
    $profile = new class($password) {
        public function __construct(private mixed $password) {}
        public function environment(): object { return new class($this->password) {
            public function __construct(private mixed $password) {}
            public function variables(): array { return ['ANALYTICS_DB_PASSWORD' => $this->password]; }
        }; }
    };
    $server = m::mock(Server::class);
    $server->shouldNotReceive('databases');
    $provider = new PloiProvider(['database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']]);
    expect(fn () => (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $server, 55, ploiDatabaseProject(), $profile))
        ->toThrow(InvalidArgumentException::class, 'Explicit Ploi database passwords must be nonempty strings.');
    expect($provider->validate(ploiDatabaseProject(), $profile))->toContain('Explicit Ploi database passwords must be nonempty strings.');
    expect($provider->apply(ploiDatabaseProject(), $profile))->toBeFalse()
        ->and($provider->getLastError())->toBe('Deployment error: Explicit Ploi database passwords must be nonempty strings. (Type: InvalidArgumentException)');
})->with([[''], [null], [false], [123]]);
