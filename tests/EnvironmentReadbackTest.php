<?php
namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class EnvironmentReadbackTest extends TestCase
{
    public static function cases(): array { return [['saved', true], ['ignored', false], ['malformed', false], ['missing', false], ['wrong-type', false], ['lost-retained', false]]; }

    #[DataProvider('cases')]
    public function testFinalEnvironmentRequiresReadback(string $mode, bool $valid): void
    {
        $project = new class { public function name(): string { return 'fixture'; } };
        $profile = new class { public function name(): string { return 'production'; } public function get(string $key): mixed { return null; } };
        $config = ['url' => 'https://fixture.test', 'auth_token' => 'synthetic', 'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
            'env' => ['SECRET' => 'synthetic-$literal', 'EMPTY' => '', 'ZERO' => 0]];
        $name = (new \ReflectionMethod(EasyPanelProvider::class, 'managedProjectName'))->invoke(new EasyPanelProvider($config), $project, $profile);
        $env = "KEEP=retained\nSHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={$name}";
        $trace = [];
        $written = false;
        $client = new EasyPanelClient('https://fixture.test', 'synthetic', transport: static function ($procedure, $input) use (&$env, &$trace, &$written, $mode, $name): array {
            $trace[] = $procedure;
            if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [['name' => $name]], 'services' => [['projectName' => $name, 'name' => 'app', 'type' => 'app']]];
            if ($procedure === 'services.app.inspectService') {
                if ($written && $mode === 'missing') return [];
                if ($written && $mode === 'wrong-type') return ['env' => false];
                return ['env' => $env];
            }
            if ($procedure === 'services.app.updateEnv') {
                $written = true;
                if ($mode !== 'ignored') $env = $mode === 'malformed' ? "BROKEN=\"unfinished" : $input['env'];
                if ($mode === 'lost-retained') $env = str_replace("KEEP=retained\n", '', $env);
            }
            return [];
        });
        $provider = new EasyPanelProvider($config, $client);
        self::assertSame($valid, $provider->apply($project, $profile));
        self::assertSame($valid, in_array('services.app.deployService', $trace, true));
        if (! $valid) {
            self::assertStringContainsString('application environment could not be verified', $provider->getLastError());
            self::assertStringNotContainsString('synthetic-$literal', $provider->getLastError());
        }
    }
}
