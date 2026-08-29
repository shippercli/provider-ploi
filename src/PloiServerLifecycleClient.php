<?php

declare(strict_types=1);

namespace ShipperCli\ProviderPloi;

use Ploi\Ploi;

final class PloiServerLifecycleClient implements ServerLifecycleClientInterface
{
    public function __construct(private readonly Ploi $client) {}

    public function list(): array
    {
        $data = $this->client->server()->get()->getJson()->data ?? null;
        if (! \is_array($data)) {
            throw new \RuntimeException('Ploi returned an invalid server list response');
        }

        return \array_values(\array_filter($data, \is_object(...)));
    }

    public function get(int $serverId): object
    {
        $data = $this->client->server($serverId)->get()->getJson()->data ?? null;
        if (! \is_object($data) || ! \property_exists($data, 'id') || (int) $data->id !== $serverId) {
            throw new \RuntimeException("Ploi server {$serverId} was not found or returned an invalid response");
        }

        return $data;
    }

    public function create(string $name, int $credential, string $region, string $plan, array $options = []): int
    {
        $data = $this->client->server()->create($name, $credential, $region, $plan, $options)->getJson()->data ?? null;
        if (! \is_object($data) || ! \property_exists($data, 'id') || (int) $data->id <= 0) {
            throw new \RuntimeException('Failed to create Ploi server: invalid response from API');
        }

        return (int) $data->id;
    }

    public function delete(int $serverId): void
    {
        $message = $this->client->server($serverId)->delete()->getJson()->message ?? null;
        if (\is_string($message)) {
            $message = \strtolower($message);
            if (\str_contains($message, 'error') || \str_contains($message, 'failed')) {
                throw new \RuntimeException("Failed to delete Ploi server {$serverId}: {$message}");
            }
        }
    }
}
