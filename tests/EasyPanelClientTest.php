<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;

final class EasyPanelClientTest extends TestCase
{
    public function test_it_builds_the_current_easypanel_domain_payload(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): array {
                $calls[] = [$procedure, $input];

                return [];
            },
        );

        $client->createDomain('shipper-demo-production', 'app', 'api.example.com');

        self::assertSame('domains.createDomain', $calls[0][0]);
        self::assertSame('api.example.com', $calls[0][1]['host']);
        self::assertTrue($calls[0][1]['https']);
        self::assertSame('shipper-demo-production', $calls[0][1]['serviceDestination']['projectName']);
        self::assertSame('app', $calls[0][1]['serviceDestination']['serviceName']);
        self::assertSame(80, $calls[0][1]['serviceDestination']['port']);
    }

    public function test_it_uses_the_current_image_source_procedure(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): null {
                $calls[] = [$procedure, $input];

                return null;
            },
        );

        $client->updateImageSource('shipper-demo-production', 'app', 'nginx:alpine');

        self::assertSame([
            'services.app.updateSourceImage',
            [
                'projectName' => 'shipper-demo-production',
                'serviceName' => 'app',
                'image' => 'nginx:alpine',
            ],
        ], $calls[0]);
    }

    public function test_it_creates_a_database_service_through_the_current_typed_procedure(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): null {
                $calls[] = [$procedure, $input];

                return null;
            },
        );

        $client->createDatabaseService('postgresql', 'shipper-demo', 'db-main', [
            'databaseName' => 'main',
            'user' => 'app',
        ]);

        self::assertSame('services.postgres.createService', $calls[0][0]);
        self::assertSame('shipper-demo', $calls[0][1]['projectName']);
        self::assertSame('db-main', $calls[0][1]['serviceName']);
        self::assertSame('main', $calls[0][1]['databaseName']);
    }

    public function test_it_updates_an_app_deployment_command_for_workers(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): null {
                $calls[] = [$procedure, $input];

                return null;
            },
        );

        $client->updateAppDeployment('shipper-demo', 'worker-main', ['command' => 'php artisan queue:work']);

        self::assertSame('services.app.updateDeploy', $calls[0][0]);
        self::assertSame('php artisan queue:work', $calls[0][1]['deploy']['command']);
    }

    public function test_it_verifies_deployment_settings_after_an_empty_acknowledgement(): void
    {
        $command = 'php artisan queue:work';
        foreach ([$command, 'other command', null] as $saved) {
            $calls = [];
            $client = new EasyPanelClient(
                'https://panel.example.com',
                'token',
                transport: static function (string $procedure, array $input) use (&$calls, $saved): object|array {
                    $calls[] = [$procedure, $input];

                    return $procedure === 'services.app.updateDeploy'
                        ? new \stdClass()
                        : ['deploy' => $saved === null ? [] : ['command' => $saved]];
                },
            );

            $failure = null;
            try {
                $client->updateAppDeployment('shipper-demo', 'worker-main', ['command' => $command]);
            } catch (\RuntimeException $exception) {
                $failure = $exception->getMessage();
            }
            self::assertSame($saved === $command ? null : 'EasyPanel app deployment settings could not be verified.', $failure);
            self::assertSame(['services.app.updateDeploy', 'services.app.inspectService'], array_column($calls, 0));
            self::assertSame(['projectName' => 'shipper-demo', 'serviceName' => 'worker-main'], $calls[1][1]);
        }
    }

    public function test_it_configures_scheduled_box_scripts(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): null {
                $calls[] = [$procedure, $input];

                return null;
            },
        );

        $client->updateBoxScripts('shipper-demo', 'scheduler', [[
            'name' => 'reports',
            'content' => 'php artisan reports:generate',
            'schedule' => '0 9 * * 1',
            'enabled' => true,
        ]]);

        self::assertSame('services.box.updateScripts', $calls[0][0]);
        self::assertSame('0 9 * * 1', $calls[0][1]['scripts'][0]['schedule']);
    }

    public function test_box_environment_uses_the_openapi_content_shape(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls): null {
            $calls[] = [$procedure, $input];
            return null;
        });

        $client->updateBoxEnvironment('shipper-demo', 'scheduler', "APP_ENV=production\n");

        self::assertSame(['content' => "APP_ENV=production\n"], $calls[0][1]['env']);
    }

    public function test_log_filters_cannot_override_the_managed_service(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls): array {
            $calls[] = [$procedure, $input];
            return [];
        });

        $client->queryServiceLogs('managed', 'web', ['limit' => 20, 'projectName' => 'other', 'serviceName' => 'other', 'unexpected' => true]);

        self::assertSame([
            'limit' => 20,
            'projectName' => 'managed',
            'serviceName' => 'web',
        ], $calls[0][1]);
    }

    public function test_it_redacts_connection_values_from_debug_output(): void
    {
        $client = new EasyPanelClient('https://private-panel.example.com', 'private-token');

        ob_start();
        var_dump($client);
        $debugOutput = (string) ob_get_clean();

        self::assertStringNotContainsString('private-panel.example.com', $debugOutput);
        self::assertStringNotContainsString('private-token', $debugOutput);
        self::assertSame(2, substr_count($debugOutput, '[redacted]'));
    }

    public static function mismatchedIdentities(): array
    {
        $cases = [];
        foreach (['app', 'box', 'mysql'] as $type) {
            foreach ([['projectName', 'other'], ['projectName', null], ['name', 'other'],
                ['serviceName', 'other'], ['type', 'other'], ['name', ['synthetic-secret']]] as [$key, $value]) {
                $cases[] = [$type, $key, $value];
            }
        }
        return $cases;
    }

    private function inspect(EasyPanelClient $client, string $type): array
    {
        return match ($type) {
            'app' => $client->inspectAppService('fixture', 'target'),
            'box' => $client->inspectBoxService('fixture', 'target'),
            default => $client->inspectDatabaseService($type, 'fixture', 'target'),
        };
    }

    #[DataProvider('mismatchedIdentities')]
    public function testExplicitIdentityMustMatchRequest(string $type, string $key, mixed $value): void
    {
        $data = ['env' => 'SHIPPERCLI_MANAGED=1', $key => $value];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('inspection identity');
        $this->inspect($client, $type);
    }

    public function testMatchedAndOmittedOptionalIdentitiesRemainSupported(): void
    {
        foreach (['app', 'box', 'mysql'] as $type) {
            foreach ([['env' => 'fixture'], ['projectName' => 'fixture', 'name' => 'target', 'serviceName' => 'target', 'type' => $type, 'env' => 'fixture']] as $data) {
                $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
                self::assertSame($data, $this->inspect($client, $type));
            }
        }
    }

    public static function invalidInventories(): array
    {
        $project = ['name' => 'fixture'];
        $service = ['projectName' => 'fixture', 'name' => 'app', 'type' => 'app'];
        return [
            'duplicate projects' => [['projects' => [$project, $project], 'services' => []]],
            'padded project' => [['projects' => [['name' => ' fixture']], 'services' => []]],
            'duplicate services' => [['projects' => [$project], 'services' => [$service, $service]]],
            'conflicting types' => [['projects' => [$project], 'services' => [$service, [...$service, 'type' => 'mysql']]]],
            'orphan' => [['projects' => [], 'services' => [$service]]],
            'padded service' => [['projects' => [$project], 'services' => [[...$service, 'name' => ' app']]]],
            'padded parent' => [['projects' => [$project], 'services' => [[...$service, 'projectName' => 'fixture ']]]],
            'padded type' => [['projects' => [$project], 'services' => [[...$service, 'type' => 'app ']]]],
        ];
    }

    #[DataProvider('invalidInventories')]
    public function testRejectsAmbiguousInventories(array $inventory): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $inventory);
        $this->expectException(\RuntimeException::class);
        $client->listProjectsAndServices();
    }

    public function testServiceNamesAreScopedToProjects(): void
    {
        $inventory = ['projects' => [['name' => 'first'], ['name' => 'second']], 'services' => [
            ['projectName' => 'first', 'name' => 'app', 'type' => 'app'],
            ['projectName' => 'second', 'name' => 'app', 'type' => 'app'],
        ]];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $inventory);
        self::assertSame($inventory, $client->listProjectsAndServices());
    }

    public static function invalidInspections(): array
    {
        $cases = [];
        foreach (['app', 'box', 'mysql'] as $type) {
            foreach ([null, false, 'synthetic-secret', [['env' => 'synthetic-secret']]] as $data) $cases[] = [$type, $data];
        }
        return $cases;
    }

    #[DataProvider('invalidInspections')]
    public function testInspectionsDoNotNormalizeUnreadableData(string $type, mixed $data): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        $this->expectException(\RuntimeException::class);
        match ($type) {
            'app' => $client->inspectAppService('fixture', 'app'),
            'box' => $client->inspectBoxService('fixture', 'worker'),
            'mysql' => $client->inspectDatabaseService('mysql', 'fixture', 'db'),
        };
    }

    public static function invalidDomains(): array
    {
        return array_map(fn ($data) => [$data], [null, false, 'synthetic-secret',
            ['named' => ['host' => 'fixture.test']], [[]], [['host' => '']], [['host' => ' fixture.test']]]);
    }

    #[DataProvider('invalidDomains')]
    public function testUnreadableDomainsCannotBecomeEmptyInventory(mixed $data): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        $this->expectException(\RuntimeException::class);
        $client->listDomains('fixture', 'app');
    }

    public function testValidDomainsAndInspectionsArePreserved(): void
    {
        foreach ([[], [['host' => 'fixture.test', 'path' => '/']], [['host' => 'fixture.test', 'path' => '/a'], ['host' => 'fixture.test', 'path' => '/b']]] as $data) {
            $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
            self::assertSame($data, $client->listDomains('fixture', 'app'));
        }
        $data = ['env' => 'SHIPPERCLI_MANAGED=1'];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: fn () => $data);
        self::assertSame($data, $client->inspectAppService('fixture', 'app'));
        self::assertSame($data, $client->inspectBoxService('fixture', 'worker'));
        self::assertSame($data, $client->inspectDatabaseService('mysql', 'fixture', 'db'));
    }

    public static function invalidEnvelopes(): array
    {
        return array_map(fn ($body) => [$body], [
            '{}', '[]', 'null', '42', '"synthetic-secret"',
            '{"error":{"json":{"message":"synthetic-secret"}}}',
            '{"json":null,"error":{"message":"synthetic-secret"}}',
            '{"json":null,"errors":["synthetic-secret"]}',
            '{"json":null,"success":false}',
            '{"result":{"data":"synthetic-secret"}}',
            '{"result":{"data":{"json":null},"error":"synthetic-secret"}}',
            '{"json":null,"result":{"data":{"json":null}}}',
            '{"json":',
        ]);
    }

    #[DataProvider('invalidEnvelopes')]
    public function testInvalidEnvelopesFailSafely(string $body): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic');
        $failure = null;
        try {
            (new \ReflectionMethod($client, 'decodeResponse'))->invoke($client, $body, 'fixture');
        } catch (\Throwable $exception) { $failure = $exception; }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringNotContainsString('synthetic-secret', $failure->getMessage());
        self::assertNull($failure->getPrevious());
    }

    public function testValidNullMutationPayloadsAreExplicit(): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic');
        foreach (['{"json":null}', '{"result":{"data":{"json":null}}}'] as $body) {
            self::assertNull((new \ReflectionMethod($client, 'decodeResponse'))->invoke($client, $body, 'fixture'));
        }
    }

    public function test_running_containers_must_match_the_exact_managed_service(): void
    {
        $project = 'fixture';
        $service = 'app';
        $containers = [
            (object) ['State' => 'running', 'Labels' => (object) ['com.docker.swarm.service.name' => 'other_app']],
            (object) ['State' => 'exited', 'Labels' => (object) ['com.docker.swarm.service.name' => 'fixture_app']],
        ];
        $client = new EasyPanelClient(
            'https://panel.example.test',
            'synthetic',
            transport: static function (string $procedure, array $input) use (&$containers): array {
                self::assertSame('getDockerContainers', $procedure);
                self::assertSame(['service' => 'fixture_app'], $input);

                return $containers;
            },
        );

        self::assertFalse($client->hasRunningAppContainer($project, $service));
        $containers[] = (object) ['State' => 'running', 'Labels' => (object) ['com.docker.swarm.service.name' => 'fixture_app']];
        self::assertTrue($client->hasRunningAppContainer($project, $service));
    }

    public function test_database_payload_cannot_override_target_identity(): void
    {
        $client = new EasyPanelClient(
            'https://panel.example.test',
            'synthetic',
            transport: static fn () => self::fail('An invalid target must be rejected before transport.'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not override the target identity');
        $client->createDatabaseService('mysql', 'fixture', 'main', ['serviceName' => 'other']);
    }
}
