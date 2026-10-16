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
            if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [], 'services' => []];
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
    public function testAnExistingUnmanagedDatabaseIsNotReused(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.test', 'token', transport: static function (string $procedure, array $input) use (&$calls): mixed {
            $calls[] = $procedure;
            return match ($procedure) {
                'projects.listProjectsAndServices' => ['projects' => [['name' => 'owned-project']], 'services' => [['projectName' => 'owned-project', 'name' => 'db-main', 'type' => 'mysql']]],
                'services.mysql.inspectService' => ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=someone-else"],
                default => throw new \LogicException('Unexpected mutation: '.$procedure),
            };
        });
        $provider = new EasyPanelProvider(['database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']], $client);
        try {
            (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $this->project(), 'owned-project');
            self::fail('An unmanaged database must not be reused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ownership markers', $exception->getMessage());
            self::assertSame(['projects.listProjectsAndServices', 'services.mysql.inspectService'], $calls);
        }
    }

    public function testExistingDatabasesRequireMatchingConnectionSettingsWithoutRotation(): void
    {
        foreach ([
            ["DB_HOST=db-main\nDB_DATABASE=main\nDB_USERNAME=main\nDB_PASSWORD='retained-secret'", true],
            ["DB_HOST=db-main\nDB_DATABASE=main\nDB_USERNAME=main", false],
            ["DB_HOST=db-main\nDB_DATABASE=other\nDB_USERNAME=main\nDB_PASSWORD=retained-secret", false],
            ["DB_PASSWORD=\"unterminated", false],
        ] as [$content, $valid]) {
            $calls = [];
            $client = new EasyPanelClient('https://panel.example.test', 'token', transport: static function (string $procedure, array $input) use (&$calls): mixed {
                $calls[] = $procedure;
                return match ($procedure) {
                    'projects.listProjectsAndServices' => ['projects' => [['name' => 'owned-project']], 'services' => [['projectName' => 'owned-project', 'name' => 'db-main', 'type' => 'mysql']]],
                    'services.mysql.inspectService' => ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=owned-project"],
                    default => throw new \LogicException('Unexpected mutation: '.$procedure),
                };
            });
            $project = new class {
                public function databases(): array { return [new class {
                    public function name(): string { return 'main'; }
                    public function user(): string { return 'main'; }
                    public function type(): string { return 'mysql'; }
                }]; }
            };
            $provider = new EasyPanelProvider([], $client);
            try {
                $result = (new \ReflectionMethod($provider, 'applyDatabases'))->invoke($provider, $client, $project, 'owned-project', $content);
                self::assertTrue($valid);
                self::assertSame([], $result);
            } catch (\RuntimeException $exception) {
                self::assertFalse($valid);
                self::assertStringNotContainsString('retained-secret', $exception->getMessage());
            }
            self::assertSame(['projects.listProjectsAndServices', 'services.mysql.inspectService'], $calls);
        }
    }

}
