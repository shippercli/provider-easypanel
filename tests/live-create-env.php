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
$recover = $argv === [$argv[0], '--recover-failed-probe'];
if (! $recover && count($argv) !== 1) {
    throw new InvalidArgumentException('Unknown probe arguments.');
}

$request = static function (string $route, ?array $payload = null) use ($url, $token): string {
    $handle = curl_init(rtrim($url, '/').'/api/'.$route);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROTOCOLS_STR => 'HTTPS',
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Content-Type: application/json'],
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if (! is_string($body) || $status < 200 || $status >= 300) {
        throw new RuntimeException('Public EasyPanel '.$route.' failed (HTTP '.$status.').');
    }
    return $body;
};

if ($recover) {
    // Exact disposable project logged by run 43218511043.
    $project = 's-ec68be70d5292-v1-b6442ef7990e44f816e0';
    $inventory = $client->listProjectsAndServices();
    if (! in_array($project, array_column($inventory['projects'], 'name'), true)) {
        echo "Disposable project is already absent.\n";
        exit(0);
    }

    foreach ($inventory['services'] as $entry) {
        if ($entry['projectName'] !== $project) {
            continue;
        }
        if ($entry['name'] !== 'web' || $entry['type'] !== 'app') {
            throw new RuntimeException('Unexpected service in disposable project. Manual review required.');
        }
        $inspection = $client->inspectAppService($project, 'web');
        $env = is_string($inspection['env'] ?? null) ? preg_split('/\R/', $inspection['env']) : [];
        if (! in_array('SHIPPERCLI_MANAGED=1', $env ?: [], true)
            || ! in_array('SHIPPERCLI_MANAGED_PROJECT='.$project, $env ?: [], true)) {
            throw new RuntimeException('Disposable app ownership could not be confirmed. Manual review required.');
        }
    }

    $request('destroyProject', ['name' => $project]);
    if (in_array($project, array_column($client->listProjectsAndServices()['projects'], 'name'), true)) {
        throw new RuntimeException('Disposable project still exists after recovery.');
    }
    echo "Disposable lifecycle project recovery verified.\n";
    exit(0);
}

$project = 'shippercli-create-probe-'.bin2hex(random_bytes(8));
$service = 'probe';
$marker = 'SHIPPERCLI_MANAGED_PROJECT='.$project;

if (in_array($project, array_column($client->listProjectsAndServices()['projects'], 'name'), true)) {
    throw new RuntimeException('Disposable project name is already in use.');
}

echo "Disposable EasyPanel project: {$project}\n";
try {
    $client->createProject($project);
} catch (RuntimeException $error) {
    $exists = in_array($project, array_column($client->listProjectsAndServices()['projects'], 'name'), true);
    if ($exists) {
        throw new RuntimeException('Project creation could not be confirmed. Inspect the disposable project for manual cleanup.', previous: $error);
    }

    throw $error;
}

try {
    $client->createAppService($project, $service, $marker);
    $details = $client->inspectAppService($project, $service);
    if (! is_string($details['env'] ?? null)) {
        throw new RuntimeException('The app inspection did not return a valid environment.');
    }
    $persisted = in_array($marker, preg_split('/\R/', $details['env']) ?: [], true);
    if ($persisted) {
        try {
            $client->updateImageSource($project, $service, 'nginx:alpine');
        } catch (RuntimeException) {
            echo "Image update response was rejected by the client.\n";
        }
        try {
            $client->destroyAppService($project, $service);
        } catch (RuntimeException) {
            echo "App destroy response was rejected by the client.\n";
        }
        $remaining = array_filter(
            $client->listProjectsAndServices()['services'],
            static fn (array $entry): bool => $entry['projectName'] === $project && $entry['name'] === $service,
        );
        echo $remaining === [] ? "App absent after destroy call.\n" : "App still present after destroy call.\n";
    }

} finally {
    $inventory = $client->listProjectsAndServices();
    foreach ($inventory['services'] as $entry) {
        if ($entry['projectName'] === $project && ($entry['name'] !== $service || $entry['type'] !== 'app')) {
            throw new RuntimeException('Disposable project contains an unexpected service. Manual cleanup required.');
        }
    }
    if (in_array($project, array_column($inventory['projects'], 'name'), true)) {
        $deletionError = null;
        try {
            $request('destroyProject', ['name' => $project]);
        } catch (RuntimeException $error) {
            $deletionError = $error;
        }
        if (in_array($project, array_column($client->listProjectsAndServices()['projects'], 'name'), true)) {
            throw $deletionError ?? new RuntimeException('Disposable project still exists after cleanup.');
        }
    }
    echo "Disposable project cleanup verified.\n";
}

if (! $persisted) {
    throw new RuntimeException('Environment marker was not persisted at creation.');
}
echo "Environment marker persisted.\n";
