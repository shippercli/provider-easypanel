<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class DatabaseEnvironmentTest extends TestCase
{
    private function project(): object
    {
        return new class {
            public function databases(): array
            {
                return array_map(static fn (string $name): object => new class($name) {
                    public function __construct(private string $database) {}
                    public function name(): string { return $this->database; }
                    public function user(): string { return $this->database; }
                    public function type(): string { return 'mysql'; }
                }, ['main', 'analytics']);
            }
        };
    }

    public function testEachDatabaseGetsDistinctApplicationConnectionVariables(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.test', 'token', transport: static function (string $procedure, array $input) use (&$calls): array {
            if (str_ends_with($procedure, 'createService')) $calls[] = $input;
            return [];
        });
        $provider = new EasyPanelProvider(['database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']], $client);
        $environment = (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $this->project(), 'owned-project');

        self::assertCount(2, $calls);
        self::assertSame($calls[0]['password'], $environment['DB_PASSWORD']);
        self::assertSame($calls[1]['password'], $environment['ANALYTICS_DB_PASSWORD']);
        self::assertNotSame($environment['DB_PASSWORD'], $environment['ANALYTICS_DB_PASSWORD']);
        self::assertSame('db-main', $environment['DB_HOST']);
        self::assertSame('db-analytics', $environment['ANALYTICS_DB_HOST']);
    }

    public function testAmbiguousMappingsFailBeforeProviderCalls(): void
    {
        foreach ([[], ['main' => 'DB', 'analytics' => 'DB'], ['main' => 'DB', 'analytics' => "BAD\nKEY"]] as $prefixes) {
            $calls = [];
            $client = new EasyPanelClient('https://panel.example.test', 'token', transport: static function () use (&$calls): array { $calls[] = true; return []; });
            $provider = new EasyPanelProvider(['database_environment_prefixes' => $prefixes], $client);
            try {
                (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $this->project(), 'owned-project');
                self::fail('Ambiguous database mappings must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $calls);
            }
        }
    }
}
