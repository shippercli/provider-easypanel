<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class InventoryValidationTest extends TestCase
{
    public function testMalformedInventoriesBlockPlanApplyAndDestroyBeforeWrites(): void
    {
        foreach ([null, [], 'unexpected', ['projects' => []],
            ['projects' => ['named' => ['name' => 'api']], 'services' => []],
            ['projects' => [null], 'services' => []],
            ['projects' => [], 'services' => [['name' => 'app']]],
            ['projects' => [], 'services' => ['invalid']],
        ] as $payload) {
            $calls = [];
            $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls, $payload): mixed {
                $calls[] = $procedure;
                return $payload;
            });
            $provider = new EasyPanelProvider([
                'url' => 'https://panel.example.com', 'auth_token' => 'token',
                'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
            ], $client);
            $project = new class { public function name(): string { return 'api'; } };
            $profile = new class { public function name(): string { return 'preview'; } };
            self::assertTrue($provider->plan($project, $profile)['blocked']);
            self::assertFalse($provider->apply($project, $profile));
            self::assertFalse($provider->destroy($project, $profile));
            self::assertSame(array_fill(0, 3, 'projects.listProjectsAndServices'), $calls);
        }
    }

    public function testExplicitEmptyInventoryRemainsValid(): void
    {
        $empty = ['projects' => [], 'services' => []];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static fn (): array => $empty);
        self::assertSame($empty, $client->listProjectsAndServices());
    }
}
