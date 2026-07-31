<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel;

use ShipperCli\Contracts\ShipperPluginInterface;

final class EasyPanelPlugin implements ShipperPluginInterface
{
    public function providers(): array
    {
        return [EasyPanelProvider::class];
    }
}
