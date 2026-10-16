<?php

declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

$config = ['url' => 'https://fixture.test', 'auth_token' => 'synthetic-authorization-secret',
    'source' => ['type' => 'git', 'repo' => 'https://github.com/example/app.git'],
    'env' => ['SECRET' => 'synthetic-$literal']];
foreach (['worker', 'scheduler'] as $workload) {
    foreach (['saved', 'saved-flat', 'ignored', 'malformed', 'missing', 'wrong-type', 'lost-retained'] as $case) {
        $project = new class($workload) {
            public function __construct(private string $workload) {}
            public function name(): string { return 'fixture'; }
            public function queues(): array { return $this->workload === 'worker' ? ['default' => new class {}] : []; }
            public function cron(): array { return $this->workload === 'scheduler' ? ['daily' => new class {
                public function command(): string { return 'php artisan schedule:run'; }
                public function frequency(): string { return 'daily'; }
            }] : []; }
        };
        $profile = new class { public function name(): string { return 'production'; } public function branch(): string { return 'main'; } public function get(string $key): mixed { return null; } };
        $client = new EasyPanelClient($argv[1].'/'.$workload.'/'.$case, 'synthetic-authorization-secret');
        $provider = new EasyPanelProvider($config, $client);
        $result = $provider->apply($project, $profile);
        if ($result !== in_array($case, ['saved', 'saved-flat'], true)) throw new RuntimeException("Unexpected {$workload} apply result: {$case}");
        if (!$result && (!str_contains($provider->getLastError(), 'environment could not be verified')
            || str_contains($provider->getLastError(), 'synthetic-$literal'))) {
            throw new RuntimeException("Unsafe or unclear {$workload} diagnostic: {$case}");
        }
    }
}
echo "Fourteen public worker/scheduler HTTP readback scenarios passed\n";
