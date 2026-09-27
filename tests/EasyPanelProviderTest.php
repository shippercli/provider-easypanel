<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\Contracts\CapabilityManifest;
use ShipperCli\Contracts\DeploymentLogsProviderInterface;
use ShipperCli\Contracts\DeploymentStatusProviderInterface;
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

    public function test_provider_exposes_the_core_logs_and_status_contracts(): void
    {
        $provider = new EasyPanelProvider();

        self::assertInstanceOf(DeploymentLogsProviderInterface::class, $provider);
        self::assertInstanceOf(DeploymentStatusProviderInterface::class, $provider);
    }

    public function test_orphan_cleanup_lists_only_owned_preview_service_domains(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): mixed {
                $calls[] = [$procedure, $input];

                return match ($procedure) {
                    'projects.listProjectsAndServices' => [
                        'projects' => [['name' => 'shippercli-demo-provider-preview']],
                        'services' => [[
                            'projectName' => 'shippercli-demo-provider-preview',
                            'name' => 'web',
                            'type' => 'app',
                        ]],
                    ],
                    'services.app.inspectService' => [
                        'env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=shippercli-demo-provider-preview",
                    ],
                    'domains.listDomains' => [['host' => 'preview.example.com']],
                    default => [],
                };
            },
        );
        $provider = new EasyPanelProvider($this->config(), $client);

        $sites = $provider->listSites($this->project(), new class {
            public function name(): string { return 'preview'; }
            public function get(string $key): mixed { return $key === 'domain' ? 'preview.example.com' : null; }
        });

        self::assertCount(1, $sites);
        self::assertSame('preview.example.com', $sites[0]['domain']);
        self::assertIsInt($sites[0]['site_id']);
        self::assertSame('projects.listProjectsAndServices', $calls[0][0]);
    }

    public function test_logs_query_uses_the_derived_managed_service(): void
    {
        $calls = [];
        $client = new EasyPanelClient(
            'https://panel.example.com',
            'token',
            transport: static function (string $procedure, array $input) use (&$calls): mixed {
                $calls[] = [$procedure, $input];
                return ['logs' => [
                    ['message' => 'ready', 'stream' => 'stdout'],
                    ['text' => 'started'],
                    ['timestamp' => '2026-09-27T10:00:00Z', 'stream' => 'stdout'],
                ]];
            },
        );
        $provider = new EasyPanelProvider($this->config(), $client);

        self::assertSame(
            ['ready', 'started', '{"timestamp":"2026-09-27T10:00:00Z","stream":"stdout"}'],
            $provider->logs($this->project(), $this->profile(), 25),
        );
        self::assertSame('logs.queryServiceLogs', $calls[0][0]);
        self::assertSame('shippercli-demo-provider-v1', $calls[0][1]['projectName']);
        self::assertSame('web', $calls[0][1]['serviceName']);
        self::assertSame(25, $calls[0][1]['limit']);
    }

    public function test_logs_limit_is_clamped_to_the_api_maximum(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls): array {
            $calls[] = [$procedure, $input];
            return ['logs' => []];
        });

        (new EasyPanelProvider($this->config(), $client))->logs($this->project(), $this->profile(), 5000);

        self::assertSame(1000, $calls[0][1]['limit']);
    }

    public function test_status_inspects_the_derived_managed_service(): void
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
                    'services.app.inspectService' => [
                        'status' => 'running',
                        'image' => 'example/app:latest',
                    ],
                    default => [],
                };
            },
        );
        $provider = new EasyPanelProvider($this->config(), $client);

        self::assertSame([
            'provider' => 'easypanel',
            'state' => 'running',
            'project' => 'shippercli-demo-provider-v1',
            'service' => 'web',
            'service_state' => [
                'status' => 'running',
                'image' => 'example/app:latest',
            ],
        ], $provider->status($this->project(), $this->profile()));
        self::assertSame('projects.listProjectsAndServices', $calls[0][0]);
        self::assertSame('services.app.inspectService', $calls[1][0]);
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
            'projects.listProjectsAndServices',
            'projects.listProjectsAndServices',
            'domains.listDomains',
            'domains.createDomain',
            'services.app.deployService',
        ], \array_column($calls, 0));
        self::assertSame('shippercli-demo-provider-v1', $calls[1][1]['name']);
        self::assertSame('shippercli-demo-provider-v1', $calls[2][1]['projectName']);
        self::assertStringContainsString('SHIPPERCLI_MANAGED=1', $calls[4][1]['env']);
        self::assertStringContainsString('SHIPPERCLI_MANAGED_PROJECT=shippercli-demo-provider-v1', $calls[4][1]['env']);
        self::assertSame('demo.shippercli.com', $calls[8][1]['host']);
    }

    public function test_apply_removes_an_owned_worker_removed_from_configuration(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls): mixed {
            $calls[] = [$procedure, $input];

            return match ($procedure) {
                'projects.listProjectsAndServices' => [
                    'projects' => [['name' => 'shippercli-demo-provider-v1']],
                    'services' => [
                        ['projectName' => 'shippercli-demo-provider-v1', 'name' => 'web', 'type' => 'app'],
                        ['projectName' => 'shippercli-demo-provider-v1', 'name' => 'worker-old', 'type' => 'app'],
                    ],
                ],
                'services.app.inspectService' => ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=shippercli-demo-provider-v1\nSHIPPERCLI_MANAGED_WORKER=worker-old"],
                'domains.listDomains' => [],
                default => null,
            };
        });
        $project = new class {
            public function name(): string { return 'provider'; }
            public function repository(): array { return []; }
            public function hasSection(string $section): bool { return $section === 'queues'; }
            public function queues(): array { return []; }
        };

        self::assertTrue((new EasyPanelProvider($this->config(), $client))->apply($project, $this->profile()));
        self::assertContains('services.app.destroyService', \array_column($calls, 0));
    }

    public function test_apply_can_reconcile_an_owned_database_when_explicitly_enabled(): void
    {
        $calls = [];
        $client = new EasyPanelClient('https://panel.example.com', 'token', transport: static function (string $procedure, array $input) use (&$calls): mixed {
            $calls[] = [$procedure, $input];

            return match ($procedure) {
                'projects.listProjectsAndServices' => [
                    'projects' => [['name' => 'shippercli-demo-provider-v1']],
                    'services' => [
                        ['projectName' => 'shippercli-demo-provider-v1', 'name' => 'web', 'type' => 'app'],
                        ['projectName' => 'shippercli-demo-provider-v1', 'name' => 'db-old', 'type' => 'postgres'],
                    ],
                ],
                'services.postgres.inspectService' => ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=shippercli-demo-provider-v1"],
                'domains.listDomains' => [],
                default => null,
            };
        });
        $project = new class {
            public function name(): string { return 'provider'; }
            public function repository(): array { return []; }
            public function hasSection(string $section): bool { return $section === 'databases'; }
            public function databases(): array { return []; }
        };
        $provider = new EasyPanelProvider([...$this->config(), 'reconcile_databases' => true], $client);

        self::assertTrue($provider->apply($project, $this->profile()));
        self::assertContains('services.postgres.destroyService', \array_column($calls, 0));
    }

    public function test_apply_provisions_a_configured_database_service(): void
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
        $project = new class {
            public function name(): string { return 'provider'; }
            public function repository(): array { return []; }
            public function databases(): array { return [new class {
                public function name(): string { return 'main'; }
                public function user(): string { return 'app'; }
                public function type(): string { return 'postgresql'; }
            }]; }
        };
        $provider = new EasyPanelProvider($this->config(), $client);

        self::assertTrue($provider->apply($project, $this->profile()));
        $databaseCall = $calls[array_search('services.postgres.createService', array_column($calls, 0), true)];
        self::assertSame('db-main', $databaseCall[1]['serviceName']);
        self::assertSame('app', $databaseCall[1]['user']);
        self::assertSame('main', $databaseCall[1]['databaseName']);
        self::assertStringContainsString('SHIPPERCLI_MANAGED=1', $databaseCall[1]['env']);
        $environmentCalls = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'services.app.updateEnv'));
        self::assertStringContainsString('DB_PASSWORD=', $environmentCalls[0][1]['env']);
    }

    public function test_apply_reuses_an_existing_database_with_an_alias_type(): void
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
                        'services' => [
                            ['projectName' => 'shippercli-demo-provider-v1', 'name' => 'web', 'type' => 'app'],
                            ['projectName' => 'shippercli-demo-provider-v1', 'name' => 'db-main', 'type' => 'postgres'],
                        ],
                    ],
                    'domains.listDomains' => [],
                    default => null,
                };
            },
        );
        $project = new class {
            public function name(): string { return 'provider'; }
            public function repository(): array { return []; }
            public function databases(): array { return [new class {
                public function name(): string { return 'main'; }
                public function type(): string { return 'postgresql'; }
            }]; }
        };

        self::assertTrue((new EasyPanelProvider($this->config(), $client))->apply($project, $this->profile()));
        self::assertNotContains('services.postgres.createService', array_column($calls, 0));
    }

    public function test_apply_translates_named_cron_frequency_to_an_easy_panel_expression(): void
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
        $project = new class {
            public function name(): string { return 'provider'; }
            public function repository(): array { return []; }
            public function cron(): array { return ['nightly' => new class {
                public function command(): string { return 'php artisan schedule:run'; }
                public function frequency(): string { return 'daily'; }
                public function enabled(): bool { return true; }
            }]; }
        };
        $config = [
            ...$this->config(),
            'source' => ['type' => 'git', 'repo' => 'https://github.com/example/app.git'],
        ];

        self::assertTrue((new EasyPanelProvider($config, $client))->apply($project, $this->profile()));

        $scriptCall = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'services.box.updateScripts'));
        self::assertCount(1, $scriptCall);
        self::assertSame('0 0 * * *', $scriptCall[0][1]['scripts'][0]['schedule']);
        self::assertSame('php artisan schedule:run', $scriptCall[0][1]['scripts'][0]['content']);
        self::assertNotEmpty($scriptCall[0][1]['scripts'][0]['webhookToken']);
        $cloneCalls = array_values(array_filter($calls, static fn (array $call): bool => $call[0] === 'services.box.cloneGitRepository'));
        self::assertCount(1, $cloneCalls);
        self::assertFalse($cloneCalls[0][1]['private']);
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
