<?php

use ShipperCli\ProviderPloi\PloiProvider;

test('post apply stops immediately at the first failed configuration step', function (int $failure): void {
    $provider = new class($failure) extends PloiProvider {
        public array $operations = [];
        public function __construct(private int $failure) { parent::__construct(); }
        private function step(int $index): array {
            $this->operations[] = $index;
            return ['success' => $index !== $this->failure, 'message' => 'step '.$index];
        }
        protected function applyAliases(object $profile): array { return $this->step(0); }
        protected function applyDeployScript(object $project, object $profile): array { return $this->step(1); }
        protected function applyEnvironment(object $project, object $profile): array { return $this->step(2); }
        protected function applySsl(object $project, object $profile): array { return $this->step(3); }
        protected function applyPhpVersion(object $project): array { return $this->step(4); }
        protected function applyNginxConfig(object $project): array { return $this->step(5); }
        protected function applyRedirects(object $project): array { return $this->step(6); }
        protected function applyNetworkRules(object $project, object $profile): array { return $this->step(7); }
        protected function deploymentLogs(int $serverId, int $siteId): array { return []; }
    };
    (new ReflectionProperty(PloiProvider::class, 'lastServerId'))->setValue($provider, 123);
    (new ReflectionProperty(PloiProvider::class, 'lastSiteId'))->setValue($provider, 55);
    $result = $provider->postApply(new stdClass, new stdClass);
    expect($provider->operations)->toBe(range(0, $failure < 0 ? 7 : $failure))
        ->and($result['success'])->toBe($failure < 0);
    if ($failure >= 0) {
        expect($result['message'])->toBe('step '.$failure);
    }
})->with(range(-1, 7));
