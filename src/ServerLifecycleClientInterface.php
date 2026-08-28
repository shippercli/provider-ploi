<?php

declare(strict_types=1);

namespace ShipperCli\ProviderPloi;

interface ServerLifecycleClientInterface
{
    /** @return array<int, object> */
    public function list(): array;

    public function get(int $serverId): object;

    /** @param array<string, mixed> $options */
    public function create(string $name, int $credential, string $region, string $plan, array $options = []): int;

    public function delete(int $serverId): void;
}
