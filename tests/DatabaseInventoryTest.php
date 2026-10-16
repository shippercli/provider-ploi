<?php

use ShipperCli\ProviderPloi\PloiProvider;

test('database association never coerces malformed site identities', function (mixed $id): void {
    expect(fn () => (new ReflectionMethod(PloiProvider::class, 'assertDatabaseSiteAssociation'))
        ->invoke(new PloiProvider, (object) ['site' => (object) ['id' => $id]], 55, 'fixture'))
        ->toThrow(RuntimeException::class, 'cannot be proven');
})->with(['55junk', '55.9', 55.9, '5.5e1', ' 55', '055', true, null]);

test('database association accepts exact canonical identities', function (mixed $id): void {
    (new ReflectionMethod(PloiProvider::class, 'assertDatabaseSiteAssociation'))
        ->invoke(new PloiProvider, (object) ['site' => (object) ['id' => $id]], 55, 'fixture');
    expect(true)->toBeTrue();
})->with([55, '55']);

test('database inventories reject ambiguous names and malformed record identities', function (array $data): void {
    $resource = paginationResource([(object) ['data' => $data, 'meta' => (object) ['last_page' => 1]]]);
    expect(fn () => (new ReflectionMethod(PloiProvider::class, 'databaseInventory'))->invoke(new PloiProvider, $resource))
        ->toThrow(RuntimeException::class, 'database inventory');
})->with([
    'duplicate names' => [[(object) ['id' => 1, 'name' => 'fixture'], (object) ['id' => 2, 'name' => 'fixture']]],
    'duplicate ids' => [[(object) ['id' => 1, 'name' => 'first'], (object) ['id' => '1', 'name' => 'second']]],
    'missing id' => [[(object) ['name' => 'fixture']]],
    'fraction id' => [[(object) ['id' => 1.5, 'name' => 'fixture']]],
    'junk id' => [[(object) ['id' => '1junk', 'name' => 'fixture']]],
    'missing name' => [[(object) ['id' => 1]]],
    'empty name' => [[(object) ['id' => 1, 'name' => '']]],
    'padded name' => [[(object) ['id' => 1, 'name' => ' fixture']]],
]);

test('database inventories preserve unique records and canonical identities', function (): void {
    $records = [(object) ['id' => 1, 'name' => 'first'], (object) ['id' => '2', 'name' => 'second']];
    $resource = paginationResource([(object) ['data' => $records, 'meta' => (object) ['last_page' => 1]]]);
    expect((new ReflectionMethod(PloiProvider::class, 'databaseInventory'))->invoke(new PloiProvider, $resource))->toBe($records);
});
