<?php

declare(strict_types=1);

namespace ShipperCli\ProviderPloi;

use ShipperCli\Contracts\ShipperPluginInterface;

final class PloiPlugin implements ShipperPluginInterface
{
    public function providers(): array
    {
        return ['ploi' => PloiProvider::class];
    }
}
