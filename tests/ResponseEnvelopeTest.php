<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;

final class ResponseEnvelopeTest extends TestCase
{
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
        try {
            (new \ReflectionMethod($client, 'decodeResponse'))->invoke($client, $body, 'fixture');
            self::fail('Invalid response was accepted');
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('synthetic-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testValidNullMutationPayloadsAreExplicit(): void
    {
        $client = new EasyPanelClient('https://example.test', 'synthetic');
        foreach (['{"json":null}', '{"result":{"data":{"json":null}}}'] as $body) {
            self::assertNull((new \ReflectionMethod($client, 'decodeResponse'))->invoke($client, $body, 'fixture'));
        }
    }
}
