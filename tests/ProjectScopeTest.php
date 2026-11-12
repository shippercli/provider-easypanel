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
            return ['projects' => [['name' => 'shipper-api-preview']], 'services' => []];
        });
        $provider = new EasyPanelProvider([
            'url' => 'https://panel.example.com', 'auth_token' => 'token',
            'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
        ], $client);
        self::assertFalse($provider->apply($this->named('api'), $this->named('preview')));
        self::assertStringContainsString('legacy', $provider->getLastError());
        self::assertFalse($provider->destroy($this->named('api'), $this->named('preview')));
        self::assertStringContainsString('legacy', $provider->getLastError());
        self::assertSame(['projects.listProjectsAndServices', 'projects.listProjectsAndServices'], $calls);
    }
}
