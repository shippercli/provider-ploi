<?php

declare(strict_types=1);

use ShipperCli\ProviderPloi\PloiProvider;

function environmentVariableProject(array $databases = []): object
{
    return new class($databases)
    {
        public function __construct(private readonly array $databases) {}

        public function name(): string
        {
            return 'api';
        }

        public function path(): string
        {
            return '.';
        }

        public function repository(): array
        {
            return ['provider' => 'github', 'name' => 'shippercli/cli'];
        }

        public function databases(): array
        {
            return $this->databases;
        }
    };
}

function environmentVariableProfile(): object
{
    return new class
    {
        public function name(): string
        {
            return 'preview';
        }

        public function branch(): string
        {
            return 'feature/test';
        }

        public function get(string $key): mixed
        {
            return $key === 'domain' ? 'preview.example.com' : null;
        }
    };
}

function environmentVariableDatabase(string $name, string $user): object
{
    return new class($name, $user)
    {
        public function __construct(
            private readonly string $databaseName,
            private readonly string $databaseUser,
        ) {}

        public function name(): string
        {
            return $this->databaseName;
        }

        public function user(): string
        {
            return $this->databaseUser;
        }

        public function type(): string
        {
            return 'mysql';
        }
    };
}

test('validate rejects an unresolved API key placeholder', function (): void {
    $provider = new PloiProvider([
        'api_key' => '${PLOI_API_KEY}',
        'server_id' => '123',
    ]);

    expect($provider->validate(environmentVariableProject(), environmentVariableProfile()))
        ->toContain('Ploi API key contains an unresolved environment variable');
});

test('database interpolation fails when a preview variable is missing', function (): void {
    putenv('GITHUB_PR_NUMBER');
    $provider = new PloiProvider(['api_key' => 'token', 'server_id' => '123']);
    $project = environmentVariableProject([
        environmentVariableDatabase(
            'shipper_${PROJECT_NAME}_${PROFILE}_${GITHUB_PR_NUMBER}',
            'shipper_${PROJECT_NAME}_${PROFILE}_${GITHUB_PR_NUMBER}',
        ),
    ]);

    expect(fn (): array => $provider->plan($project, environmentVariableProfile()))
        ->toThrow(RuntimeException::class, 'Database identifier contains unresolved environment variable(s): GITHUB_PR_NUMBER');
});

test('database interpolation resolves project profile and environment placeholders', function (): void {
    putenv('GITHUB_PR_NUMBER=42');

    try {
        $provider = new PloiProvider(['api_key' => 'token', 'server_id' => '123']);
        $project = environmentVariableProject([
            environmentVariableDatabase(
                'shipper_${PROJECT_NAME}_${PROFILE}_${GITHUB_PR_NUMBER}',
                'shipper_${PROJECT_NAME}_${PROFILE}_${GITHUB_PR_NUMBER}',
            ),
        ]);

        expect($provider->plan($project, environmentVariableProfile())['actions'])
            ->toContain('Create or find database: shipper_api_preview_42 (user: shipper_api_preview_42, type: mysql)');
    } finally {
        putenv('GITHUB_PR_NUMBER');
    }
});
