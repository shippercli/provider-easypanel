<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class EnvironmentTest extends TestCase
{
    public function testCanonicalEnvironmentMergesAndLiteralValuesRoundTrip(): void
    {
        $source = static fn (array $values): object => new class($values) {
            public function __construct(private array $values) {}
            public function environment(): object {
                return new class($this->values) {
                    public function __construct(private array $values) {}
                    public function variables(): array { return $this->values; }
                };
            }
        };
        $project = $source(['PROJECT_ONLY' => 'included', 'SHARED' => 'project', 'LITERAL' => '$literal #value', 'MULTILINE' => "first\nsecond"]);
        $profile = $source(['SHARED' => 'profile']);
        $provider = new EasyPanelProvider(['env' => ['SHARED' => 'provider']]);
        $text = (new \ReflectionMethod($provider, 'environment'))->invoke($provider, 'project', $profile, ['DB_PASSWORD' => 'generated'], 'RETAINED=secret', $project);
        $values = \Dotenv\Dotenv::parse($text);
        self::assertSame('included', $values['PROJECT_ONLY']);
        self::assertSame('profile', $values['SHARED']);
        self::assertSame('$literal #value', $values['LITERAL']);
        self::assertSame("first\nsecond", $values['MULTILINE']);
        self::assertSame('secret', $values['RETAINED']);
        self::assertSame('generated', $values['DB_PASSWORD']);
        self::assertSame('project', $values['SHIPPERCLI_MANAGED_PROJECT']);
    }

    public function testVariableNamesCannotInjectAssignments(): void
    {
        $provider = new EasyPanelProvider(['env' => ["BAD\nKEY" => 'value']]);
        $this->expectException(\InvalidArgumentException::class);
        (new \ReflectionMethod($provider, 'environment'))->invoke($provider, 'project', new \stdClass);
    }
}
