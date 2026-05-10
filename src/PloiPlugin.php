<?php

declare(strict_types=1);

namespace ShipperCli\ProviderPloi;

use ShipperCli\Contracts\ShipperPluginInterface;

final class PloiPlugin implements ShipperPluginInterface
{
    /**
     * @return array<class-string, class-string>
     */
    public function providers(): array
    {
        return [
            PloiServiceProvider::class,
        ];
    }
}