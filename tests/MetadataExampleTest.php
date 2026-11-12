<?php

use ShipperCli\ProviderPloi\PloiProvider;

it('website metadata example validates for an existing static server', function (): void {
    $metadata = json_decode(file_get_contents(__DIR__.'/../meta.json'), true, 512, JSON_THROW_ON_ERROR);
    $config = array_map(static fn ($value) => is_string($value) ? strtr($value, ['${PLOI_API_KEY}' => 'synthetic-token']) : $value, $metadata['config']);
    $project = new class {
        public function name(): string { return 'fixture'; }
        public function repository(): array { return ['provider' => 'github', 'name' => 'example/app']; }
    };
    $profile = new class { public function get(string $key): mixed { return $key === 'domain' ? 'fixture.test' : null; } };
    $provider = new class($config) extends PloiProvider {
        protected function getClient(): \Ploi\Ploi { throw new RuntimeException('Metadata validation must not access the API'); }
    };
    expect($provider->validate($project, $profile))->toBe([]);
});
