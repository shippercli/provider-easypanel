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

$withDatabase = $argv === [$argv[0], '--disposable-database'];
$recoverDatabase = $argv === [$argv[0], '--recover-disposable-database'];
$disposable = $withDatabase || $argv === [$argv[0], '--disposable'];
if (! $disposable && ! $recoverDatabase && count($argv) !== 1) {
    throw new InvalidArgumentException('Unknown live test arguments.');
}
$name = match (true) {
    $recoverDatabase => 'eba8db18205cf',
    $disposable => 'e'.bin2hex(random_bytes(6)),
    default => 'provider',
};
$prefix = $disposable || $recoverDatabase ? 's-' : 'shippercli-demo-';
$expectedProject = $prefix.$name.'-v1-'.substr(hash('sha256', json_encode([$name, 'v1'], JSON_THROW_ON_ERROR)), 0, 20);
$expectedService = 'web';
$client = new EasyPanelClient($url, $token, timeout: 45);
$expectedDomain = null;
if ($disposable) {
    $baseDomain = $client->serviceBaseDomain();
    if ($baseDomain === null) {
        throw new RuntimeException('EasyPanel did not provide a service base domain.');
    }
    $expectedDomain = $expectedService.'-'.$expectedProject.'.'.$baseDomain;
}
$provider = new EasyPanelProvider([
    'url' => $url,
    'auth_token' => $token,
    'managed_prefix' => $prefix,
    'service_name' => $expectedService,
    'source' => $withDatabase ? [
        'type' => 'dockerfile',
        'dockerfile' => file_get_contents(__DIR__.'/mysql-probe.Dockerfile'),
    ] : [
        'type' => 'image',
        'image' => 'nginx:alpine',
    ],
], $client);

$project = new class($name, $withDatabase) {
    public function __construct(private readonly string $name, private readonly bool $withDatabase) {}

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function repository(): array
    {
        return [];
    }

    public function databases(): array
    {
        return $this->withDatabase ? [new class {
            public function name(): string { return 'fixture'; }
            public function user(): string { return 'fixture'; }
            public function type(): string { return 'mysql'; }
        }] : [];
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

$projectExists = static function () use ($client, $expectedProject): bool {
    return in_array($expectedProject, array_column($client->listProjectsAndServices()['projects'], 'name'), true);
};
if ($recoverDatabase) {
    // Only recover the exact disposable fixture reported by run 43225723945.
    if ($expectedProject !== 's-eba8db18205cf-v1-f2c8c6abb18068eaa758') {
        throw new RuntimeException('Recovery project identity does not match the failed fixture.');
    }
    $inventory = $client->listProjectsAndServices();
    foreach ($inventory['services'] as $entry) {
        if ($entry['projectName'] === $expectedProject
            && ! in_array([$entry['name'], $entry['type']], [['web', 'app'], ['db-fixture', 'mysql']], true)) {
            throw new RuntimeException('Unexpected service in disposable project. Manual review required.');
        }
    }
    if (! in_array($expectedProject, array_column($inventory['projects'], 'name'), true)) {
        echo "Disposable MySQL project is already absent.\n";
        exit(0);
    }
    if (! $provider->destroy($project, $profile) || $projectExists()) {
        throw new RuntimeException('Disposable MySQL recovery failed: '.$provider->getLastError());
    }
    echo "Disposable MySQL project recovery verified.\n";
    exit(0);
}
if ($disposable && $projectExists()) {
    throw new RuntimeException('Disposable project name is already in use.');
}
if ($disposable) {
    echo "Disposable lifecycle project: {$expectedProject}\n";
}

try {
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
            && ($expectedDomain === null || strcasecmp($domain['host'], $expectedDomain) === 0)
        ) {
            $domainHost = $domain['host'];
            break;
        }
    }

    if ($domainHost === null) {
        throw new RuntimeException('The Shipper test service has no matching HTTPS binding.');
    }

    $waitForApp = static function () use ($domainHost, $withDatabase): void {
        $ready = false;
        $deadline = hrtime(true) + ($withDatabase ? 150 : 90) * 1_000_000_000;
        while (($remainingMs = intdiv($deadline - hrtime(true), 1_000_000)) > 0) {
            $handle = curl_init('https://'.$domainHost.'/');
            if ($handle === false) {
                throw new RuntimeException('Unable to initialize the demo readiness request.');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => min(15_000, $remainingMs),
                CURLOPT_PROTOCOLS_STR => 'HTTPS',
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            unset($handle);

            if ($status === 200 && is_string($body) && ($withDatabase
                ? $body === 'shippercli-mysql-query-ok'
                : str_contains($body, '<title>Welcome to nginx!</title>'))) {
                $ready = true;
                break;
            }

            $remainingUs = intdiv($deadline - hrtime(true), 1_000);
            if ($remainingUs > 0) {
                usleep(min(5_000_000, $remainingUs));
            }
        }

        if (! $ready) {
            throw new RuntimeException($withDatabase
                ? 'The disposable application did not confirm a successful MySQL query over HTTPS.'
                : 'The Shipper demo service did not become ready.');
        }
    };
    $waitForApp();

    echo "EasyPanel demo deployment is ready.\n";
    if ($withDatabase) {
        echo "The deployed application authenticated to MySQL and ran SELECT 1.\n";
    }

    if ($disposable) {
        $status = $provider->status($project, $profile);
        if ($status['project'] !== $expectedProject || $status['service'] !== $expectedService || $status['state'] !== 'running') {
            throw new RuntimeException('Provider status did not identify the deployed app.');
        }
        echo "Provider status: {$status['state']}\n";

        $connection = static function () use ($client, $expectedProject, $expectedService): array {
            $app = $client->inspectAppService($expectedProject, $expectedService);
            try {
                $values = \Dotenv\Dotenv::parse($app['env'] ?? '');
            } catch (\Throwable) {
                throw new RuntimeException('Unable to parse the disposable application environment.');
            }
            $keys = array_flip(['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);
            $connection = array_intersect_key($values, $keys);
            if (count($connection) !== count($keys) || in_array('', $connection, true)
                || ($connection['DB_HOST'] ?? null) !== 'db-fixture'
                || ($connection['DB_DATABASE'] ?? null) !== 'fixture'
                || ($connection['DB_USERNAME'] ?? null) !== 'fixture') {
                throw new RuntimeException('The application database environment did not match the disposable declaration.');
            }
            return $connection;
        };
        $originalConnection = $withDatabase ? $connection() : [];

        if (! $provider->apply($project, $profile)) {
            throw new RuntimeException('Redeploy failed: '.$provider->getLastError());
        }
        $waitForApp();
        $matchingDomains = array_filter(
            $client->listDomains($expectedProject, $expectedService),
            static fn (array $binding): bool => strcasecmp($binding['host'], $domainHost) === 0,
        );
        if (count($matchingDomains) !== 1) {
            throw new RuntimeException('Redeploy did not preserve exactly one automatic HTTPS binding.');
        }
        echo "Redeploy accepted and the HTTPS endpoint remains ready.\n";
        if ($withDatabase) {
            if ($originalConnection !== $connection() || ! $client->hasRunningAppContainer($expectedProject, 'db-fixture')) {
                throw new RuntimeException('The database runtime or retained application credentials could not be verified.');
            }
            echo "Disposable MySQL runtime and preserved application credentials verified.\n";
        }

        $logs = $provider->logs($project, $profile, 20);
        if ($logs === []) {
            throw new RuntimeException('Provider logs did not return any deployment or application entries.');
        }
        echo "Provider logs returned entries.\n";
    }
} finally {
    if ($disposable) {
        // Destroy reconciles database declarations, so omit the disposable database from desired state.
        $cleanupProject = new class($name) {
            public function __construct(private readonly string $name) {}
            public function name(): string { return $this->name; }
        };
        if (! $provider->destroy($cleanupProject, $profile) || $projectExists()) {
            throw new RuntimeException('Disposable lifecycle cleanup failed: '.$provider->getLastError());
        }
        echo "Disposable lifecycle project cleanup verified.\n";
    }
}
