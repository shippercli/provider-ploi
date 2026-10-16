<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use GuzzleHttp\Client;
use ShipperCli\ProviderPloi\PloiApiClient;


$http = new Client(['base_uri' => $argv[1].'/', 'http_errors' => false,
    'headers' => ['Authorization' => 'Bearer synthetic-authorization-secret']]);
$client = new PloiApiClient('synthetic-authorization-secret', $http);
foreach ([301, 302, 303, 307, 308, 400, 401, 403, 404, 405, 422, 429, 500, 502, 503] as $status) {
    foreach (['json', 'text'] as $format) {
        foreach (['get', 'post'] as $method) {
            $failure = null;
            try {
                $client->makeAPICall("status-{$status}-{$format}", $method, $method === 'post' ? ['json' => ['password' => 'synthetic-body-secret']] : []);
            } catch (Throwable $exception) { $failure = $exception; }
            if ($failure === null) throw new RuntimeException("Non-success accepted: {$status}/{$format}/{$method}");
            $diagnostic = $failure->getMessage();
            foreach (['synthetic-authorization-secret', 'synthetic-body-secret', 'synthetic-error-secret'] as $secret) {
                if (str_contains($diagnostic, $secret)) throw new RuntimeException('Error response leaked synthetic credentials');
            }
        }
    }
}
foreach (['direct', 'nested'] as $format) {
    $data = json_decode(json_encode($client->makeAPICall('success-'.$format)->getJson()), true);
    $expected = $format === 'direct' ? ['json' => ['accepted' => true]] : ['result' => ['data' => ['json' => ['accepted' => true]]]];
    if ($data !== $expected) throw new RuntimeException('Successful response decoding changed');
}
echo "60 HTTP rejection checks and two success controls passed\n";

(new \ShipperCli\ProviderPloi\PloiServerLifecycleClient($client, 0))->delete(7);
echo "Real HTTP deletion completed only after matching acknowledgement and subsequent 404\n";

foreach (['error', 'errors', 'false', 'string', 'null', 'valid'] as $case) {
    foreach (['list', 'create'] as $operation) {
        $lifecycle = new \ShipperCli\ProviderPloi\PloiServerLifecycleClient(new PloiApiClient(
            'synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
                'headers' => ['X-Fixture-Case' => $case]])));
        $failure = null;
        try {
            $result = $operation === 'list' ? $lifecycle->list() : $lifecycle->create('fixture', 42, 'region', 'plan');
        } catch (RuntimeException $exception) { $failure = $exception; }
        if ($case === 'valid') {
            if ($failure !== null || ($operation === 'list' ? count($result) !== 1 : $result !== 123)) {
                throw new RuntimeException('Valid lifecycle envelope rejected');
            }
        } elseif ($failure === null || $failure->getMessage() !== 'Ploi server response is invalid or contradictory.') {
            throw new RuntimeException('Contradictory lifecycle envelope accepted or exposed');
        }
    }
}
echo "12 real HTTP lifecycle envelope checks passed\n";

foreach (['absent', 'visible', 'recreated', 'renamed', 'unreadable', 'error'] as $case) {
    $api = new PloiApiClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => $case]]));
    $failure = null;
    try {
        (new ReflectionMethod(\ShipperCli\ProviderPloi\PloiProvider::class, 'deleteDatabaseAndVerify'))
            ->invoke(new \ShipperCli\ProviderPloi\PloiProvider, $api->server(123), 9, 'fixture');
    } catch (RuntimeException $exception) { $failure = $exception; }
    if (($case === 'absent') !== ($failure === null)) throw new RuntimeException('Invalid database deletion verification: '.$case);
    if ($failure !== null && str_contains($failure->getMessage(), 'synthetic-authorization-secret')) {
        throw new RuntimeException('Database deletion failure echoed credentials');
    }
}
echo "Six real HTTP database deletion verification cases passed\n";

foreach (['absent', 'already-absent', 'visible', 'recreated', 'renamed', 'unreadable', 'error', 'false', 'missing-message', 'failed-message'] as $case) {
    $api = new PloiApiClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => $case]]));
    $failure = null;
    try {
        (new ReflectionMethod(\ShipperCli\ProviderPloi\PloiProvider::class, 'deleteSiteAndVerify'))
            ->invoke(new \ShipperCli\ProviderPloi\PloiProvider, $api->server(123), 55);
    } catch (RuntimeException $exception) { $failure = $exception; }
    if (in_array($case, ['absent', 'already-absent'], true) !== ($failure === null)) {
        throw new RuntimeException('Invalid site deletion verification: '.$case);
    }
    if ($failure !== null && str_contains($failure->getMessage(), 'synthetic-authorization-secret')) {
        throw new RuntimeException('Site deletion failure echoed credentials');
    }
}
echo "Ten real HTTP site deletion verification cases passed\n";
