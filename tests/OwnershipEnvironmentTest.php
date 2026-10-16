<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class OwnershipEnvironmentTest extends TestCase
{
    public function testOwnershipUsesParsedAssignmentsInsteadOfRawLines(): void
    {
        $provider = new EasyPanelProvider();
        $method = new \ReflectionMethod($provider, 'hasEnvironmentLine');
        foreach ([
            ["SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED=0", false],
            ["COMMENT=\"multiline\nSHIPPERCLI_MANAGED=1\ntext\"", false],
            ['SHIPPERCLI_MANAGED="unterminated', false],
            ['export SHIPPERCLI_MANAGED = "1" # managed', true],
            ["SHIPPERCLI_MANAGED=0\nSHIPPERCLI_MANAGED=1", true],
        ] as [$environment, $owned]) {
            self::assertSame($owned, $method->invoke($provider, $environment, 'SHIPPERCLI_MANAGED=1'));
        }
    }
}
