<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class PartialDatabaseProvisioningTest extends TestCase
{
    public static function readbackCases(): array { return [['saved'], ['ignored'], ['malformed']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('readbackCases')]
    public function testConfirmedConnectionIsSavedBeforeLaterFailureAndReusableByFreshProvider(string $mode): void
    {
        $project = new class {
            public function name(): string { return 'fixture'; }
            public function databases(): array { return array_map(static fn ($name) => new class($name) {
                public function __construct(private string $name) {}
                public function name(): string { return $this->name; }
                public function user(): string { return $this->name; }
                public function type(): string { return 'mysql'; }
            }, ['main', 'analytics']); }
        };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function get(string $key): mixed { return null; }
        };
        $config = ['url' => 'https://fixture.test', 'auth_token' => 'synthetic',
            'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
            'database_environment_prefixes' => ['main' => 'DB', 'analytics' => 'ANALYTICS_DB']];
        $name = (new \ReflectionMethod(EasyPanelProvider::class, 'managedProjectName'))->invoke(new EasyPanelProvider($config), $project, $profile);
        $environment = "KEEP=retained\nSHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={$name}";
        $databases = [];
        $fail = true;
        $trace = [];
        $written = false;
        $client = new EasyPanelClient('https://fixture.test', 'synthetic', transport: static function ($procedure, $input) use (&$environment, &$databases, &$fail, &$trace, &$written, $mode, $name): array {
            $trace[] = $procedure.':'.($input['serviceName'] ?? '');
            if ($procedure === 'projects.listProjectsAndServices') return ['projects' => [['name' => $name]], 'services' => array_merge(
                [['projectName' => $name, 'name' => 'app', 'type' => 'app']],
                array_map(static fn ($key) => ['projectName' => $name, 'name' => $key, 'type' => 'mysql'], array_keys($databases)),
            )];
            if ($procedure === 'services.app.inspectService') return ['env' => $written && $mode === 'malformed' ? $environment."\nBROKEN=\"unfinished" : $environment];
            if ($procedure === 'services.app.updateEnv') { $written = true; if ($mode !== 'ignored') $environment = $input['env']; return []; }
            if ($procedure === 'services.mysql.inspectService') return ['env' => $databases[$input['serviceName']]['env']];
            if ($procedure === 'services.mysql.createService') {
                if ($input['serviceName'] === 'db-analytics' && $fail) throw new \RuntimeException('synthetic later failure');
                $databases[$input['serviceName']] = $input;
            }
            return [];
        });
        $provider = new EasyPanelProvider($config, $client);
        self::assertFalse($provider->apply($project, $profile));
        if ($mode !== 'saved') {
            self::assertCount(1, $databases);
            self::assertStringContainsString('connection environment could not be verified', $provider->getLastError());
            self::assertStringNotContainsString($databases['db-main']['password'], $provider->getLastError());
            return;
        }
        $saved = \Dotenv\Dotenv::parse($environment);
        self::assertSame($databases['db-main']['password'], $saved['DB_PASSWORD'] ?? null);
        self::assertSame('retained', $saved['KEEP']);
        self::assertContains('services.app.updateEnv:app', $trace);
        self::assertLessThan(
            array_search('services.mysql.createService:db-analytics', $trace, true),
            array_search('services.app.updateEnv:app', $trace, true),
        );

        $fail = false;
        self::assertTrue((new EasyPanelProvider($config, $client))->apply($project, $profile));
        self::assertCount(2, $databases);
        self::assertSame(1, count(array_filter($trace, static fn ($call) => $call === 'services.mysql.createService:db-main')));
        $final = \Dotenv\Dotenv::parse($environment);
        self::assertSame($saved['DB_PASSWORD'], $final['DB_PASSWORD']);
        self::assertSame($databases['db-analytics']['password'], $final['ANALYTICS_DB_PASSWORD']);
    }
}
