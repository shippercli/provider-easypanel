<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class StatusSafetyTest extends TestCase
{
    public function testStatusDoesNotExposeInspectionSecretsOrInventDeploymentSuccess(): void
    {
        $project = new class { public function name(): string { return 'fixture'; } };
        $profile = new class { public function name(): string { return 'production'; } };
        $name = (new \ReflectionMethod(EasyPanelProvider::class, 'managedProjectName'))->invoke(new EasyPanelProvider, $project, $profile);
        foreach ([['running', 'running'], [null, 'unknown'], [false, 'unknown'], ['   ', 'unknown']] as [$state, $expected]) {
            $client = new EasyPanelClient('https://panel.example.test', 'synthetic-token', transport: static function (string $procedure) use ($name, $state): mixed {
                return match ($procedure) {
                    'projects.listProjectsAndServices' => ['projects' => [['name' => $name]], 'services' => [['projectName' => $name, 'name' => 'app', 'type' => 'app']]],
                    'services.app.inspectService' => ['status' => $state, 'env' => 'DB_PASSWORD=synthetic-secret', 'source' => ['token' => 'synthetic-secret'], 'image' => 'user:synthetic-secret@registry.example/app'],
                    default => throw new \RuntimeException('Unexpected status API call'),
                };
            });
            $result = (new EasyPanelProvider([], $client))->status($project, $profile);
            self::assertSame($expected, $result['state']);
            self::assertStringNotContainsString('synthetic-secret', json_encode($result, JSON_THROW_ON_ERROR));
            self::assertSame(['provider', 'state', 'project', 'service'], array_keys($result));
        }
    }
}
