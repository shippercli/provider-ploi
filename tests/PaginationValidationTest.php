<?php

use ShipperCli\ProviderPloi\PloiProvider;

function paginationResource(array $pages): object
{
    return new class($pages) {
        public array $requested = [];
        public function __construct(private array $pages) {}
        public function page(int $page, int $perPage): object {
            $this->requested[] = $page;
            return new class($this->pages[$page - 1]) {
                public function __construct(private mixed $value) {}
                public function getJson(): mixed { return $this->value; }
            };
        }
    };
}

test('malformed Ploi pages fail instead of silently losing inventory', function (): void {
    $invalid = [
        (object) ['data' => [], 'meta' => (object) []],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 0]],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 1.5]],
        (object) ['data' => [], 'meta' => (object) ['last_page' => '1e2']],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 1, 'current_page' => 2]],
        (object) ['data' => ['invalid'], 'meta' => (object) ['last_page' => 1]],
        (object) ['data' => ['named' => (object) ['id' => 1]], 'meta' => (object) ['last_page' => 1]],
    ];
    $method = new ReflectionMethod(PloiProvider::class, 'paginatedData');
    foreach ($invalid as $payload) {
        try {
            $method->invoke(new PloiProvider, paginationResource([$payload]));
            throw new LogicException('Invalid inventory was accepted.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('Ploi returned');
        }
    }
});

test('later-page failures discard partial Ploi inventory', function (): void {
    $resource = paginationResource([
        (object) ['data' => [(object) ['id' => 1]], 'meta' => (object) ['last_page' => 2]],
        (object) ['data' => [], 'meta' => (object) ['last_page' => 1]],
    ]);
    expect(fn () => (new ReflectionMethod(PloiProvider::class, 'paginatedData'))->invoke(new PloiProvider, $resource))
        ->toThrow(RuntimeException::class, 'invalid pagination metadata');
    expect($resource->requested)->toBe([1, 2]);
});

test('valid complete Ploi pagination preserves every resource', function (): void {
    $first = (object) ['id' => 1];
    $second = (object) ['id' => 2];
    $resource = paginationResource([
        (object) ['data' => [$first], 'meta' => (object) ['last_page' => 2, 'current_page' => 1]],
        (object) ['data' => [$second], 'meta' => (object) ['last_page' => 2, 'current_page' => 2]],
    ]);
    expect((new ReflectionMethod(PloiProvider::class, 'paginatedData'))->invoke(new PloiProvider, $resource))->toBe([$first, $second]);
    expect($resource->requested)->toBe([1, 2]);
});
