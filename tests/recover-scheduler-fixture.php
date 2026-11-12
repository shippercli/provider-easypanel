<?php

declare(strict_types=1);

use ShipperCli\ProviderEasyPanel\EasyPanelClient;

require dirname(__DIR__).'/vendor/autoload.php';

// This project was verified absent before its random name was first used in run 43246645234.
$project = 's-e34c5a22a96b7-v1-a3a605687f9ccdb6c64b';
$client = new EasyPanelClient(getenv('EASYPANEL_URL'), getenv('EASYPANEL_AUTH_TOKEN'), timeout: 45);
$exists = static fn (array $inventory): bool => in_array($project, array_column($inventory['projects'], 'name'), true);

$inventory = $client->listProjectsAndServices();
if (! $exists($inventory)) {
    echo "The previous fixture project is absent.\n";
    exit(0);
}

$services = [];
foreach ($inventory['services'] as $service) {
    if (($service['projectName'] ?? null) === $project) {
        $services[] = [($service['name'] ?? null), ($service['type'] ?? null)];
    }
}
sort($services);
if ($services !== [['scheduler', 'box'], ['web', 'app']]) {
    throw new RuntimeException('Recovery stopped because the fixture inventory differed from the failed run.');
}

$app = $client->inspectAppService($project, 'web');
$environment = is_string($app['env'] ?? null) ? preg_split('/\R/', $app['env']) : [];
if (! in_array('SHIPPERCLI_MANAGED=1', $environment, true)
    || ! in_array('SHIPPERCLI_MANAGED_PROJECT='.$project, $environment, true)) {
    throw new RuntimeException('Recovery stopped because fixture ownership could not be confirmed.');
}

try {
    $client->cloneBoxRepository($project, 'scheduler', 'https://github.com/shippercli/provider-easypanel.git', 'main');
    echo "The Box Git clone returned the documented acknowledgement.\n";
} catch (RuntimeException $exception) {
    echo $exception->getMessage().PHP_EOL;
}

try {
    $client->destroyProject($project);
} catch (RuntimeException $exception) {
    if ($exists($client->listProjectsAndServices())) {
        throw $exception;
    }
}
if ($exists($client->listProjectsAndServices())) {
    throw new RuntimeException('The previous fixture project is still present after recovery.');
}
echo "The previous fixture project was removed.\n";
