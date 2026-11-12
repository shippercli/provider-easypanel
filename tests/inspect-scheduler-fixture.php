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
foreach ($inventory['services'] as $service) {
    if (($service['projectName'] ?? null) !== $project) {
        continue;
    }
    $name = match ($service['name'] ?? null) {
        'web' => 'web',
        'scheduler' => 'scheduler',
        default => 'other',
    };
    $type = match ($service['type'] ?? null) {
        'app' => 'app',
        'box' => 'box',
        default => 'other',
    };
    echo "Fixture service: {$name}/{$type}\n";
    if ($name !== 'scheduler' || $type !== 'box') {
        continue;
    }
    try {
        $box = $client->inspectBoxService($project, 'scheduler');
        $environment = $box['env'] ?? null;
        echo 'Box env shape: '.get_debug_type($environment).PHP_EOL;
        $content = is_array($environment) ? ($environment['content'] ?? null) : $environment;
        $marker = 'SHIPPERCLI_MANAGED_PROJECT='.$project;
        echo 'Box project marker: '.(is_string($content) && in_array($marker, preg_split('/\R/', $content), true) ? 'present' : 'absent').PHP_EOL;
    } catch (RuntimeException $exception) {
        echo 'Box inspection failed. HTTP status code if available: '.$exception->getCode().PHP_EOL;
    }
}
