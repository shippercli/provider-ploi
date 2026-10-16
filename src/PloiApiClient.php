<?php

declare(strict_types=1);

namespace ShipperCli\ProviderPloi;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Ploi\Http\Response;
use Ploi\Ploi;
use RuntimeException;

/** Provider transport policy without changing the upstream SDK resource classes. */
final class PloiApiClient extends Ploi
{
    private readonly Client $http;

    public function __construct(string $token, ?Client $http = null)
    {
        parent::__construct($token);
        $this->http = $http ?? new Client(['base_uri' => 'https://ploi.io/api/']);
    }

    public function __debugInfo(): array
    {
        return ['apiToken' => '[redacted]', 'transport' => 'provider-managed'];
    }

    public function makeAPICall(string $url, string $method = 'get', array $options = []): Response
    {
        if (! in_array($method, ['get', 'post', 'patch', 'delete'], true)) {
            throw new RuntimeException('Invalid Ploi API method.');
        }
        $options['allow_redirects'] = false;
        $options['http_errors'] = false;
        $options['timeout'] = 30;
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer '.$this->getApiToken(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        try {
            $response = $this->http->request(strtoupper($method), $url, $options);
        } catch (GuzzleException) {
            throw new RuntimeException('Ploi API transport failed; check connectivity, TLS, and timeout settings.');
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $exception = match ($status) {
                401 => \Ploi\Exceptions\Http\Unauthenticated::class,
                404 => \Ploi\Exceptions\Http\NotFound::class,
                405 => \Ploi\Exceptions\Http\NotAllowed::class,
                422 => \Ploi\Exceptions\Http\NotValid::class,
                429 => \Ploi\Exceptions\Http\TooManyAttempts::class,
                500 => \Ploi\Exceptions\Http\InternalServerError::class,
                503 => \Ploi\Exceptions\Http\PerformingMaintenance::class,
                default => RuntimeException::class,
            };
            throw new $exception('Ploi API request failed (HTTP '.$status.').');
        }
        return new Response($response);
    }
}
