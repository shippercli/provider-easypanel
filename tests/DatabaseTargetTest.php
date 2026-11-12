<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;

final class DatabaseTargetTest extends TestCase
{
    public static function conflictingTargets(): array
    {
        $cases = [];
        foreach (['projectName', 'serviceName'] as $field) {
            foreach (['other-target', null, false, ['synthetic-secret']] as $value) $cases[] = [[$field => $value]];
        }
        return $cases;
    }

    #[DataProvider('conflictingTargets')]
    public function testPayloadCannotChangeExplicitTarget(array $payload): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: function ($procedure, $input) use (&$calls) {
            $calls[] = [$procedure, $input];
            return null;
        });
        try {
            $client->createDatabaseService('mysql', 'fixture', 'db', $payload);
            self::fail('Payload overrode explicit target');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame([], $calls);
            self::assertStringNotContainsString('synthetic-secret', $exception->getMessage());
        }
    }

    public function testMatchingTargetsAndDatabaseOptionsArePreserved(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: function ($procedure, $input) use (&$calls) {
            $calls[] = [$procedure, $input];
            return null;
        });
        $payload = ['projectName' => 'fixture', 'serviceName' => 'db', 'databaseName' => 'application', 'password' => 'synthetic-password'];
        $client->createDatabaseService('postgresql', 'fixture', 'db', $payload);
        self::assertSame([['services.postgres.createService', $payload]], $calls);
    }
}
