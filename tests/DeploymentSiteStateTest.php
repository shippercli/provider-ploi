<?php

use ShipperCli\ProviderPloi\PloiProvider;

test('deployment state follows documented status without trusting a missing deploying flag', function (string $status, ?bool $deploying, string $expected): void {
    $site = (object) ['id' => '55', 'server_id' => '123', 'status' => $status];
    if ($deploying !== null) $site->deploying = $deploying;
    $provider = new PloiProvider;
    expect((new ReflectionMethod($provider, 'deploymentSiteState'))->invoke($provider, (object) ['data' => $site], 123, 55))->toBe($expected);
})->with([
    ['active', null, 'ready'], ['active', false, 'ready'], ['active', true, 'pending'],
    ['deploying', null, 'pending'], ['deploying', false, 'pending'],
    ['building', null, 'pending'], ['repository-installing', null, 'pending'],
    ['deploy-failed', null, 'failed'],
]);
