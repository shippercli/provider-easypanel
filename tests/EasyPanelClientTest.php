<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel\Tests;

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
}
