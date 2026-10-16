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
    $created = m::mock(Response::class);
    $page->shouldReceive('getJson')->andReturn((object) ['data' => [], 'meta' => (object) ['last_page' => 1]]);
    $databases->shouldReceive('page')->with(1, 50)->twice()->andReturn($page);
    $passwords = [];
    $databases->shouldReceive('create')->twice()->andReturnUsing(function (string $name, string $user, string $password, mixed $description, int $site) use (&$passwords, $created): Response {
        expect($site)->toBe(55)->and($user)->toBe('api_production_'.$name);
        $passwords[$name] = $password;
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
