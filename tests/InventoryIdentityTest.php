<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;

final class InventoryIdentityTest extends TestCase
{
    public static function invalidInventories(): array
    {
        $project = ['name' => 'fixture'];
        $service = ['projectName' => 'fixture', 'name' => 'app', 'type' => 'app'];
        return [
            'duplicate projects' => [['projects' => [$project, $project], 'services' => []]],
            'padded project' => [['projects' => [['name' => ' fixture']], 'services' => []]],
            'duplicate services' => [['projects' => [$project], 'services' => [$service, $service]]],
            'conflicting types' => [['projects' => [$project], 'services' => [$service, [...$service, 'type' => 'mysql']]]],
            'orphan' => [['projects' => [], 'services' => [$service]]],
            'padded service' => [['projects' => [$project], 'services' => [[...$service, 'name' => ' app']]]],
            'padded parent' => [['projects' => [$project], 'services' => [[...$service, 'projectName' => 'fixture ']]]],
            'padded type' => [['projects' => [$project], 'services' => [[...$service, 'type' => 'app ']]]],
        ];
    }

    #[DataProvider('invalidInventories')]
    public function testRejectsAmbiguousInventories(array $inventory): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $inventory);
        $this->expectException(\RuntimeException::class);
        $client->listProjectsAndServices();
    }

    public function testServiceNamesAreScopedToProjects(): void
    {
        $inventory = ['projects' => [['name' => 'first'], ['name' => 'second']], 'services' => [
            ['projectName' => 'first', 'name' => 'app', 'type' => 'app'],
            ['projectName' => 'second', 'name' => 'app', 'type' => 'app'],
        ]];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $inventory);
        self::assertSame($inventory, $client->listProjectsAndServices());
    }
}
