<?php

declare(strict_types=1);

use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

require dirname(__DIR__).'/vendor/autoload.php';

$url = getenv('EASYPANEL_URL');
$token = getenv('EASYPANEL_AUTH_TOKEN');

if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
    throw new RuntimeException('EASYPANEL_URL and EASYPANEL_AUTH_TOKEN are required.');
}

$expectedProject = 'shippercli-demo-provider-v1';
$expectedService = 'web';
$client = new EasyPanelClient($url, $token, timeout: 45);
$provider = new EasyPanelProvider([
    'url' => $url,
    'auth_token' => $token,
    'managed_prefix' => 'shippercli-demo-',
    'service_name' => $expectedService,
    'source' => [
        'type' => 'image',
        'image' => 'nginx:alpine',
    ],
], $client);

$project = new class {
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

$profile = new class {
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
        return null;
    }
};

if (! $provider->apply($project, $profile)) {
    throw new RuntimeException($provider->getLastError());
}

$inventory = $client->listProjectsAndServices();
$serviceFound = false;
foreach ($inventory['services'] as $service) {
    if (
        ($service['projectName'] ?? null) === $expectedProject
        && ($service['name'] ?? null) === $expectedService
        && ($service['type'] ?? null) === 'app'
    ) {
        $serviceFound = true;
        break;
    }
}

if (! $serviceFound) {
    throw new RuntimeException('The exact Shipper demo service was not found after apply.');
}

$inspected = $client->inspectAppService($expectedProject, $expectedService);
$env = is_string($inspected['env'] ?? null) ? $inspected['env'] : '';
if (
    ! in_array('SHIPPERCLI_MANAGED=1', preg_split('/\R/', $env) ?: [], true)
    || ! in_array('SHIPPERCLI_MANAGED_PROJECT='.$expectedProject, preg_split('/\R/', $env) ?: [], true)
) {
    throw new RuntimeException('The Shipper ownership markers were not applied to the demo service.');
}

$domainHost = null;
foreach ($client->listDomains($expectedProject, $expectedService) as $domain) {
    if (
        ($domain['https'] ?? false) === true
        && ($domain['destinationType'] ?? null) === 'service'
        && ($domain['serviceDestination']['projectName'] ?? null) === $expectedProject
        && ($domain['serviceDestination']['serviceName'] ?? null) === $expectedService
        && is_string($domain['host'] ?? null)
    ) {
        $domainHost = $domain['host'];
        break;
    }
}

if ($domainHost === null) {
    throw new RuntimeException('The Shipper demo service has no generated HTTPS domain.');
}

$ready = false;
for ($attempt = 1; $attempt <= 18; $attempt++) {
    $handle = curl_init('https://'.$domainHost.'/');
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the demo readiness request.');
    }

    curl_setopt_array($handle, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);

    if ($status >= 200 && $status < 400) {
        $ready = true;
        break;
    }

    sleep(5);
}

if (! $ready) {
    throw new RuntimeException('The Shipper demo service did not become ready.');
}

echo "EasyPanel demo deployment is ready.\n";
