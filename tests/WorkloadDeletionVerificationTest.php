<?php

namespace ShipperCli\ProviderEasyPanel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

final class WorkloadDeletionVerificationTest extends TestCase
{
    public function testDatabaseReconciliationDeletesOnlyAnExactCreatedMarker(): void
    {
        foreach ([false, true, 'wrong-service'] as $marked) {
            $deleted = false;
            $name = 'db-old';
            $hash = substr(hash('sha256', json_encode(['fixture', $name], JSON_THROW_ON_ERROR)), 0, 24);
            $copiedHash = is_string($marked) ? substr(hash('sha256', json_encode(['fixture', 'db-other'], JSON_THROW_ON_ERROR)), 0, 24) : $hash;
            $environment = "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=fixture".($marked ? "\nSHIPPERCLI_DATABASE_CREATED={$copiedHash}" : '');
            $inventory = ['projects' => [['name' => 'fixture']], 'services' => [['projectName' => 'fixture', 'name' => $name, 'type' => 'mysql']]];
            $client = new EasyPanelClient('https://example.test', 'synthetic', transport: function ($procedure) use (&$inventory, &$deleted, $environment) {
                if ($procedure === 'services.mysql.inspectService') return ['env' => $environment];
                if ($procedure === 'services.mysql.destroyService') { $deleted = true; $inventory['services'] = []; return null; }
                if ($procedure === 'projects.listProjectsAndServices') return $inventory;
                return null;
            });
            (new \ReflectionMethod(new EasyPanelProvider, 'reconcileDatabases'))->invoke(new EasyPanelProvider, $client, new \stdClass, 'fixture', $inventory);
            self::assertSame($marked === true, $deleted);
        }
    }

    public static function deletionStates(): array
    {
        $cases = [];
        foreach (['worker', 'scheduler', 'database'] as $kind) foreach (['absent', 'visible', 'replaced', 'unreadable'] as $state) $cases[] = [$kind, $state];
        return $cases;
    }

    #[DataProvider('deletionStates')]
    public function testDeletionRequiresFreshAbsence(string $kind, string $state): void
    {
        $name = ['worker' => 'worker-default', 'scheduler' => 'scheduler', 'database' => 'db-fixture'][$kind];
        $type = ['worker' => 'app', 'scheduler' => 'box', 'database' => 'mysql'][$kind];
        $service = ['projectName' => 'fixture', 'name' => $name, 'type' => $type];
        $inventory = ['projects' => [['name' => 'fixture']], 'services' => [$service]];
        $deletes = 0;
        $client = new EasyPanelClient('https://example.test', 'synthetic', transport: function ($procedure) use ($inventory, $state, &$deletes) {
            if (str_ends_with($procedure, '.inspectService')) return ['env' => "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT=fixture\nSHIPPERCLI_MANAGED_WORKER=worker-default\nSHIPPERCLI_MANAGED_CRON=scheduler\nSHIPPERCLI_DATABASE_CREATED=".substr(hash('sha256', json_encode(['fixture', 'db-fixture'], JSON_THROW_ON_ERROR)), 0, 24)];
            if (str_ends_with($procedure, '.destroyService')) { $deletes++; return null; }
            if ($state === 'unreadable') return null;
            if ($state === 'absent') return ['projects' => [['name' => 'fixture']], 'services' => []];
            if ($state === 'replaced') $inventory['services'][0]['type'] = 'redis';
            return $inventory;
        });
        $provider = new EasyPanelProvider([], $client);
        $operation = fn () => match ($kind) {
            'worker' => (new \ReflectionMethod($provider, 'reconcileWorkers'))->invoke($provider, $client, $inventory, 'fixture', []),
            'scheduler' => (new \ReflectionMethod($provider, 'destroyOwnedCronService'))->invoke($provider, $client, 'fixture', $service),
            'database' => (new \ReflectionMethod($provider, 'reconcileDatabases'))->invoke($provider, $client, new \stdClass, 'fixture', $inventory),
        };
        $failure = null;
        try { $operation(); } catch (\RuntimeException $exception) { $failure = $exception; }
        if ($state === 'absent') self::assertNull($failure);
        else self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame(1, $deletes);
    }
}
