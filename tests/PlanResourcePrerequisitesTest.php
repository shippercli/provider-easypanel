<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class PlanResourcePrerequisitesTest extends TestCase
{
    public function testPlanBlocksDatabaseAndWorkerCollisionsBeforeAnyMutation(): void
    {
        foreach (['database-wrong-type', 'database-unowned', 'worker-wrong-type', 'worker-unowned'] as $mode) {
            $projectName = 'shipper-api-preview-'.substr(hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)), 0, 20);
            $calls = [];
            $service = match ($mode) {
                'database-wrong-type', 'database-unowned' => ['projectName' => $projectName, 'name' => 'db-main', 'type' => $mode === 'database-wrong-type' ? 'postgres' : 'mysql'],
                default => ['projectName' => $projectName, 'name' => 'worker-main', 'type' => $mode === 'worker-wrong-type' ? 'mysql' : 'app'],
            };
            $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls, $mode, $projectName, $service): array {
                $calls[] = $procedure;
                if ($procedure === 'projects.listProjectsAndServices') {
                    return ['projects' => [['name' => $projectName]], 'services' => [$service]];
                }
                return match ($procedure) {
                    'services.mysql.inspectService' => ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=someone-else"],
                    'services.app.inspectService' => ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=someone-else"],
                    default => throw new \LogicException('Plan must not mutate services.'),
                };
            });
            $project = new class($mode) {
                public function __construct(private string $mode) {}
                public function name(): string { return 'api'; }
                public function databases(): array
                {
                    return str_starts_with($this->mode, 'database-') ? [new class {
                        public function name(): string { return 'main'; }
                        public function user(): string { return 'main'; }
                        public function type(): string { return 'mysql'; }
                    }] : [];
                }
                public function queues(): array
                {
                    return str_starts_with($this->mode, 'worker-') ? ['main' => new class {
                        public function enabled(): bool { return true; }
                    }] : [];
                }
            };
            $provider = new EasyPanelProvider([
                'url' => 'https://panel.example.com', 'auth_token' => 'token',
                'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
            ], $client);

            $plan = $provider->plan($project, new class { public function name(): string { return 'preview'; } });

            self::assertTrue($plan['blocked'], $mode);
            self::assertCount(1, $plan['actions']);
            self::assertStringStartsWith('BLOCKED:', $plan['actions'][0]);
            self::assertNotContains('projects.createProject', $calls);
            self::assertNotContains('services.app.createService', $calls);
            self::assertNotContains('services.mysql.createService', $calls);
            self::assertNotContains('services.app.updateEnv', $calls);
        }
    }

    public function testPlanBlocksLegacyDatabaseBeforeAnyMutation(): void
    {
        $projectName = 'shipper-api-preview-'.substr(hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)), 0, 20);
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls, $projectName): array {
            $calls[] = $procedure;
            if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [['name' => $projectName]], 'services' => [
                ['projectName' => $projectName, 'name' => 'db-main', 'type' => 'mysql'],
            ]];
            return ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={$projectName}"];
        });
        $project = new class {
            public function name(): string { return 'api'; }
            public function databases(): array { return [new class {
                public function name(): string { return 'main'; }
                public function user(): string { return 'main'; }
                public function type(): string { return 'mysql'; }
            }]; }
        };
        $provider = new EasyPanelProvider([
            'url' => 'https://panel.example.com', 'auth_token' => 'token',
            'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
        ], $client);
        $profile = new class { public function name(): string { return 'preview'; } };
        $plan = $provider->plan($project, $profile);
        self::assertTrue($plan['blocked']);
        self::assertStringContainsString('cannot safely adopt', $plan['actions'][0]);
        self::assertNotContains('services.mysql.createService', $calls);
        self::assertNotContains('services.app.updateEnv', $calls);
    }
}
