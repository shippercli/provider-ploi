<?php

use ShipperCli\ProviderPloi\PloiProvider;

test('environment updates preserve unrelated content and replace complete assignments', function (): void {
    $merge = new ReflectionMethod(PloiProvider::class, 'mergeEnvironment');
    $content = "# operator comment\nPRIVATE_KEY=\"first\nsecond\"\nexport APP_ENV = local\nTOKEN=\"old\nsecret\"\n";
    $result = $merge->invoke(new PloiProvider, $content, [
        'APP_ENV' => 'production',
        'TOKEN' => '$literal',
        'GREETING' => "hello #world\nnext",
    ]);

    expect($result)->toContain("# operator comment\nPRIVATE_KEY=\"first\nsecond\"\n")
        ->toContain("APP_ENV=production\n")
        ->toContain('TOKEN="\$literal"')
        ->toContain('GREETING="hello #world\nnext"')
        ->not->toContain('local')
        ->not->toContain('old')
        ->not->toContain('secret');
});

test('environment updates reject assignment injection through variable names', function (): void {
    (new ReflectionMethod(PloiProvider::class, 'mergeEnvironment'))->invoke(new PloiProvider, '', ["BAD\nNAME" => 'value']);
})->throws(InvalidArgumentException::class);
