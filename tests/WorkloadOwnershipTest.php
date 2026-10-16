<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class WorkloadOwnershipTest extends TestCase
{
    public function testWorkloadUpdatesRequireOwnershipAndPreserveExistingSecrets(): void
    {
        foreach (['WORKER', 'CRON'] as $kind) {
            foreach ([false, true] as $owned) {
                $name = $kind === 'WORKER' ? 'worker-default' : 'scheduler';
                $type = $kind === 'WORKER' ? 'app' : 'box';
                $calls = [];
                $env = 'DB_PASSWORD=retained-secret';
                if ($owned) $env .= "\nSHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=project\nSHIPPERCLI_MANAGED_{$kind}={$name}";
                $client = new EasyPanelClient('https://panel.example.test', 'token', transport: static function (string $procedure, array $input) use (&$calls, $env, $name, $type): mixed {
                    $calls[] = [$procedure, $input];
                    if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [['name' => 'project']], 'services' => [['projectName' => 'project', 'name' => $name, 'type' => $type]]];
                    if (str_ends_with($procedure, 'inspectService')) return ['env' => $type === 'box' ? ['content' => $env] : $env];
                    return null;
                });
                $project = new class {
                    public function queues(): array { return ['default' => new class {}]; }
                    public function cron(): array { return ['daily' => new class {}]; }
                };
                $profile = new class { public function name(): string { return 'production'; } };
                $provider = new EasyPanelProvider([], $client);
                $method = new \ReflectionMethod($provider, $kind === 'WORKER' ? 'applyWorkers' : 'applyCron');
                $failure = null;
                try {
                    $method->invoke($provider, $client, $project, $profile, 'project', ['type' => 'git', 'repo' => 'https://github.com/example/app.git']);
                } catch (\RuntimeException $exception) { $failure = $exception; }
                self::assertSame($owned, $failure === null, 'Workload ownership result did not match its scope.');
                if ($failure === null) {
                    $updates = array_values(array_filter($calls, static fn (array $call): bool => str_ends_with($call[0], 'updateEnv')));
                    self::assertCount(1, $updates);
                    $updated = $updates[0][1]['env'];
                    self::assertStringContainsString('DB_PASSWORD=retained-secret', is_array($updated) ? $updated['content'] : $updated);
                } else {
                    self::assertStringContainsString('ownership markers', $failure->getMessage());
                    foreach ($calls as [$procedure]) self::assertTrue($procedure === 'projects.listProjectsAndServices' || str_ends_with($procedure, 'inspectService'));
                }
            }
        }
    }
    public function testSchedulerCleanupAcceptsNestedOwnedEnvironmentAndRejectsOtherScopes(): void
    {
        foreach ([true, false] as $owned) {
            $calls = [];
            $project = $owned ? 'project' : 'another-project';
            $env = "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={$project}\nSHIPPERCLI_MANAGED_CRON=scheduler";
            $client = new EasyPanelClient('https://panel.example.test', 'token', transport: static function (string $procedure, array $input) use (&$calls, $env): mixed {
                $calls[] = $procedure;
                if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [['name' => 'project']], 'services' => []];
                return str_ends_with($procedure, 'inspectService') ? ['env' => ['content' => $env]] : null;
            });
            $provider = new EasyPanelProvider([], $client);
            $failure = null;
            try {
                (new \ReflectionMethod($provider, 'destroyOwnedCronService'))->invoke($provider, $client, 'project', ['type' => 'box']);
            } catch (\RuntimeException $exception) { $failure = $exception; }
            self::assertSame($owned, $failure === null);
            if ($failure === null) {
                self::assertSame(['services.box.inspectService', 'services.box.destroyService', 'projects.listProjectsAndServices'], $calls);
            } else {
                self::assertStringContainsString('ownership markers', $failure->getMessage());
                self::assertSame(['services.box.inspectService'], $calls);
            }
        }
    }

    public function testCronCleanupRefusesOwnershipFromIncompleteEnvironment(): void
    {
        $calls = [];
        $env = "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=project\nSHIPPERCLI_MANAGED_CRON=scheduler\nBROKEN=\"unfinished";
        $client = new EasyPanelClient('https://panel.example.test', 'token', transport: static function (string $procedure, array $input) use (&$calls, $env): mixed {
            $calls[] = $procedure;
            if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [['name' => 'project']], 'services' => []];
            return str_ends_with($procedure, 'inspectService') ? ['env' => ['content' => $env]] : null;
        });
        $provider = new EasyPanelProvider([], $client);
        $failure = null;
        try {
            (new \ReflectionMethod($provider, 'destroyOwnedCronService'))->invoke($provider, $client, 'project', ['type' => 'box']);
        } catch (\RuntimeException $exception) { $failure = $exception; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame(['services.box.inspectService'], $calls);
    }

}
