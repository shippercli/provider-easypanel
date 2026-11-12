<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class ApplyOwnershipPreflightTest extends TestCase
{
    public function testApplyChecksExistingDatabaseAndWorkerOwnershipBeforeCreatingResources(): void
    {
        $projectName = 'shipper-api-preview-'.substr(hash('sha256', json_encode(['api', 'preview'], JSON_THROW_ON_ERROR)), 0, 20);

        foreach (['database', 'worker'] as $collision) {
            $calls = [];
            $service = $collision === 'database'
                ? ['projectName' => $projectName, 'name' => 'db-main', 'type' => 'mysql']
                : ['projectName' => $projectName, 'name' => 'worker-main', 'type' => 'app'];
            $client = new EasyPanelClient(
                'https://panel.example.test',
                'token',
                transport: static function (string $procedure, array $input) use (&$calls, $projectName, $service, $collision): array {
                    $calls[] = [$procedure, $input];

                    return match ($procedure) {
                        'projects.listProjectsAndServices' => [
                            'projects' => [['name' => $projectName]],
                            'services' => [$service],
                        ],
                        'services.mysql.inspectService', 'services.app.inspectService' => [
                            'env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=another-scope",
                        ],
                        default => [],
                    };
                },
            );
            $project = new class($collision)
            {
                public function __construct(private string $collision) {}
                public function name(): string { return 'api'; }
                public function databases(): array
                {
                    return $this->collision === 'database' ? [new class {
                        public function name(): string { return 'main'; }
                        public function user(): string { return 'main'; }
                        public function type(): string { return 'mysql'; }
                    }] : [];
                }
                public function queues(): array
                {
                    return $this->collision === 'worker' ? ['main' => new class {
                        public function enabled(): bool { return true; }
                    }] : [];
                }
            };
            $profile = new class
            {
                public function name(): string { return 'preview'; }
            };
            $provider = new EasyPanelProvider([
                'url' => 'https://panel.example.test',
                'auth_token' => 'token',
                'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
            ], $client);

            self::assertFalse($provider->apply($project, $profile), $collision);
            self::assertStringContainsString(
                $collision === 'database' ? 'cannot safely adopt' : 'lacks matching project ownership markers',
                $provider->getLastError(),
                $collision,
            );
            self::assertSame([
                'projects.listProjectsAndServices',
                $collision === 'database' ? 'services.mysql.inspectService' : 'services.app.inspectService',
            ], array_column($calls, 0), $collision);
            self::assertSame(
                [],
                array_values(array_filter(
                    array_column($calls, 0),
                    static fn (string $procedure): bool => ! in_array($procedure, [
                        'projects.listProjectsAndServices',
                        'services.mysql.inspectService',
                        'services.app.inspectService',
                    ], true),
                )),
                $collision.' must be rejected before any mutation.',
            );
        }
    }
}
