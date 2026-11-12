<?php

declare(strict_types=1);

use ShipperCli\ProviderEasyPanel\EasyPanelClient;

require dirname(__DIR__).'/vendor/autoload.php';

$url = getenv('EASYPANEL_URL');
$token = getenv('EASYPANEL_AUTH_TOKEN');

if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
    throw new RuntimeException('EASYPANEL_URL and EASYPANEL_AUTH_TOKEN are required.');
}

$client = new EasyPanelClient($url, $token);
$project = 'shippercli-create-probe-'.bin2hex(random_bytes(8));
$service = 'probe';
$marker = 'SHIPPERCLI_MANAGED_PROJECT='.$project;

if (in_array($project, array_column($client->listProjectsAndServices()['projects'], 'name'), true)) {
    throw new RuntimeException('Disposable project name is already in use.');
}

$client->createProject($project);
try {
    $client->call('services.app.createService', [
        'projectName' => $project,
        'serviceName' => $service,
        'env' => $marker,
    ]);
    $details = $client->inspectAppService($project, $service);
    $env = is_string($details['env'] ?? null) ? $details['env'] : '';
    if (! in_array($marker, preg_split('/\R/', $env) ?: [], true)) {
        throw new RuntimeException('The app create route did not persist the environment marker.');
    }

    echo "The app create route persisted the environment marker.\n";
} finally {
    foreach ($client->listProjectsAndServices()['services'] as $entry) {
        if ($entry['projectName'] === $project && ($entry['name'] !== $service || $entry['type'] !== 'app')) {
            throw new RuntimeException('Disposable project contains an unexpected service. Manual cleanup required.');
        }
    }
    $client->destroyProject($project);
    if (in_array($project, array_column($client->listProjectsAndServices()['projects'], 'name'), true)) {
        throw new RuntimeException('Disposable project still exists after cleanup.');
    }
    echo "Disposable project cleanup verified.\n";
}
