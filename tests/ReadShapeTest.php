<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;

final class ReadShapeTest extends TestCase
{
    public static function invalidInspections(): array
    {
        $cases = [];
        foreach (['app', 'box', 'mysql'] as $type) {
            foreach ([null, false, 'synthetic-secret', [['env' => 'synthetic-secret']]] as $data) $cases[] = [$type, $data];
        }
        return $cases;
    }

    #[DataProvider('invalidInspections')]
    public function testInspectionsDoNotNormalizeUnreadableData(string $type, mixed $data): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        $this->expectException(\RuntimeException::class);
        match ($type) {
            'app' => $client->inspectAppService('fixture', 'app'),
            'box' => $client->inspectBoxService('fixture', 'worker'),
            'mysql' => $client->inspectDatabaseService('mysql', 'fixture', 'db'),
        };
    }

    public static function invalidDomains(): array
    {
        return array_map(fn ($data) => [$data], [null, false, 'synthetic-secret',
            ['named' => ['host' => 'fixture.test']], [[]], [['host' => '']], [['host' => ' fixture.test']]]);
    }

    #[DataProvider('invalidDomains')]
    public function testUnreadableDomainsCannotBecomeEmptyInventory(mixed $data): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        $this->expectException(\RuntimeException::class);
        $client->listDomains('fixture', 'app');
    }

    public function testValidDomainsAndInspectionsArePreserved(): void
    {
        foreach ([[], [['host' => 'fixture.test', 'path' => '/']], [['host' => 'fixture.test', 'path' => '/a'], ['host' => 'fixture.test', 'path' => '/b']]] as $data) {
            $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
            self::assertSame($data, $client->listDomains('fixture', 'app'));
        }
        $data = ['env' => 'SHIPPERCLI_MANAGED=1'];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        self::assertSame($data, $client->inspectAppService('fixture', 'app'));
        self::assertSame($data, $client->inspectBoxService('fixture', 'worker'));
        self::assertSame($data, $client->inspectDatabaseService('mysql', 'fixture', 'db'));
    }
}
