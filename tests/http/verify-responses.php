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

foreach (['get', 'post', 'patch', 'delete'] as $method) {
    foreach (['null', 'number', 'boolean', 'string', 'array', 'records', 'syntax', 'utf8'] as $case) {
        $failure = null;
        try { $client->makeAPICall('json-shape-'.$case, $method); }
        catch (Throwable $exception) { $failure = $exception; }
        if (!$failure instanceof RuntimeException || $failure->getMessage() !== 'Ploi API returned invalid JSON.' || $failure->getPrevious() !== null) {
            throw new RuntimeException('Invalid JSON response accepted or exposed: '.$method.'/'.$case);
        }
    }
    if ($client->makeAPICall('json-shape-object', $method)->getJson()->data->accepted !== true) {
        throw new RuntimeException('Valid JSON object changed');
    }
}
if ($client->makeAPICall('json-shape-empty', 'delete')->getJson() !== null
    || $client->makeAPICall('json-shape-empty204', 'post')->getJson() !== null) {
    throw new RuntimeException('Empty success response compatibility changed');
}
echo "32 real HTTP invalid JSON checks and six valid controls passed\n";


$project = new class {
    public function name(): string { return 'fixture'; }
    public function databases(): array { return [new class {
        public function name(): string { return 'fixture'; }
        public function user(): string { return 'fixture_user'; }
    }]; }
};
foreach (['valid', 'valid-site', 'valid-explicit', 'wrong-name', 'bad-id', 'wrong-site', 'missing', 'false', 'error'] as $case) {
    $api = new PloiApiClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => $case]]));
    $provider = new \ShipperCli\ProviderPloi\PloiProvider;
    $profile = new class($case) {
        public function __construct(private string $case) {}
        public function environment(): object { return new class($this->case) {
            public function __construct(private string $case) {}
            public function variables(): array { return $this->case === 'valid-explicit'
                ? ['DB_PASSWORD' => 'literal-${PROJECT_NAME}-${PROFILE}-+%:@'] : []; }
        }; }
    };
    $failure = null;
    try {
        (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $api->server(124), 55, $project, $profile);
    } catch (RuntimeException $exception) { $failure = $exception; }
    $environment = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
    if (in_array($case, ['valid', 'valid-site', 'valid-explicit'], true)) {
        if ($failure !== null || ($environment['DB_DATABASE'] ?? null) !== 'fixture'
            || ($environment['DB_USERNAME'] ?? null) !== 'fixture_user' || empty($environment['DB_PASSWORD'])) {
            throw new RuntimeException('Valid database creation rejected');
        }
        if ($case === 'valid-explicit' && $environment['DB_PASSWORD'] !== 'literal-${PROJECT_NAME}-${PROFILE}-+%:@') {
            throw new RuntimeException('Explicit database password changed');
        }
    } elseif ($failure?->getMessage() !== 'Ploi did not confirm the requested database creation identity.'
        || $failure->getPrevious() !== null || $environment !== []) {
        throw new RuntimeException('Invalid database creation accepted or exposed');
    }
}
echo "Nine real HTTP database creation identity cases passed\n";


$api = new PloiApiClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/']));
$project = new class {
    public function name(): string { return 'fixture'; }
    public function databases(): array {
        return array_map(static fn ($name) => new class($name) {
            public function __construct(private string $name) {}
            public function name(): string { return $this->name; }
            public function user(): string { return 'fixture_user'; }
        }, ['main', 'analytics']);
    }
};
$config = ['database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']];
$provider = new \ShipperCli\ProviderPloi\PloiProvider($config);
$failure = null;
try {
    (new ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $api->server(125), 55, $project, new stdClass);
} catch (RuntimeException $error) { $failure = $error; }
$first = (new ReflectionProperty($provider, 'generatedEnvironment'))->getValue($provider);
if ($failure === null || empty($first['DB_PASSWORD'])) {
    throw new RuntimeException('Expected partial provisioning failure');
}
$retry = new \ShipperCli\ProviderPloi\PloiProvider($config);
if (!(new ReflectionMethod($retry, 'applyDatabases'))->invoke($retry, $api->server(125), 55, $project, new stdClass)) {
    throw new RuntimeException('Fresh retry rejected retained credentials');
}
$stored = \Dotenv\Dotenv::parse($api->server(125)->sites(55)->environment()->get()->getJson()->data->content);
$second = (new ReflectionProperty($retry, 'generatedEnvironment'))->getValue($retry);
if (($stored['DB_PASSWORD'] ?? null) !== $first['DB_PASSWORD']
    || ($stored['ANALYTICS_DB_PASSWORD'] ?? null) !== ($second['ANALYTICS_DB_PASSWORD'] ?? null)
    || empty($stored['ANALYTICS_DB_PASSWORD']) || ($stored['APP_ENV'] ?? null) !== 'production') {
    throw new RuntimeException('Partial provisioning retry changed or lost connection settings');
}
echo "Real SDK partial provisioning and fresh retry passed\n";


foreach (['final-valid', 'final-ignored'] as $case) {
    $api = new PloiApiClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => $case]]));
    $provider = new class($api) extends \ShipperCli\ProviderPloi\PloiProvider {
        public function __construct(private \Ploi\Ploi $client) { parent::__construct(); }
        protected function getClient(): \Ploi\Ploi { return $this->client; }
    };
    (new ReflectionProperty(\ShipperCli\ProviderPloi\PloiProvider::class, 'lastServerId'))->setValue($provider, 124);
    (new ReflectionProperty(\ShipperCli\ProviderPloi\PloiProvider::class, 'lastSiteId'))->setValue($provider, 55);
    $project = new class {
        public function environment(): object { return new class {
            public function variables(): array { return ['APP_NAME' => 'literal-${PROJECT_NAME}-secret', 'COUNT' => 7, 'EMPTY' => '']; }
        }; }
    };
    $result = (new ReflectionMethod($provider, 'applyEnvironment'))->invoke($provider, $project, new stdClass);
    if ($result['success'] !== ($case === 'final-valid') || str_contains($result['message'], 'literal-${PROJECT_NAME}-secret')) {
        throw new RuntimeException('Final environment readback acceptance or diagnostics incorrect');
    }
}
echo "Final environment real SDK readback controls passed\n";


foreach (['valid', 'false', 'errors', 'error'] as $case) {
    $api = new PloiApiClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => 'ownership-'.$case]]));
    $provider = new class($api) extends \ShipperCli\ProviderPloi\PloiProvider {
        public function __construct(private \Ploi\Ploi $client) { parent::__construct(); }
        protected function getClient(): \Ploi\Ploi { return $this->client; }
    };
    $project = new class { public function name(): string { return 'fixture'; } };
    $profile = new class { public function name(): string { return 'production'; } };
    $failure = null;
    try { (new ReflectionMethod($provider, 'assertSiteOwnership'))->invoke($provider, $project, $profile, 126, 55); }
    catch (RuntimeException $error) { $failure = $error; }
    if (($failure === null) !== ($case === 'valid') || ($failure !== null && str_contains($failure->getMessage(), 'synthetic-secret'))) {
        throw new RuntimeException('Ownership accepted a failed environment response or exposed diagnostics');
    }
}
echo "Four real SDK ownership envelope controls passed\n";


foreach (['active', 'deploying', 'failed', 'wrong-id', 'wrong-server', 'missing-status', 'false', 'invalid-flag'] as $case) {
    $api = new PloiApiClient('synthetic-authorization-secret', new Client(['base_uri' => $argv[1].'/',
        'headers' => ['X-Fixture-Case' => 'poll-'.$case]]));
    $provider = new \ShipperCli\ProviderPloi\PloiProvider;
    $failure = null;
    $state = null;
    try {
        $state = (new ReflectionMethod($provider, 'deploymentSiteState'))->invoke($provider, $api->server(127)->sites(55)->get()->getJson(), 127, 55);
    } catch (RuntimeException $error) { $failure = $error; }
    $expected = ['active' => 'ready', 'deploying' => 'pending', 'failed' => 'failed'][$case] ?? null;
    if ($expected !== null ? ($state !== $expected || $failure !== null)
        : $failure?->getMessage() !== 'Ploi returned an invalid deployment site response.') {
        throw new RuntimeException('Deployment site response accepted an invalid identity or state');
    }
}
echo "Eight real SDK deployment site response checks passed\n";
