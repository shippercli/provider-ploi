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
