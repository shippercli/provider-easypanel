<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel;

use ShipperCli\Contracts\ShipperPluginInterface;

final class EasyPanelPlugin implements ShipperPluginInterface
{
    /**
     * @return array<class-string, class-string>
     */
    public function providers(): array
    {
        return [];
    }
}
