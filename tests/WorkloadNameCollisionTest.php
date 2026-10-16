<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class WorkloadNameCollisionTest extends TestCase
{
    public function testActiveWorkloadNamesMustRemainDistinctAfterNormalization(): void
    {
        foreach (['queues', 'cron'] as $section) {
            foreach ([['main_queue', 'main-queue'], ['Main', 'main'], [str_repeat('a', 48).'x', str_repeat('a', 48).'y']] as $names) {
                $calls = [];
                $client = new EasyPanelClient('https://panel.example.com', 'synthetic', transport: static function ($procedure, $input) use (&$calls): array {
                    $calls[] = $procedure;
                    return [];
                });
                $project = new class($section, $names) {
                    public function __construct(private string $section, private array $names) {}
                    public function name(): string { return 'api'; }
                    public function queues(): array { return $this->section === 'queues' ? $this->workloads() : []; }
                    public function cron(): array { return $this->section === 'cron' ? $this->workloads() : []; }
                    private function workloads(): array { return array_fill_keys($this->names, new class {
                        public function enabled(): bool { return true; }
                    }); }
                };
                $provider = new EasyPanelProvider(['url' => 'https://panel.example.com', 'auth_token' => 'synthetic',
                    'source' => ['type' => 'git', 'repo' => 'https://github.com/example/application']], $client);
                self::assertContains('EasyPanel '.$section.' names must remain distinct after normalization and truncation.', $provider->validate($project, new \stdClass));
                self::assertTrue($provider->plan($project, new \stdClass)['blocked']);
                self::assertFalse($provider->apply($project, new \stdClass));
                self::assertSame([], $calls);
            }
        }
    }
    public function testDisabledWorkloadsDoNotReserveNames(): void
    {
        $project = new class {
            public function queues(): array { return ['Main' => new class {
                public function enabled(): bool { return false; }
            }, 'main' => new class {
                public function enabled(): bool { return true; }
            }]; }
            public function cron(): array { return $this->queues(); }
        };
        $provider = new EasyPanelProvider;
        self::assertNull((new \ReflectionMethod($provider, 'assertUniqueWorkloadNames'))->invoke($provider, $project));
    }
}
