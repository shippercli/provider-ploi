<?php

declare(strict_types=1);

namespace ShipperCli\ProviderPloi;

use Ploi\Ploi;
use ShipperCli\Contracts\DeploymentLogsProviderInterface;
use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\DeploymentStatusProviderInterface;
use ShipperCli\Contracts\ProviderCapabilitiesInterface;

class PloiProvider implements DeploymentLogsProviderInterface, DeploymentProviderInterface, DeploymentStatusProviderInterface, ProviderCapabilitiesInterface
{
    private const MANAGED_SERVER_PREFIX = 'shipper';

    private const LOG_FETCH_DELAY_SECONDS = 5;

    private const MAX_ERROR_LOG_ENTRIES = 10;

    /** @var array<string, mixed> */
    private readonly array $config;

    private ?Ploi $client = null;

    private ?ServerLifecycleClientInterface $serverLifecycleClient;

    private string $lastError = '';

    private int $lastServerId = 0;

    private int $lastSiteId = 0;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [], ?ServerLifecycleClientInterface $serverLifecycleClient = null)
    {
        $this->config = $config;
        $this->serverLifecycleClient = $serverLifecycleClient;
    }

    public function getName(): string
    {
        return 'ploi';
    }

    public function capabilities(): array
    {
        return [
            'app_deploy' => ['state' => 'supported'],
            'server_lifecycle' => ['state' => 'partial', 'notes' => 'Server creation and ownership-safe cleanup follow the Ploi API structure and require live account verification.'],
            'domain_management' => ['state' => 'supported'],
            'ssl' => ['state' => 'supported'],
            'databases' => ['state' => 'supported'],
            'profiles' => ['state' => 'supported'],
            'background_workloads' => ['state' => 'partial'],
            'env' => ['state' => 'supported'],
            'observability' => ['state' => 'partial'],
            'rollback' => ['state' => 'unsupported'],
            'previews' => ['state' => 'partial'],
        ];
    }

    public function validate(object $project, object $profile): array
    {
        $errors = [];

        $apiKey = $this->config['api_key'] ?? null;
        if (! \is_string($apiKey) || $apiKey === '') {
            $errors[] = 'Ploi API key is required';
        }

        $server = $this->extractServerLifecycle($profile);
        if ($server === null) {
            $serverId = $this->config['server_id'] ?? null;
            if ((! \is_string($serverId) && ! \is_int($serverId)) || (string) $serverId === '') {
                $errors[] = 'Ploi server ID is required';
            } elseif (! \ctype_digit((string) $serverId)) {
                $errors[] = 'Ploi server ID must contain only digits';
            }
        } else {
            $errors = [...$errors, ...$this->validateServerLifecycle($server)];
        }

        $domain = $this->profileValue($profile, 'domain');
        if (! \is_string($domain) || $domain === '') {
            $errors[] = 'Domain is required for profile';
        }

        $repository = $this->projectRepository($project);
        $repoProvider = $repository['provider'] ?? null;
        if (! \is_string($repoProvider) || $repoProvider === '') {
            $errors[] = 'Repository provider is required';
        }

        $repoName = $repository['name'] ?? null;
        if (! \is_string($repoName) || $repoName === '') {
            $errors[] = 'Repository name is required';
        }

        return $errors;
    }

    public function plan(object $project, object $profile): array
    {
        $server = $this->extractServerLifecycle($profile);
        $serverId = $server !== null && ($server['mode'] ?? null) === 'create' ? null : $this->getServerId($profile);
        $actions = [];

        if ($server !== null && ($server['mode'] ?? null) === 'create') {
            $serverName = $this->displayServerName($server);
            $managedServerName = $this->managedServerName($project, $profile, $server);
            $actions[] = 'Create server: '.$serverName;
            $actions[] = 'Mark created server as managed: '.$managedServerName;

            $cleanup = $server['cleanup'] ?? null;
            if (\is_string($cleanup) && $cleanup !== '') {
                $actions[] = 'Cleanup policy for created server: '.$cleanup;
            }
        } else {
            $actions[] = 'Use existing server: '.$serverId;
        }

        $domain = $this->profileValue($profile, 'domain');
        $repository = $this->projectRepository($project);
        $branch = $this->profileBranch($profile);
        $repoProvider = \is_string($repository['provider'] ?? null) ? $repository['provider'] : 'unknown';
        $repoName = \is_string($repository['name'] ?? null) ? $repository['name'] : 'unknown';

        $actions[] = 'Create or find site for domain: '.(\is_string($domain) ? $domain : '');
        $actions[] = "Install repository: {$repoProvider}:{$repoName} ({$branch})";

        foreach ($this->projectDatabases($project) as $database) {
            $dbName = $this->interpolateDatabaseName(
                $this->databaseValue($database, 'name'),
                $this->projectName($project),
                $this->profileName($profile),
            );
            $dbUser = $this->interpolateDatabaseName(
                $this->databaseValue($database, 'user'),
                $this->projectName($project),
                $this->profileName($profile),
            );
            $actions[] = "Create or find database: {$dbName} (user: {$dbUser}, type: ".$this->databaseValue($database, 'type').')';
        }

        $actions[] = 'Deploy site via Ploi API';
        $actions[] = 'Run deployment script';

        $note = $server !== null && ($server['mode'] ?? null) === 'create'
            ? 'This may provision a new Ploi server and create a real deployment.'
            : 'This will create a real deployment on Ploi server '.$serverId;

        return [
            'provider' => $this->getName(),
            'project' => $this->projectName($project),
            'profile' => $this->profileName($profile),
            'branch' => $branch,
            'path' => $this->projectPath($project),
            'server_mode' => $server['mode'] ?? 'existing',
            'server_id' => $server !== null && ($server['mode'] ?? null) === 'create' ? null : $serverId,
            'server_cleanup' => $server['cleanup'] ?? null,
            'server_spec' => $server['spec'] ?? [],
            'domain' => \is_string($domain) ? $domain : '',
            'repository' => "{$repoProvider}:{$repoName}",
            'web_directory' => $this->projectWebDirectory($project),
            'project_root' => $this->projectRoot($project),
            'actions' => $actions,
            'note' => $note,
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        $this->lastError = '';
        $serverId = 0;

        try {
            $serverId = $this->resolveServerIdForProfile($project, $profile, true);
            $domain = $this->profileValue($profile, 'domain');

            if (! \is_string($domain) || $domain === '') {
                $this->lastError = 'Domain is empty or invalid';

                return false;
            }

            $client = $this->getClient();
            $server = $client->server($serverId);
            $repository = $this->projectRepository($project);
            $branch = $this->profileBranch($profile);
            $repoProvider = \is_string($repository['provider'] ?? null) ? $repository['provider'] : '';
            $repoName = \is_string($repository['name'] ?? null) ? $repository['name'] : '';

            $sites = $server->sites()->get();
            $existingSite = null;
            $siteData = $sites->getJson()->data ?? null;
            if (\is_array($siteData)) {
                foreach ($siteData as $site) {
                    if (\is_object($site) && \property_exists($site, 'domain') && $site->domain === $domain) {
                        $existingSite = $site;
                        break;
                    }
                }
            }

            if ($existingSite === null) {
                $response = $server->sites()->create(
                    $domain,
                    $this->projectWebDirectory($project),
                    $this->projectRoot($project),
                );
                $responseData = $response->getJson()->data ?? null;
                if ($responseData === null || ! \property_exists($responseData, 'id')) {
                    $this->lastError = 'Failed to create site: Invalid response from Ploi API';

                    return false;
                }

                $siteId = (int) $responseData->id;
                $site = $server->sites($siteId);
                $site->repository()->install($repoProvider, $branch, $repoName);
            } else {
                if (! \property_exists($existingSite, 'id')) {
                    $this->lastError = 'Existing site found but has no ID';

                    return false;
                }

                $siteId = (int) $existingSite->id;
            }

            $this->lastServerId = $serverId;
            $this->lastSiteId = $siteId;

            foreach ($this->projectDatabases($project) as $database) {
                $dbName = $this->interpolateDatabaseName(
                    $this->databaseValue($database, 'name'),
                    $this->projectName($project),
                    $this->profileName($profile),
                );
                $dbUser = $this->interpolateDatabaseName(
                    $this->databaseValue($database, 'user'),
                    $this->projectName($project),
                    $this->profileName($profile),
                );

                $existingDatabases = $server->databases()->get();
                $dbData = $existingDatabases->getJson()->data ?? null;
                $exists = false;

                if (\is_array($dbData)) {
                    foreach ($dbData as $db) {
                        if (\is_object($db) && \property_exists($db, 'name') && $db->name === $dbName) {
                            $exists = true;
                            break;
                        }
                    }
                }

                if (! $exists) {
                    $server->databases()->create($dbName, $dbUser, $this->generateDatabasePassword(), null, $siteId);
                }
            }

            $site = $server->sites($siteId);
            $site->deployment()->deploy();

            $timeout = $this->getDeploymentTimeout();
            $pollInterval = 5;
            $elapsed = 0;
            $initialCheck = true;

            while ($elapsed < $timeout) {
                if (! $initialCheck) {
                    \sleep($pollInterval);
                    $elapsed += $pollInterval;
                }
                $initialCheck = false;

                $siteInfo = $server->sites($siteId)->get()->getJson()->data ?? null;
                if ($siteInfo === null) {
                    continue;
                }

                $isDeploying = \property_exists($siteInfo, 'deploying') ? (bool) $siteInfo->deploying : false;
                if ($isDeploying) {
                    continue;
                }

                $status = \property_exists($siteInfo, 'status') ? $siteInfo->status : null;
                if ($status === 'deploy-failed') {
                    $this->lastError = 'Deployment failed on Ploi server (status: deploy-failed)';
                    \sleep(self::LOG_FETCH_DELAY_SECONDS);
                    $logs = $this->getDeploymentLogs($serverId, $siteId);
                    if ($logs !== []) {
                        $this->lastError .= "\nRecent logs:\n".\implode("\n", \array_slice($logs, 0, self::MAX_ERROR_LOG_ENTRIES));
                    }

                    return false;
                }

                \sleep(self::LOG_FETCH_DELAY_SECONDS);
                foreach ($this->getDeploymentLogs($serverId, $siteId) as $log) {
                    $logLower = \strtolower($log);
                    if (
                        \str_contains($logLower, 'deployment failed') ||
                        \str_contains($logLower, 'deployment failure') ||
                        \str_contains($logLower, 'deploy failed') ||
                        \str_contains($logLower, 'fatal error') ||
                        \str_contains($logLower, 'critical error')
                    ) {
                        $this->lastError = 'Deployment failed on Ploi server (detected in logs)';

                        return false;
                    }
                }

                return true;
            }

            $this->lastError = "Deployment timeout after {$timeout} seconds. Deployment may still be running on Ploi.";

            return false;
        } catch (\Throwable $e) {
            $this->lastError = 'Deployment error: '.$e->getMessage().' (Type: '.\get_class($e).')';

            return false;
        }
    }

    public function destroy(object $project, object $profile): bool
    {
        $this->lastError = '';

        try {
            $serverConfig = $this->extractServerLifecycle($profile);
            if ($serverConfig !== null && ($serverConfig['mode'] ?? null) === 'create') {
                return $this->destroyCreatedServer($project, $profile, $serverConfig);
            }

            $server = $this->getClient()->server((int) $this->getServerId($profile));
            $domain = $this->profileValue($profile, 'domain');
            if (! \is_string($domain) || $domain === '') {
                $this->lastError = 'Domain is empty or invalid';

                return false;
            }

            $sites = $server->sites()->get();
            $existingSite = null;
            $siteData = $sites->getJson()->data ?? null;
            if (\is_array($siteData)) {
                foreach ($siteData as $site) {
                    if (\is_object($site) && \property_exists($site, 'domain') && $site->domain === $domain) {
                        $existingSite = $site;
                        break;
                    }
                }
            }

            if ($existingSite === null) {
                return true;
            }

            if (! \property_exists($existingSite, 'id')) {
                $this->lastError = 'Site found but has no ID';

                return false;
            }

            $siteId = (int) $existingSite->id;
            foreach ($this->projectDatabases($project) as $database) {
                $dbName = $this->interpolateDatabaseName(
                    $this->databaseValue($database, 'name'),
                    $this->projectName($project),
                    $this->profileName($profile),
                );
                $dbData = $server->databases()->get()->getJson()->data ?? null;

                if (\is_array($dbData)) {
                    foreach ($dbData as $db) {
                        if (\is_object($db) && \property_exists($db, 'name') && $db->name === $dbName && \property_exists($db, 'id')) {
                            try {
                                $server->databases((int) $db->id)->delete();
                            } catch (\Throwable) {
                            }
                            break;
                        }
                    }
                }
            }

            $response = $server->sites($siteId)->delete();
            $message = $response->getJson()->message ?? null;
            if (\is_string($message)) {
                $messageLower = \strtolower($message);
                if (\str_contains($messageLower, 'error') || \str_contains($messageLower, 'failed')) {
                    $this->lastError = 'Failed to delete site: '.$message;

                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastError = 'Destroy error: '.$e->getMessage().' (Type: '.\get_class($e).')';

            return false;
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Apply optional configuration after the deployment target exists.
     *
     * @return array{success: bool, message: string, logs: array<int, string>}
     */
    public function postApply(object $project, object $profile): array
    {
        if ($this->lastServerId <= 0 || $this->lastSiteId <= 0) {
            return ['success' => false, 'message' => 'Ploi deployment target is unavailable for post-apply configuration', 'logs' => []];
        }

        $operations = [
            $this->applyAliases($profile),
            $this->applyDeployScript($project, $profile),
            $this->applyEnvironment($project, $profile),
            $this->applySsl($project, $profile),
        ];

        foreach ($operations as $operation) {
            if (! ($operation['success'] ?? false)) {
                $message = $operation['message'] ?? 'Ploi post-apply configuration failed';

                return [
                    'success' => false,
                    'message' => \is_string($message) ? $message : 'Ploi post-apply configuration failed',
                    'logs' => $this->deploymentLogs($this->lastServerId, $this->lastSiteId),
                ];
            }
        }

        return [
            'success' => true,
            'message' => 'Ploi post-apply configuration completed',
            'logs' => $this->deploymentLogs($this->lastServerId, $this->lastSiteId),
        ];
    }

    public function status(object $project, object $profile): array
    {
        $serverId = $this->resolveServerIdForProfile($project, $profile);
        if ($serverId <= 0) {
            return ['provider' => 'ploi', 'state' => 'not_found', 'server_id' => null, 'site' => null];
        }

        $site = $this->findSite($serverId, $this->profileDomain($profile));

        return [
            'provider' => 'ploi',
            'state' => $site === null ? 'server_ready' : 'deployed',
            'server_id' => $serverId,
            'site' => $site,
        ];
    }

    public function logs(object $project, object $profile, int $lines = 100): array
    {
        $serverId = $this->resolveServerIdForProfile($project, $profile);
        $site = $this->findSite($serverId, $this->profileDomain($profile));
        if ($site === null || ! \property_exists($site, 'id')) {
            return [];
        }

        return \array_slice($this->deploymentLogs($serverId, (int) $site->id), -$lines);
    }

    /** @return array{success: bool, message: string} */
    protected function applyAliases(object $profile): array
    {
        $aliases = \method_exists($profile, 'aliases') ? $profile->aliases() : [];
        if (! \is_array($aliases) || $aliases === []) {
            return ['success' => true, 'message' => 'No aliases to configure'];
        }

        try {
            $this->getClient()->server($this->lastServerId)->sites($this->lastSiteId)->alias()->create($aliases);

            return ['success' => true, 'message' => 'Aliases configured successfully'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => 'Failed to configure domain aliases: '.$exception->getMessage()];
        }
    }

    /** @return array{success: bool, message: string} */
    protected function applyDeployScript(object $project, object $profile): array
    {
        $profileScript = \method_exists($profile, 'deployScript') ? $profile->deployScript() : null;
        $projectScript = \method_exists($project, 'deployScript') ? $project->deployScript() : '';
        $script = \is_string($profileScript) && $profileScript !== '' ? $profileScript : (\is_string($projectScript) ? $projectScript : '');
        if ($script === '') {
            return ['success' => true, 'message' => 'No deploy script to configure'];
        }

        $script = \str_replace(['{site}', '{branch}'], [$this->profileDomain($profile), $this->profileBranch($profile)], $script);

        try {
            $this->getClient()->server($this->lastServerId)->sites($this->lastSiteId)->deployment()->updateDeployScript($script);

            return ['success' => true, 'message' => 'Deploy script configured successfully'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => 'Failed to configure deploy script: '.$exception->getMessage()];
        }
    }

    /** @return array{success: bool, message: string} */
    protected function applyEnvironment(object $project, object $profile): array
    {
        $projectEnvironment = \method_exists($project, 'environment') ? $project->environment() : null;
        $profileEnvironment = \method_exists($profile, 'environment') ? $profile->environment() : null;
        if (! \is_object($projectEnvironment) || ! \method_exists($projectEnvironment, 'mergeWith') || ! \is_object($profileEnvironment)) {
            return ['success' => true, 'message' => 'No environment variables to configure'];
        }

        $merged = $projectEnvironment->mergeWith($profileEnvironment);
        $variables = \is_object($merged) && \method_exists($merged, 'variables') ? $merged->variables() : [];
        if (! \is_array($variables) || $variables === []) {
            return ['success' => true, 'message' => 'No environment variables to configure'];
        }

        try {
            $environment = $this->getClient()->server($this->lastServerId)->sites($this->lastSiteId)->environment();
            $existing = $environment->get()->getJson()->data->content ?? '';
            $environment->update($this->mergeEnvironment(\is_string($existing) ? $existing : '', $variables));

            return ['success' => true, 'message' => 'Environment variables configured successfully'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => 'Failed to configure environment variables: '.$exception->getMessage()];
        }
    }

    /** @return array{success: bool, message: string} */
    protected function applySsl(object $project, object $profile): array
    {
        $ssl = \method_exists($project, 'ssl') ? $project->ssl() : null;
        if (! \is_object($ssl) || ! \method_exists($ssl, 'enabled') || ! $ssl->enabled()) {
            return ['success' => true, 'message' => 'SSL not enabled'];
        }

        $type = \method_exists($ssl, 'type') ? $ssl->type() : 'letsencrypt';

        try {
            $this->getClient()->server($this->lastServerId)->sites($this->lastSiteId)->certificates()->create(
                $this->profileDomain($profile),
                \is_string($type) ? $type : 'letsencrypt',
                false,
            );

            return ['success' => true, 'message' => 'SSL certificate created successfully'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'message' => 'Failed to create SSL certificate: '.$exception->getMessage()];
        }
    }

    /** @param array<string, mixed> $variables */
    private function mergeEnvironment(string $content, array $variables): string
    {
        $lines = $content === '' ? [] : \preg_split('/\r?\n/', $content);
        $values = [];
        foreach (\is_array($lines) ? $lines : [] as $line) {
            if (\str_contains($line, '=')) {
                [$key, $value] = \explode('=', $line, 2);
                $values[$key] = $value;
            }
        }

        foreach ($variables as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return \implode("\n", \array_map(static fn (string $key, string $value): string => "{$key}={$value}", \array_keys($values), $values));
    }

    private function profileDomain(object $profile): string
    {
        $domain = $this->profileValue($profile, 'domain');

        return \is_string($domain) ? $domain : '';
    }

    private function findSite(int $serverId, string $domain): ?object
    {
        $sites = $this->getClient()->server($serverId)->sites()->get()->getJson()->data ?? null;
        if (! \is_array($sites)) {
            return null;
        }

        foreach ($sites as $site) {
            if (\is_object($site) && \property_exists($site, 'domain') && $site->domain === $domain) {
                return $site;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    protected function deploymentLogs(int $serverId, int $siteId): array
    {
        return $this->getDeploymentLogs($serverId, $siteId);
    }

    protected function getClient(): Ploi
    {
        if ($this->client === null) {
            $apiKey = $this->config['api_key'] ?? '';
            \assert(\is_string($apiKey));
            $this->client = new Ploi($apiKey);
        }

        return $this->client;
    }

    private function getServerId(object $profile): string
    {
        $server = $this->extractServerLifecycle($profile);
        $serverId = $server['id'] ?? ($this->config['server_id'] ?? '');

        return (string) $serverId;
    }

    /**
     * @return array{mode:string,id?:mixed,cleanup?:mixed,ttl?:mixed,spec?:array<string,mixed>}|null
     */
    private function extractServerLifecycle(object $profile): ?array
    {
        if (\method_exists($profile, 'server')) {
            $server = $profile->server();
            if (\is_object($server) && \method_exists($server, 'mode')) {
                $spec = \method_exists($server, 'spec') ? $server->spec() : [];

                return [
                    'mode' => (string) $server->mode(),
                    'id' => \method_exists($server, 'id') ? $server->id() : null,
                    'cleanup' => \method_exists($server, 'cleanup') ? $server->cleanup() : null,
                    'ttl' => \method_exists($server, 'ttl') ? $server->ttl() : null,
                    'spec' => \is_array($spec) ? $spec : [],
                ];
            }
        }

        return null;
    }

    /**
     * @param array{mode:string,id?:mixed,cleanup?:mixed,ttl?:mixed,spec?:array<string,mixed>} $server
     * @return array<int,string>
     */
    private function validateServerLifecycle(array $server): array
    {
        $errors = [];
        $mode = $server['mode'] ?? '';

        if (! \in_array($mode, ['existing', 'create'], true)) {
            $errors[] = 'Ploi infrastructure.server.mode must be either existing or create';

            return $errors;
        }

        if ($mode === 'existing') {
            $serverId = $server['id'] ?? null;
            if ((! \is_string($serverId) && ! \is_int($serverId)) || (string) $serverId === '') {
                $errors[] = 'Ploi existing server mode requires infrastructure.server.id';
            } elseif (! \ctype_digit((string) $serverId)) {
                $errors[] = 'Ploi infrastructure.server.id must contain only digits';
            }
        }

        if ($mode === 'create') {
            $spec = \is_array($server['spec'] ?? null) ? $server['spec'] : [];
            $name = $spec['name'] ?? null;
            if (! \is_string($name) || $name === '') {
                $errors[] = 'Ploi create server mode requires infrastructure.server.spec.name';
            }

            $credential = $spec['credential'] ?? ($spec['provider_id'] ?? ($spec['provider'] ?? null));
            if ((! \is_string($credential) && ! \is_int($credential)) || (string) $credential === '' || ! \ctype_digit((string) $credential)) {
                $errors[] = 'Ploi create server mode requires infrastructure.server.spec.credential (or provider_id/provider) as digits';
            }

            $region = $spec['region'] ?? null;
            if (! \is_string($region) || $region === '') {
                $errors[] = 'Ploi create server mode requires infrastructure.server.spec.region';
            }

            $plan = $spec['plan'] ?? ($spec['size'] ?? null);
            if (! \is_string($plan) || $plan === '') {
                $errors[] = 'Ploi create server mode requires infrastructure.server.spec.plan (or size)';
            }
        }

        $cleanup = $server['cleanup'] ?? null;
        if ($cleanup !== null && $cleanup !== '' && ! \in_array($cleanup, ['destroy', 'retain', 'manual'], true)) {
            $errors[] = 'Ploi infrastructure.server.cleanup must be one of destroy, retain, or manual';
        }

        return $errors;
    }

    protected function resolveServerIdForProfile(object $project, object $profile, bool $createIfMissing = false): int
    {
        $server = $this->extractServerLifecycle($profile);
        if ($server === null || ($server['mode'] ?? null) === 'existing') {
            $serverId = (int) $this->getServerId($profile);
            if ($serverId <= 0) {
                throw new \RuntimeException('Ploi existing server ID is missing or invalid');
            }

            $this->getServerLifecycleClient()->get($serverId);

            return $serverId;
        }

        $managedName = $this->managedServerName($project, $profile, $server);
        $existingServerId = $this->findServerIdByName($managedName);
        if ($existingServerId !== null) {
            return $existingServerId;
        }

        return $createIfMissing ? $this->createServerFromLifecycle($project, $profile, $server) : 0;
    }

    private function findServerIdByName(string $name): ?int
    {
        if ($name === '') {
            return null;
        }

        foreach ($this->getServerLifecycleClient()->list() as $server) {
            if (\is_object($server) && \property_exists($server, 'name') && \property_exists($server, 'id') && $server->name === $name) {
                return (int) $server->id;
            }
        }

        return null;
    }

    /**
     * @param array{mode:string,id?:mixed,cleanup?:mixed,ttl?:mixed,spec?:array<string,mixed>} $server
     */
    private function createServerFromLifecycle(object $project, object $profile, array $server): int
    {
        $spec = \is_array($server['spec'] ?? null) ? $server['spec'] : [];
        $name = $this->managedServerName($project, $profile, $server);
        $credential = (int) ($spec['credential'] ?? ($spec['provider_id'] ?? ($spec['provider'] ?? 0)));
        $region = \is_string($spec['region'] ?? null) ? $spec['region'] : '';
        $plan = \is_string($spec['plan'] ?? ($spec['size'] ?? null)) ? ($spec['plan'] ?? $spec['size']) : '';

        unset($spec['name'], $spec['credential'], $spec['provider_id'], $spec['provider'], $spec['region'], $spec['plan'], $spec['size']);

        return $this->getServerLifecycleClient()->create($name, $credential, $region, $plan, $spec);
    }

    /**
     * @param array{mode:string,id?:mixed,cleanup?:mixed,ttl?:mixed,spec?:array<string,mixed>} $server
     */
    private function destroyCreatedServer(object $project, object $profile, array $server): bool
    {
        $cleanup = $server['cleanup'] ?? null;
        if ($cleanup === null || $cleanup === '' || $cleanup === 'retain' || $cleanup === 'manual') {
            return true;
        }

        if ($cleanup !== 'destroy') {
            $this->lastError = 'Unsupported cleanup policy for Ploi server lifecycle: '.$cleanup;

            return false;
        }

        $managedName = $this->managedServerName($project, $profile, $server);
        $serverId = $this->findServerIdByName($managedName);
        if ($serverId !== null) {
            $this->getServerLifecycleClient()->delete($serverId);

            return true;
        }

        $displayName = $this->displayServerName($server);
        if ($displayName !== $managedName && $this->findServerIdByName($displayName) !== null) {
            $this->lastError = 'Refusing to delete unmanaged server: '.$displayName;

            return false;
        }

        return true;
    }

    private function getServerLifecycleClient(): ServerLifecycleClientInterface
    {
        if ($this->serverLifecycleClient === null) {
            $this->serverLifecycleClient = new PloiServerLifecycleClient($this->getClient());
        }

        return $this->serverLifecycleClient;
    }

    /**
     * @param array{mode:string,id?:mixed,cleanup?:mixed,ttl?:mixed,spec?:array<string,mixed>} $server
     */
    private function displayServerName(array $server): string
    {
        $name = $server['spec']['name'] ?? 'unnamed-server';

        return \is_string($name) ? $name : 'unnamed-server';
    }

    /**
     * @param array{mode:string,id?:mixed,cleanup?:mixed,ttl?:mixed,spec?:array<string,mixed>} $server
     */
    private function managedServerName(object $project, object $profile, array $server): string
    {
        $prefix = self::MANAGED_SERVER_PREFIX.'-'.$this->slugify($this->projectName($project)).'-'.$this->slugify($this->profileName($profile));

        return $prefix.'-'.$this->slugify($this->displayServerName($server));
    }

    private function slugify(string $value): string
    {
        $slug = \strtolower($value);
        $slug = (string) \preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = \trim($slug, '-');

        return $slug !== '' ? $slug : 'unnamed';
    }

    private function getDeploymentTimeout(): int
    {
        $timeout = $this->config['deployment_timeout'] ?? 60;

        return \is_int($timeout) || \is_numeric($timeout) ? (int) $timeout : 60;
    }

    /**
     * @return array<int,string>
     */
    private function getDeploymentLogs(int $serverId, int $siteId): array
    {
        try {
            $logsData = $this->getClient()->server($serverId)->sites($siteId)->logs()->getData();
            if (! \is_array($logsData)) {
                return [];
            }

            $logs = [];
            foreach ($logsData as $log) {
                if (\is_object($log) && \property_exists($log, 'description')) {
                    $logs[] = (string) $log->description;
                }
            }

            return $logs;
        } catch (\Throwable $e) {
            return ['Error fetching logs: '.$e->getMessage()];
        }
    }

    private function projectName(object $project): string
    {
        return \method_exists($project, 'name') ? (string) $project->name() : 'unknown';
    }

    private function projectPath(object $project): string
    {
        return \method_exists($project, 'path') ? (string) $project->path() : '.';
    }

    private function projectWebDirectory(object $project): string
    {
        return \method_exists($project, 'webDirectory') ? (string) $project->webDirectory() : '/public';
    }

    private function projectRoot(object $project): string
    {
        return \method_exists($project, 'projectRoot') ? (string) $project->projectRoot() : '/';
    }

    /**
     * @return array<string,mixed>
     */
    private function projectRepository(object $project): array
    {
        $repository = \method_exists($project, 'repository') ? $project->repository() : [];

        return \is_array($repository) ? $repository : [];
    }

    /**
     * @return array<int,object>
     */
    private function projectDatabases(object $project): array
    {
        $databases = \method_exists($project, 'databases') ? $project->databases() : [];

        return \is_array($databases) ? \array_values(\array_filter($databases, 'is_object')) : [];
    }

    private function profileName(object $profile): string
    {
        return \method_exists($profile, 'name') ? (string) $profile->name() : 'unknown';
    }

    private function profileBranch(object $profile): string
    {
        return \method_exists($profile, 'branch') ? (string) $profile->branch() : 'main';
    }

    private function profileValue(object $profile, string $key): mixed
    {
        return \method_exists($profile, 'get') ? $profile->get($key) : null;
    }

    private function databaseValue(object $database, string $method): string
    {
        return \method_exists($database, $method) ? (string) $database->{$method}() : '';
    }

    private function interpolateDatabaseName(string $name, string $projectName, string $profileName): string
    {
        $name = \str_replace('${PROJECT_NAME}', $projectName, $name);
        $name = \str_replace('${PROFILE}', $profileName, $name);

        return (string) \preg_replace_callback(
            '/\$\{([A-Z_][A-Z0-9_]*)\}/',
            static function (array $matches): string {
                $value = \getenv($matches[1]);

                return $value !== false ? $value : '';
            },
            $name,
        );
    }

    private function generateDatabasePassword(): string
    {
        return \bin2hex(\random_bytes(16));
    }
}
