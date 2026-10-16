<?php

declare(strict_types=1);

namespace ShipperCli\ProviderPloi;

use Ploi\Ploi;

final class PloiServerLifecycleClient implements ServerLifecycleClientInterface
{
    private readonly \Closure $pause;

    public function __construct(private readonly Ploi $client, private readonly int $deleteWaitSeconds = 30, ?callable $pause = null)
    {
        if ($deleteWaitSeconds < 0) throw new \InvalidArgumentException('Ploi deletion verification window must be nonnegative.');
        $this->pause = $pause === null ? static fn () => \sleep(1) : \Closure::fromCallable($pause);
    }

    public function list(): array
    {
        $servers = [];
        $seenIds = [];
        $page = 1;
        $lastPage = null;
        $hasTotal = null;
        $expectedTotal = null;
        do {
            $json = $this->client->server()->page($page, 50)->getJson();
            $this->assertSuccessfulEnvelope($json);
            $data = $json->data ?? null;
            $meta = $json->meta ?? null;
            if (! \is_array($data) || ! \array_is_list($data) || ! \is_object($meta)) {
                throw new \RuntimeException('Ploi server inventory has an invalid list envelope');
            }
            $reportedLast = $this->serverId($meta->last_page ?? null);
            $current = \property_exists($meta, 'current_page') ? $this->serverId($meta->current_page) : $page;
            if ($reportedLast === null || $reportedLast < $page || $current !== $page
                || ($lastPage !== null && $reportedLast !== $lastPage)) {
                throw new \RuntimeException('Ploi server inventory has invalid or changing pagination metadata');
            }
            $reportsTotal = \property_exists($meta, 'total');
            if ($hasTotal !== null && $reportsTotal !== $hasTotal) {
                throw new \RuntimeException('Ploi server inventory has changing total metadata');
            }
            $hasTotal = $reportsTotal;
            if ($reportsTotal) {
                $total = (\is_int($meta->total) || \is_string($meta->total))
                    ? \filter_var($meta->total, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) : false;
                if ($total === false || ($expectedTotal !== null && $total !== $expectedTotal)) {
                    throw new \RuntimeException('Ploi server inventory has invalid or changing total metadata');
                }
                $expectedTotal = $total;
            }
            $lastPage = $reportedLast;
            foreach ($data as $server) {
                $id = \is_object($server) ? $this->serverId($server->id ?? null) : null;
                $name = \is_object($server) ? ($server->name ?? null) : null;
                if ($id === null || isset($seenIds[$id]) || ! \is_string($name)
                    || $name === '' || \trim($name) !== $name) {
                    throw new \RuntimeException('Ploi server inventory contains an invalid or duplicate identity');
                }
                $seenIds[$id] = true;
                $servers[] = $server;
            }
            $page++;
        } while ($page <= $lastPage);

        if ($expectedTotal !== null && \count($servers) !== $expectedTotal) {
            throw new \RuntimeException('Ploi server inventory does not match its reported total');
        }
        return $servers;
    }

    public function get(int $serverId): object
    {
        $json = $this->client->server($serverId)->get()->getJson();
        $data = $json->data ?? null;
        if (! \is_object($json) || ! \is_object($data) || $this->serverId($data->id ?? null) !== $serverId
            || ($json->errors ?? []) !== [] || ! \in_array($json->error ?? null, [null, '', false], true)
            || (\property_exists($json, 'success') && $json->success !== true)) {
            throw new \RuntimeException("Ploi server {$serverId} was not found or returned an invalid response");
        }

        return $data;
    }

    public function create(string $name, int $credential, string $region, string $plan, array $options = []): int
    {
        // Override the SDK's legacy PHP 7.4 and unversioned MySQL defaults.
        $options = array_merge(['php_version' => '8.4', 'database_type' => 'mysql84'], $options);
        $json = $this->client->server()->create($name, $credential, $region, $plan, $options)->getJson();
        $this->assertSuccessfulEnvelope($json);
        $data = $json->data ?? null;
        if (! \is_object($data) || $this->serverId($data->id ?? null) === null) {
            throw new \RuntimeException('Failed to create Ploi server: invalid response from API');
        }

        return (int) $data->id;
    }

    public function delete(int $serverId): void
    {
        try {
            $existing = $this->get($serverId);
        } catch (\Ploi\Exceptions\Http\NotFound) {
            return;
        }
        if (($existing->status ?? null) === 'destroying') {
            $this->waitForServerAbsence($serverId);
            return;
        }
        $json = $this->client->server($serverId)->delete()->getJson();
        $data = $json->data ?? null;
        $message = $json->message ?? null;
        $failureMessage = $message !== null && (! \is_string($message)
            || \str_contains(\strtolower($message), 'error') || \str_contains(\strtolower($message), 'failed'));
        if ($failureMessage || ! \is_object($json) || ! \is_object($data)
            || $this->serverId($data->id ?? null) !== $serverId
            || ($data->status ?? null) !== 'destroying'
            || ($json->errors ?? []) !== []
            || ! \in_array($json->error ?? null, [null, '', false], true)
            || (\property_exists($json, 'success') && $json->success !== true)) {
            throw new \RuntimeException('Ploi server deletion acknowledgement is invalid or contradictory.');
        }
        $this->waitForServerAbsence($serverId);
    }

    private function waitForServerAbsence(int $serverId): void
    {
        $deadline = \microtime(true) + $this->deleteWaitSeconds;
        do {
            try {
                $this->get($serverId);
            } catch (\Ploi\Exceptions\Http\NotFound) {
                return;
            }
            if (\microtime(true) >= $deadline) {
                throw new \RuntimeException('Ploi server deletion remains unconfirmed; the server is still visible. Recheck status before retrying.');
            }
            ($this->pause)();
        } while (true);
    }

    private function assertSuccessfulEnvelope(mixed $json): void
    {
        if (! \is_object($json) || ($json->errors ?? []) !== []
            || ! \in_array($json->error ?? null, [null, '', false], true)
            || (\property_exists($json, 'success') && $json->success !== true)) {
            throw new \RuntimeException('Ploi server response is invalid or contradictory.');
        }
    }

    private function serverId(mixed $value): ?int
    {
        $id = (\is_int($value) || \is_string($value))
            ? \filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        return $id === false ? null : $id;
    }

}
