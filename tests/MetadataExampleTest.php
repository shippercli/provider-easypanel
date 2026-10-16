<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class MetadataExampleTest extends TestCase
{
    public function testImageSourceMetadataExampleValidatesWithoutApiCalls(): void
    {
        $metadata = json_decode(file_get_contents(__DIR__.'/../meta.json'), true, 512, JSON_THROW_ON_ERROR);
        $options = array_map(static fn ($value) => is_string($value) ? strtr($value, ['${EASYPANEL_AUTH_TOKEN}' => 'synthetic-token']) : $value, $metadata['config']);
        $calls = 0;
        $client = new EasyPanelClient('https://panel.example.test', 'synthetic-token', transport: static function () use (&$calls): never {
            $calls++;
            throw new \RuntimeException('Metadata validation must not access the API');
        });
        $project = new class { public function name(): string { return 'fixture'; } };
        $profile = new class {
            public function name(): string { return 'production'; }
            public function get(string $key): mixed { return $key === 'domain' ? 'fixture.test' : null; }
        };
        self::assertSame([], (new EasyPanelProvider($options, $client))->validate($project, $profile));
        self::assertSame(0, $calls);
    }
}
