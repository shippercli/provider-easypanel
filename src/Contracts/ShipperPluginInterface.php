<?php

declare(strict_types=1);

namespace ShipperCli\Contracts;

interface ShipperPluginInterface
{
    /** @return array<int, class-string> */
    public function providers(): array;
}
