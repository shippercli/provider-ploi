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
        $body = (string) $response->getBody();
        if ($body !== '' || ($method !== 'delete' && $status !== 204)) {
            try {
                $decoded = \json_decode($body, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Ploi API returned invalid JSON.');
            }
            if (! $decoded instanceof \stdClass) {
                throw new RuntimeException('Ploi API returned invalid JSON.');
            }
        }
        // Preserve the validated bytes even if the upstream body was not seekable.
        $result = new Response($response->withBody(\GuzzleHttp\Psr7\Utils::streamFor($body)));
        if ($method === 'post' && $url === 'servers') {
            // The SDK sets a weakly typed integer resource ID before returning.
            $rawId = $result->getJson()->data->id ?? null;
            $id = (is_int($rawId) || is_string($rawId))
                ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($id === false) {
                throw new RuntimeException('Ploi server creation returned an invalid response identity.');
            }
        }
        return $result;
    }
}
