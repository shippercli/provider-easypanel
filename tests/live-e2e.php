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

$disposable = $argv === [$argv[0], '--disposable'];
if (! $disposable && count($argv) !== 1) {
    throw new InvalidArgumentException('Unknown live test arguments.');
}
$name = $disposable ? 'e'.bin2hex(random_bytes(6)) : 'provider';
$prefix = $disposable ? 's-' : 'shippercli-demo-';
$expectedProject = $prefix.$name.'-v1-'.substr(hash('sha256', json_encode([$name, 'v1'], JSON_THROW_ON_ERROR)), 0, 20);
$expectedService = 'web';
$requestedDomain = null;
if ($disposable) {
    $handle = curl_init(rtrim($url, '/').'/api/getServiceDomain');
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the service domain settings request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS_STR => 'HTTPS',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token],
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    if ($status !== 200 || ! is_string($response)) {
        throw new RuntimeException('Unable to read the EasyPanel service domain settings.');
    }

    $settings = json_decode($response, true);
    $baseDomain = $settings['defaultServiceDomain'] ?? null;
    if (! is_string($baseDomain) || ! str_contains($baseDomain, '.')) {
        throw new RuntimeException('EasyPanel did not provide a default service base domain.');
    }
    $requestedDomain = $expectedService.'-'.$expectedProject.'.'.$baseDomain;
    if (! filter_var($requestedDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        throw new RuntimeException('The disposable HTTPS hostname is invalid.');
    }
}
$client = new EasyPanelClient($url, $token, timeout: 45);
$provider = new EasyPanelProvider([
    'url' => $url,
    'auth_token' => $token,
    'managed_prefix' => $prefix,
    'service_name' => $expectedService,
    'source' => [
        'type' => 'image',
        'image' => 'nginx:alpine',
    ],
], $client);

$project = new class($name) {
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function repository(): array
    {
        return [];
    }
};

$profile = new class($requestedDomain) {
    public function __construct(private readonly ?string $domain) {}

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
        return $key === 'domain' ? $this->domain : null;
    }
};

$projectExists = static function () use ($client, $expectedProject): bool {
    return in_array($expectedProject, array_column($client->listProjectsAndServices()['projects'], 'name'), true);
};
if ($disposable && $projectExists()) {
    throw new RuntimeException('Disposable project name is already in use.');
}
if ($disposable) {
    echo "Disposable lifecycle project: {$expectedProject}\n";
}

$probeRuntimeInventory = static function (string $phase) use ($url, $token, $expectedProject, $expectedService): void {
    $query = http_build_query(['service' => $expectedProject.'_'.$expectedService]);
    $handle = curl_init(rtrim($url, '/').'/api/getDockerContainers?'.$query);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the runtime inventory request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_PROTOCOLS_STR => 'HTTPS',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token],
    ]);
    $response = curl_exec($handle);
    $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    $containers = $httpStatus === 200 && is_string($response) ? json_decode($response) : null;
    $identity = $expectedProject.'_'.$expectedService;
    $serviceLabels = 0;
    $matchingLabels = 0;
    $matchingNames = 0;
    $runningStates = 0;
    foreach (is_array($containers) ? $containers : [] as $container) {
        $serviceLabel = $container->Labels->{'com.docker.swarm.service.name'} ?? null;
        $serviceLabels += is_string($serviceLabel) ? 1 : 0;
        $matchingLabels += $serviceLabel === $identity ? 1 : 0;
        $runningStates += ($container->State ?? null) === 'running' ? 1 : 0;
        $names = $container->Names ?? [];
        foreach (is_array($names) ? $names : [] as $name) {
            if (is_string($name) && str_starts_with(ltrim($name, '/'), $identity.'.')) {
                $matchingNames++;
                break;
            }
        }
    }
    printf(
        "Disposable runtime inventory %s: HTTP %d, shape=%s, list_count=%d, service_labels=%d, matching_labels=%d, matching_names=%d, running_states=%d\n",
        $phase,
        $httpStatus,
        gettype($containers),
        is_array($containers) ? count($containers) : 0,
        $serviceLabels,
        $matchingLabels,
        $matchingNames,
        $runningStates,
    );
};

try {
    if ($disposable) {
        $client->createProject($expectedProject);
        $client->createAppService(
            $expectedProject,
            $expectedService,
            "SHIPPERCLI_MANAGED=1\nSHIPPERCLI_MANAGED_PROJECT={$expectedProject}",
        );
        $probeRuntimeInventory('before deployment');
    }

    if (! $provider->apply($project, $profile)) {
        if ($disposable && $requestedDomain !== null) {
            $bindingSaved = false;
            foreach ($client->listDomains($expectedProject, $expectedService) as $domain) {
                if (
                    strcasecmp($domain['host'], $requestedDomain) === 0
                    && ($domain['https'] ?? null) === true
                    && ($domain['destinationType'] ?? null) === 'service'
                    && ($domain['serviceDestination']['projectName'] ?? null) === $expectedProject
                    && ($domain['serviceDestination']['serviceName'] ?? null) === $expectedService
                    && ($domain['serviceDestination']['port'] ?? null) === 80
                ) {
                    $bindingSaved = true;
                    break;
                }
            }
            echo $bindingSaved ? "Exact HTTPS binding persisted.\n" : "Exact HTTPS binding absent.\n";
        }
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
    $domains = $client->listDomains($expectedProject, $expectedService);
    if ($disposable) {
        fwrite(STDERR, 'Disposable domain inventory count: '.count($domains).PHP_EOL);
    }

    foreach ($domains as $domain) {
        if ($disposable) {
            $destination = $domain['serviceDestination'] ?? null;
            fwrite(STDERR, 'Disposable domain binding: '.json_encode([
                'https_type' => gettype($domain['https'] ?? null),
                'https_enabled' => ($domain['https'] ?? false) === true,
                'service_destination' => ($domain['destinationType'] ?? null) === 'service',
                'destination_type' => gettype($destination),
                'project_matches' => ($destination['projectName'] ?? null) === $expectedProject,
                'service_matches' => ($destination['serviceName'] ?? null) === $expectedService,
            ], JSON_THROW_ON_ERROR).PHP_EOL);
        }

        if (
            ($domain['https'] ?? false) === true
            && ($domain['destinationType'] ?? null) === 'service'
            && ($domain['serviceDestination']['projectName'] ?? null) === $expectedProject
            && ($domain['serviceDestination']['serviceName'] ?? null) === $expectedService
            && is_string($domain['host'] ?? null)
            && ($requestedDomain === null || strcasecmp($domain['host'], $requestedDomain) === 0)
        ) {
            $domainHost = $domain['host'];
            break;
        }
    }

    if ($domainHost === null) {
        throw new RuntimeException('The Shipper test service has no matching HTTPS binding.');
    }

    $waitForNginx = static function () use ($domainHost): void {
        $ready = false;
        for ($attempt = 1; $attempt <= 18; $attempt++) {
            $handle = curl_init('https://'.$domainHost.'/');
            if ($handle === false) {
                throw new RuntimeException('Unable to initialize the demo readiness request.');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_PROTOCOLS_STR => 'HTTPS',
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            unset($handle);

            if ($status === 200 && is_string($body) && str_contains($body, '<title>Welcome to nginx!</title>')) {
                $ready = true;
                break;
            }

            sleep(5);
        }

        if (! $ready) {
            throw new RuntimeException('The Shipper demo service did not become ready.');
        }
    };
    $waitForNginx();

    echo "EasyPanel demo deployment is ready.\n";

    if ($disposable) {
        $status = $provider->status($project, $profile);
        if ($status['project'] !== $expectedProject || $status['service'] !== $expectedService || $status['state'] === 'not_found') {
            throw new RuntimeException('Provider status did not identify the deployed app.');
        }
        echo "Provider status: {$status['state']}\n";

        $probeRuntimeInventory('after HTTPS readiness');

        if (! $provider->apply($project, $profile)) {
            throw new RuntimeException('Redeploy failed: '.$provider->getLastError());
        }
        $waitForNginx();
        echo "Redeploy accepted and endpoint still serves nginx.\n";

        $logs = $provider->logs($project, $profile, 20);
        if ($logs === []) {
            throw new RuntimeException('Provider logs did not return any deployment or application entries.');
        }
        echo "Provider logs returned entries.\n";
    }
} finally {
    if ($disposable) {
        if (! $provider->destroy($project, $profile) || $projectExists()) {
            throw new RuntimeException('Disposable lifecycle cleanup failed: '.$provider->getLastError());
        }
        echo "Disposable lifecycle project cleanup verified.\n";
    }
}
