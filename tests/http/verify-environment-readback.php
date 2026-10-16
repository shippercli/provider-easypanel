<?php

declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

$project = new class { public function name(): string { return 'fixture'; } };
$profile = new class { public function name(): string { return 'production'; } public function get(string $key): mixed { return null; } };
$config = ['url' => 'https://fixture.test', 'auth_token' => 'synthetic-authorization-secret',
    'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
    'env' => ['SECRET' => 'synthetic-$literal', 'EMPTY' => '', 'ZERO' => 0]];
$name = (new ReflectionMethod(EasyPanelProvider::class, 'managedProjectName'))->invoke(new EasyPanelProvider($config), $project, $profile);
foreach (['saved', 'ignored', 'malformed', 'missing', 'wrong-type', 'lost-retained'] as $case) {
    $client = new EasyPanelClient($argv[1].'/'.$case.'/'.$name, 'synthetic-authorization-secret');
    $provider = new EasyPanelProvider($config, $client);
    $result = $provider->apply($project, $profile);
    if ($result !== ($case === 'saved')) throw new RuntimeException('Unexpected final environment result: '.$case);
    if (!$result && (!str_contains($provider->getLastError(), 'application environment could not be verified')
        || str_contains($provider->getLastError(), 'synthetic-$literal'))) {
        throw new RuntimeException('Environment failure diagnostic was not fixed and safe: '.$case);
    }
}
echo "Six public apply HTTP environment readback scenarios passed\n";
