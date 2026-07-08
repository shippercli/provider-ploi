<?php

declare(strict_types=1);

namespace ShipperCli\Contracts;

interface ShipperPluginInterface
{
    /**
     * @return array<class-string, class-string>
     */
    public function providers(): array;
}
