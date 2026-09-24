<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\Contracts\CapabilityManifest;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelPlugin;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class EasyPanelProviderTest extends TestCase
{
    public function test_plugin_registers_the_provider(): void
    {
        self::assertSame(['easypanel' => EasyPanelProvider::class], (new EasyPanelPlugin())->providers());
    }

    public function test_capability_manifest_conforms_to_the_shared_contract(): void
    {
        $capabilities = (new EasyPanelProvider())->capabilities();

        self::assertSame($capabilities, CapabilityManifest::from($capabilities)->toArray());
    }

    public function test_logs_query_uses_the_derived_managed_service(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): mixed {
                $calls[] = [$procedure, $input];
                return ['logs' => [['message' => 'ready', 'stream' => 'stdout']]];
            },
        );
        $provider = new EasyPanelProvider($this->config(), $client);

        self::assertSame(
            [['message' => 'ready', 'stream' => 'stdout']],
            $provider->logs($this->project(), $this->profile(), ['limit' => 25]),
        );
        self::assertSame('logs.queryServiceLogs', $calls[0][0]);
        self::assertSame('shippercli-demo-provider-v1', $calls[0][1]['projectName']);
        self::assertSame('web', $calls[0][1]['serviceName']);
        self::assertSame(25, $calls[0][1]['limit']);
    }

    public function test_apply_creates_and_deploys_only_the_derived_managed_resources(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): mixed {
                $calls[] = [$procedure, $input];

                return match ($procedure) {
                    'projects.listProjectsAndServices' => ['projects' => [], 'services' => []],
                    'domains.listDomains' => [],
                    default => null,
                };
            },
        );
        $provider = new EasyPanelProvider($this->config(), $client);

        self::assertTrue($provider->apply($this->project(), $this->profile()));
        self::assertSame([
            'projects.listProjectsAndServices',
            'projects.createProject',
            'services.app.createService',
            'services.app.updateSourceImage',
            'services.app.updateEnv',
            'domains.listDomains',
            'domains.createDomain',
            'services.app.deployService',
        ], \array_column($calls, 0));
        self::assertSame('shippercli-demo-provider-v1', $calls[1][1]['name']);
        self::assertSame('shippercli-demo-provider-v1', $calls[2][1]['projectName']);
        self::assertStringContainsString('SHIPPERCLI_MANAGED=1', $calls[4][1]['env']);
        self::assertStringContainsString('SHIPPERCLI_MANAGED_PROJECT=shippercli-demo-provider-v1', $calls[4][1]['env']);
        self::assertSame('demo.shippercli.com', $calls[6][1]['host']);
    }

    public function test_destroy_refuses_a_service_without_ownership_markers(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): mixed {
                $calls[] = [$procedure, $input];

                return match ($procedure) {
                    'projects.listProjectsAndServices' => [
                        'projects' => [['name' => 'shippercli-demo-provider-v1']],
                        'services' => [[
                            'projectName' => 'shippercli-demo-provider-v1',
                            'name' => 'web',
                            'type' => 'app',
                        ]],
                    ],
                    'services.app.inspectService' => ['env' => 'APP_ENV=production'],
                    default => null,
                };
            },
        );
        $provider = new EasyPanelProvider($this->config(), $client);

        self::assertFalse($provider->destroy($this->project(), $this->profile()));
        self::assertStringContainsString('without Shipper ownership markers', $provider->getLastError());
        self::assertNotContains('services.app.destroyService', \array_column($calls, 0));
        self::assertNotContains('projects.destroyProject', \array_column($calls, 0));
    }

    public function test_destroy_removes_only_a_marked_service_and_then_its_empty_managed_project(): void
    {
        $calls = [];
        $listCount = 0;
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls, &$listCount): mixed {
                $calls[] = [$procedure, $input];
                if ($procedure === 'projects.listProjectsAndServices') {
                    $listCount++;

                    return [
                        'projects' => [['name' => 'shippercli-demo-provider-v1']],
                        'services' => $listCount === 1 ? [[
                            'projectName' => 'shippercli-demo-provider-v1',
                            'name' => 'web',
                            'type' => 'app',
                        ]] : [],
                    ];
                }

                if ($procedure === 'services.app.inspectService') {
                    return ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=shippercli-demo-provider-v1"];
                }

                return null;
            },
        );
        $provider = new EasyPanelProvider($this->config(), $client);

        self::assertTrue($provider->destroy($this->project(), $this->profile()));
        self::assertContains('services.app.destroyService', \array_column($calls, 0));
        self::assertContains('projects.destroyProject', \array_column($calls, 0));
    }

    public function test_validation_requires_credentials_and_a_source(): void
    {
        $provider = new EasyPanelProvider();

        $errors = $provider->validate($this->project(), $this->profile());

        self::assertNotEmpty($errors);
        self::assertStringContainsString('URL', \implode(' ', $errors));
        self::assertStringContainsString('auth token', \implode(' ', $errors));
        self::assertStringContainsString('source', \implode(' ', $errors));
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return [
            'url' => 'https://panel.example.com',
            'auth_token' => 'token',
            'managed_prefix' => 'shippercli-demo-',
            'service_name' => 'web',
            'source' => [
                'type' => 'image',
                'image' => 'nginx:alpine',
            ],
        ];
    }

    private function project(): object
    {
        return new class {
            public function name(): string
            {
                return 'provider';
            }

            /** @return array<string, string> */
            public function repository(): array
            {
                return [];
            }
        };
    }

    private function profile(): object
    {
        return new class {
            public function name(): string
            {
                return 'v1';
            }

            public function branch(): string
            {
                return 'main';
            }

            public function get(string $key): mixed
            {
                return match ($key) {
                    'domain' => 'demo.shippercli.com',
                    'env' => ['APP_ENV' => 'production'],
                    default => null,
                };
            }
        };
    }
}
