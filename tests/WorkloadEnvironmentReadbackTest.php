<?php
namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class WorkloadEnvironmentReadbackTest extends TestCase
{
    public static function cases(): array
    {
        $cases = [];
        foreach (['worker', 'scheduler'] as $kind) foreach (['saved', 'ignored', 'malformed'] as $mode) $cases[] = [$kind, $mode];
        return $cases;
    }

    #[DataProvider('cases')]
    public function testWorkloadWriteIsVerifiedBeforeTrigger(string $kind, string $mode): void
    {
        $name = $kind === 'worker' ? 'worker-default' : 'scheduler';
        $type = $kind === 'worker' ? 'app' : 'box';
        $marker = $kind === 'worker' ? 'WORKER' : 'CRON';
        $env = "KEEP=retained\nSHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=fixture\nSHIPPERCLI_MANAGED_{$marker}={$name}";
        $calls = [];
        $client = new EasyPanelClient('https://fixture.test', 'synthetic', transport: static function ($procedure, $input) use (&$env, &$calls, $type, $name, $mode): array {
            $calls[] = $procedure;
            if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [['name' => 'fixture']], 'services' => [['projectName' => 'fixture', 'name' => $name, 'type' => $type]]];
            if (str_ends_with($procedure, '.inspectService')) return ['env' => $type === 'box' ? ['content' => $env] : $env];
            if (str_ends_with($procedure, '.updateEnv') && $mode !== 'ignored') {
                $value = $input['env'];
                $env = is_array($value) ? $value['content'] : $value;
                if ($mode === 'malformed') $env .= "\nBROKEN=\"unfinished";
            }
            return [];
        });
        $project = new class {
            public function queues(): array { return ['default' => new class {}]; }
            public function cron(): array { return ['daily' => new class {}]; }
        };
        $profile = new class { public function name(): string { return 'production'; } };
        $provider = new EasyPanelProvider(['env' => ['SECRET' => 'synthetic-literal']], $client);
        $failure = null;
        try {
            (new \ReflectionMethod($provider, $kind === 'worker' ? 'applyWorkers' : 'applyCron'))->invoke($provider, $client, $project, $profile, 'fixture', ['type' => 'git', 'repo' => 'https://github.com/example/app.git']);
        } catch (\RuntimeException $exception) { $failure = $exception; }
        self::assertSame($mode === 'saved', $failure === null);
        if ($failure === null) {
            self::assertSame(2, count(array_filter($calls, static fn ($call) => str_ends_with($call, '.inspectService'))));
            self::assertContains($kind === 'worker' ? 'services.app.deployService' : 'services.box.updateScripts', $calls);
        }
        if ($failure !== null) {
            self::assertStringContainsString('environment could not be verified', $failure->getMessage());
            self::assertStringNotContainsString('synthetic-literal', $failure->getMessage());
            self::assertNotContains('services.app.deployService', $calls);
            self::assertNotContains('services.app.updateDeploy', $calls);
            self::assertNotContains('services.box.updateScripts', $calls);
        }
    }
}
