<?php

use ShipperCli\ProviderPloi\PloiProvider;

function ploiTypeProject(string $type): object
{
    return new class($type) {
        public function __construct(private string $type) {}
        public function databases(): array { return [new class($this->type) {
            public function __construct(private string $type) {}
            public function name(): string { return 'main'; }
            public function type(): string { return $this->type; }
        }]; }
    };
}

test('unsupported Ploi database declaration types fail before API access', function (string $type): void {
    $provider = new class extends PloiProvider {
        public int $apiAccesses = 0;
        protected function getClient(): \Ploi\Ploi {
            $this->apiAccesses++;
            throw new LogicException('Unexpected API access');
        }
    };
    $project = ploiTypeProject($type);
    expect($provider->validate($project, new stdClass))->toContain('Ploi database declarations require mysql, mariadb or PostgreSQL.');
    expect($provider->apply($project, new stdClass))->toBeFalse()
        ->and($provider->getLastError())->toContain('Ploi database declarations require mysql, mariadb or PostgreSQL.');
    expect(fn () => $provider->plan($project, new stdClass))->toThrow(InvalidArgumentException::class,
        'Ploi database declarations require mysql, mariadb or PostgreSQL.');
    expect($provider->apiAccesses)->toBe(0);
})->with(['redis', 'sqlite', 'mongodb']);


test('known SQL declarations pass the local Ploi type check', function (string $type): void {
    expect((new PloiProvider)->validate(ploiTypeProject($type), new stdClass))
        ->not->toContain('Ploi database declarations require mysql, mariadb or PostgreSQL.');
})->with(['mysql', 'mariadb', 'postgres', 'postgresql', 'pgsql']);
