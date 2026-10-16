<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class PlanOwnershipTest extends TestCase
{
    public function testPlanChecksOwnershipWithoutWrites(): void
    {
        $name = 'shipper-api-preview-'.substr(hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)), 0, 20);
        foreach (['new', 'owned', 'unowned', 'wrong-type', 'malformed', 'legacy', 'error'] as $mode) {
            $calls = [];
            $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls, $mode, $name): array {
                $calls[] = $procedure;
                if ($mode === 'error') throw new \RuntimeException('sensitive API error');
                if ($procedure === 'projects.listProjectsAndServices') {
                    return [
                        'projects' => [['name' => $mode === 'legacy' ? 'shipper-api-preview' : $name]],
                        'services' => in_array($mode, ['new', 'legacy'], true) ? [] : [[
                            'projectName' => $name, 'name' => 'app', 'type' => $mode === 'wrong-type' ? 'mysql' : 'app',
                        ]],
                    ];
                }
                return ['env' => match ($mode) {
                    'owned' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={$name}",
                    'malformed' => 'SHIPPERCLI_MANAGED="unterminated',
                    default => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=another-project",
                }];
            });
            $provider = new EasyPanelProvider([
                'url' => 'https://panel.example.com', 'auth_token' => 'token',
                'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
            ], $client);
            $project = new class { public function name(): string { return 'api'; } };
            $profile = new class { public function name(): string { return 'preview'; } };
            $plan = $provider->plan($project, $profile);
            self::assertSame(! in_array($mode, ['new', 'owned'], true), $plan['blocked'], $mode);
            foreach ($calls as $call) self::assertContains($call, ['projects.listProjectsAndServices', 'services.app.inspectService']);
            if ($plan['blocked']) {
                self::assertCount(1, $plan['actions']);
                self::assertStringStartsWith('BLOCKED:', $plan['actions'][0]);
                self::assertStringNotContainsString('sensitive API error', $plan['actions'][0]);
            }
        }
    }
}
