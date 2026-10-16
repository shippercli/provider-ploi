<?php

use ShipperCli\ProviderPloi\PloiProvider;

test('site discovery rejects every ambiguous or malformed inventory before selecting a match', function (array $records): void {
    $resource = paginationResource([(object) ['data' => $records, 'meta' => (object) ['last_page' => 1]]]);
    expect(fn () => (new ReflectionMethod(PloiProvider::class, 'siteInventory'))->invoke(new PloiProvider, $resource))
        ->toThrow(RuntimeException::class, 'site inventory');
})->with([
    'duplicate domain' => [[(object) ['id' => 1, 'domain' => 'fixture.test'], (object) ['id' => 2, 'domain' => 'fixture.test']]],
    'domain case' => [[(object) ['id' => 1, 'domain' => 'fixture.test'], (object) ['id' => 2, 'domain' => 'FIXTURE.test']]],
    'duplicate id' => [[(object) ['id' => 1, 'domain' => 'fixture.test'], (object) ['id' => 1, 'domain' => 'other.test']]],
    'missing id' => [[(object) ['domain' => 'fixture.test']]],
    'fraction id' => [[(object) ['id' => 1.5, 'domain' => 'fixture.test']]],
    'empty domain' => [[(object) ['id' => 1, 'domain' => '']]],
    'padded domain' => [[(object) ['id' => 1, 'domain' => ' fixture.test']]],
    'invalid after match' => [[(object) ['id' => 1, 'domain' => 'fixture.test'], (object) ['id' => 0, 'domain' => 'other.test']]],
]);

test('site inventories preserve all unique identities across pages', function (): void {
    $first = (object) ['id' => 1, 'domain' => 'first.test'];
    $second = (object) ['id' => 2, 'domain' => 'second.test'];
    $resource = paginationResource([
        (object) ['data' => [$first], 'meta' => (object) ['last_page' => 2]],
        (object) ['data' => [$second], 'meta' => (object) ['last_page' => 2]],
    ]);
    expect((new ReflectionMethod(PloiProvider::class, 'siteInventory'))->invoke(new PloiProvider, $resource))->toBe([$first, $second]);
});
