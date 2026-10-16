<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class CleanupVerificationTest extends TestCase
{
    public static function cleanupStates(): array
    {
        return [['absent', true], ['retain-project', true], ['other-service', true],
            ['pending-app', false], ['pending-project', false], ['unreadable', false]];
    }

    #[DataProvider('cleanupStates')]
    public function testCleanupRequiresFreshAbsenceEvidence(string $case, bool $expected): void
    {
        $project = new class { public function name(): string { return 'fixture'; } };
        $profile = new class { public function name(): string { return 'production'; } };
        $calls = [];
        $appDeleted = $projectDeleted = false;
        $name = '';
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: function ($procedure, $input) use (&$calls, &$appDeleted, &$projectDeleted, &$name, $case) {
            $calls[] = $procedure;
            if ($procedure === 'services.app.destroyService') { $appDeleted = true; return null; }
            if ($procedure === 'projects.destroyProject') { $projectDeleted = true; return null; }
            if ($procedure === 'services.app.inspectService') return ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=".$name];
            if ($procedure !== 'projects.listProjectsAndServices') throw new \LogicException('Unexpected mutation');
            if ($appDeleted && $case === 'unreadable') return null;
            $projects = $projectDeleted && $case !== 'pending-project' ? [] : [['name' => $name]];
            $services = !$appDeleted || $case === 'pending-app' ? [['projectName' => $name, 'name' => 'web', 'type' => 'app']] : [];
            if ($appDeleted && $case === 'other-service') $services[] = ['projectName' => $name, 'name' => 'other', 'type' => 'app'];
            return ['projects' => $projects, 'services' => $services];
        });
        $provider = new EasyPanelProvider(['url' => 'https://example.test', 'auth_token' => 'synthetic', 'service_name' => 'web',
            'destroy_project' => !in_array($case, ['retain-project', 'pending-app'], true)], $client);
        $name = (new \ReflectionMethod($provider, 'managedProjectName'))->invoke($provider, $project, $profile);
        self::assertSame($expected, $provider->destroy($project, $profile));
        self::assertSame(1, count(array_filter($calls, fn ($call) => $call === 'services.app.destroyService')));
        if (in_array($case, ['pending-app', 'unreadable', 'other-service', 'retain-project'], true)) {
            self::assertNotContains('projects.destroyProject', $calls);
        }
    }
}
