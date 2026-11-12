<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class ProjectScopeTest extends TestCase
{
    private function named(string $name): object
    {
        return new class($name) {
            public function __construct(private string $value) {}
            public function name(): string { return $this->value; }
        };
    }

    public function testNormalizedNamesRetainExactScope(): void
    {
        $provider = new EasyPanelProvider();
        $names = [];
        foreach ([['api-web', 'test'], ['api', 'web-test'], ['API', 'web-test'], ['api_', 'web-test']] as [$project, $profile]) {
            $names[] = $provider->plan($this->named($project), $this->named($profile))['easypanel_project'];
        }
        self::assertCount(4, array_unique($names));
        self::assertSame($names[0], $provider->plan($this->named('api-web'), $this->named('test'))['easypanel_project']);
    }

    public function testLegacyProjectsBlockApplyAndDestroyBeforeWrites(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls): array {
            $calls[] = $procedure;
            return ['projects' => [['name' => 'shipper-api-staging']], 'services' => []];
        });
        $provider = new EasyPanelProvider([
            'url' => 'https://panel.example.com', 'auth_token' => 'token',
            'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
        ], $client);
        self::assertFalse($provider->apply($this->named('api'), $this->named('staging')));
        self::assertStringContainsString('legacy', $provider->getLastError());
        self::assertFalse($provider->destroy($this->named('api'), $this->named('staging')));
        self::assertStringContainsString('legacy', $provider->getLastError());
        self::assertSame(['projects.listProjectsAndServices', 'projects.listProjectsAndServices'], $calls);
    }
    public function testPreviousSharedPreviewBlocksNewDeploymentBeforeWrites(): void
    {
        $calls = [];
        $shared = 'shipper-api-preview-'.substr(hash('sha256', '["api","preview"]'), 0, 20);
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls, $shared): array {
            $calls[] = $procedure;
            return ['projects' => [['name' => $shared]], 'services' => []];
        });
        $provider = new EasyPanelProvider([
            'url' => 'https://panel.example.com', 'auth_token' => 'token',
            'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
        ], $client);
        $project = $this->named('api');
        $profile = new class {
            public function name(): string { return 'preview'; }
            public function get(string $key): ?string { return $key === 'domain' ? 'pr-1.example.com' : null; }
        };

        self::assertFalse($provider->apply($project, $profile));
        self::assertStringContainsString('legacy', $provider->getLastError());
        self::assertFalse($provider->destroy($project, $profile));
        self::assertStringContainsString('legacy', $provider->getLastError());
        self::assertFalse($provider->destroy($project, $this->named('preview')));
        self::assertStringContainsString('concrete hostname', $provider->getLastError());
        self::assertSame(['projects.listProjectsAndServices', 'projects.listProjectsAndServices'], $calls);
    }

}
