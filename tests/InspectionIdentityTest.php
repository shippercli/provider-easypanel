<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;

final class InspectionIdentityTest extends TestCase
{
    public static function mismatchedIdentities(): array
    {
        $cases = [];
        foreach (['app', 'box', 'mysql'] as $type) {
            foreach ([['projectName', 'other'], ['projectName', null], ['name', 'other'],
                ['serviceName', 'other'], ['type', 'other'], ['name', ['synthetic-secret']]] as [$key, $value]) {
                $cases[] = [$type, $key, $value];
            }
        }
        return $cases;
    }

    private function inspect(EasyPanelClient $client, string $type): array
    {
        return match ($type) {
            'app' => $client->inspectAppService('fixture', 'target'),
            'box' => $client->inspectBoxService('fixture', 'target'),
            default => $client->inspectDatabaseService($type, 'fixture', 'target'),
        };
    }

    #[DataProvider('mismatchedIdentities')]
    public function testExplicitIdentityMustMatchRequest(string $type, string $key, mixed $value): void
    {
        $data = ['env' => 'SHIPPERCLI_MANAGED=1', $key => $value];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inspection identity');
        $this->inspect($client, $type);
    }

    public function testMatchedAndOmittedOptionalIdentitiesRemainSupported(): void
    {
        foreach (['app', 'box', 'mysql'] as $type) {
            foreach ([['env' => 'fixture'], ['projectName' => 'fixture', 'name' => 'target', 'serviceName' => 'target', 'type' => $type, 'env' => 'fixture']] as $data) {
                $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
                self::assertSame($data, $this->inspect($client, $type));
            }
        }
    }
}
