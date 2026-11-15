<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelPlugin;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class EasyPanelPluginTest extends TestCase
{
    public function test_plugin_registers_the_provider(): void
    {
        self::assertSame(['easypanel' => EasyPanelProvider::class], (new EasyPanelPlugin())->providers());
    }
}
