<?php

declare(strict_types=1);

use ShipperCli\ProviderEasyPanel\EasyPanelClient;

require dirname(__DIR__).'/vendor/autoload.php';

$project = 's-e4be322416c58-v1-7b251e19363551483299';
$client = new EasyPanelClient(getenv('EASYPANEL_URL'), getenv('EASYPANEL_AUTH_TOKEN'), timeout: 45);
$inventory = $client->listProjectsAndServices();
if (! in_array($project, array_column($inventory['projects'], 'name'), true)) {
    echo "The previous fixture is absent.\n";
    exit(0);
}
$services = array_values(array_filter($inventory['services'], static fn (array $service): bool =>
    ($service['projectName'] ?? null) === $project));
if (count($services) !== 1 || ($services[0]['name'] ?? null) !== 'web' || ($services[0]['type'] ?? null) !== 'app') {
    throw new RuntimeException('The failed fixture no longer matches the verified web-only inventory.');
}
$app = $client->inspectAppService($project, 'web');
$environment = is_string($app['env'] ?? null) ? preg_split('/\R/', $app['env']) : [];
if (! in_array('SHIPPERCLI_MANAGED=1', $environment, true)
    || ! in_array('SHIPPERCLI_MANAGED_PROJECT='.$project, $environment, true)) {
    throw new RuntimeException('Could not confirm ownership of the failed fixture.');
}
try {
    $client->destroyProject($project);
} catch (RuntimeException $exception) {
    $deletionError = $exception;
}
$remaining = $client->listProjectsAndServices();
if (in_array($project, array_column($remaining['projects'], 'name'), true)) {
    throw $deletionError ?? new RuntimeException('The failed fixture still exists.');
}
echo "Verified removal of the previous web-only fixture.\n";
