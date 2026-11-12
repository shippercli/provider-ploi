<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ShipperCli\ProviderPloi\PloiApiClient;
use ShipperCli\ProviderPloi\PloiProvider;
use ShipperCli\ProviderPloi\PloiServerLifecycleClient;

it('supplies current explicit server defaults rather than legacy SDK defaults', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(201, [], '{"data":{"id":123}}')]));
    $stack->push(Middleware::history($history));
    $client = new PloiServerLifecycleClient(new PloiApiClient('synthetic', new Client(['handler' => $stack])));
    expect($client->create('fixture', 42, 'region', 'plan'))->toBe(123);
    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body['php_version'])->toBe('8.4')->and($body['database_type'])->toBe('mysql84');
});

it('rejects retired or wrongly typed server options during validation', function (string $field, mixed $value): void {
    $provider = new PloiProvider;
    $server = ['mode' => 'create', 'spec' => ['name' => 'fixture', 'credential' => 42, 'region' => 'region', 'plan' => 'plan', $field => $value]];
    $errors = (new ReflectionMethod($provider, 'validateServerLifecycle'))->invoke($provider, $server);
    expect(implode(' ', $errors))->toContain('spec.'.$field);
})->with([
    ['os_type', 'ubuntu-22-04-lts'], ['os_type', null],
    ['database_type', 'mysql80'], ['database_type', 'mariadb112'], ['database_type', 'postgresql13'],
    ['php_version', 8.4], ['php_version', '9.9'],
    ['ip_type', 'dual'], ['install_monitoring', 'false'],
]);

it('inherits project PHP for creation while explicit server PHP still wins', function (?string $configured, string $expected): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(201, [], '{"data":{"id":123}}')]));
    $stack->push(Middleware::history($history));
    $lifecycle = new PloiServerLifecycleClient(new PloiApiClient('synthetic', new Client(['handler' => $stack])));
    $provider = new PloiProvider([], $lifecycle);
    $project = new class {
        public function name(): string { return 'fixture'; }
        public function phpVersion(): string { return '8.5'; }
    };
    $profile = new class { public function name(): string { return 'production'; } };
    $spec = ['name' => 'fixture', 'credential' => 42, 'region' => 'region', 'plan' => 'plan'];
    if ($configured !== null) $spec['php_version'] = $configured;
    expect((new ReflectionMethod($provider, 'createServerFromLifecycle'))->invoke($provider, $project, $profile, ['mode' => 'create', 'spec' => $spec]))->toBe(123);
    $body = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
    expect($body['php_version'])->toBe($expected);
})->with([[null, '8.5'], ['8.3', '8.3'], ['none', 'none']]);

it('accepts current explicitly typed provisioning options', function (): void {
    $server = ['mode' => 'create', 'spec' => [
        'name' => 'fixture', 'credential' => 42, 'region' => 'region', 'plan' => 'plan',
        'type' => 'server', 'os_type' => 'ubuntu-24-04-lts', 'database_type' => 'mysql9',
        'php_version' => '8.5', 'webserver_type' => 'nginx', 'ip_type' => 'ipv4-ipv6', 'install_monitoring' => false,
    ]];
    expect((new ReflectionMethod(PloiProvider::class, 'validateServerLifecycle'))->invoke(new PloiProvider, $server))->toBeEmpty();
});

it('refuses invalid creation options before sending a create request', function (): void {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([]));
    $stack->push(Middleware::history($history));
    $lifecycle = new PloiServerLifecycleClient(new PloiApiClient('synthetic', new Client(['handler' => $stack])));
    $provider = new PloiProvider([], $lifecycle);
    $failure = null;
    try {
        (new ReflectionMethod($provider, 'createServerFromLifecycle'))->invoke($provider, new stdClass, new stdClass,
            ['mode' => 'create', 'spec' => ['name' => 'fixture', 'credential' => 42, 'region' => 'region', 'plan' => 'plan', 'os_type' => 'ubuntu-22-04-lts']]);
    } catch (RuntimeException $exception) { $failure = $exception; }
    expect($failure)->toBeInstanceOf(RuntimeException::class)->and($history)->toBeEmpty();
});
