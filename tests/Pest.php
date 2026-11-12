<?php

use Mockery as m;
use Ploi\Ploi;
use Ploi\Resources\Server;
use Ploi\Resources\Site;
use ShipperCli\ProviderPloi\PloiProvider;

function emptyInventoryPlanProvider(): PloiProvider
{
    $client = m::mock(Ploi::class);
    $server = m::mock(Server::class);
    $sites = m::mock(Site::class);
    $client->shouldReceive('server')->with(123)->once()->andReturn($server);
    $server->shouldReceive('sites')->withNoArgs()->once()->andReturn($sites);
    $sites->shouldReceive('page->getJson')->andReturn((object) ['data' => [], 'meta' => (object) ['last_page' => 1]]);
    return new class($client) extends PloiProvider {
        public function __construct(private Ploi $fakeClient) { parent::__construct(['api_key' => 'token', 'server_id' => '123']); }
        protected function getClient(): Ploi { return $this->fakeClient; }
    };
}
