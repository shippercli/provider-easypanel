<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class DatabaseNameCollisionTest extends TestCase
{
    public function testCollidingDatabaseServicesAreRejectedBeforeApiCalls(): void
    {
        foreach ([['main_db', 'main-db'], ['Main', 'main'], [str_repeat('a', 48).'x', str_repeat('a', 48).'y']] as $names) {
            $calls = [];
            $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls): array {
                $calls[] = $procedure;
                return [];
            });
            $provider = new EasyPanelProvider([
                'url' => 'https://panel.example.com', 'auth_token' => 'token',
                'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
                'database_environment_prefixes' => [$names[0] => 'PRIMARY', $names[1] => 'SECONDARY'],
            ], $client);
            $project = new class($names) {
                public function __construct(private array $names) {}
                public function name(): string { return 'api'; }
                public function databases(): array {
                    return array_map(static fn (string $name): object => new class($name) {
                        public function __construct(private string $value) {}
                        public function name(): string { return $this->value; }
                    }, $this->names);
                }
            };
            self::assertFalse($provider->apply($project, new \stdClass));
            self::assertStringContainsString('distinct service names', $provider->getLastError());
            self::assertSame([], $calls);
        }
    }
}
