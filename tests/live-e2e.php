<?php

declare(strict_types=1);

use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use ShipperCli\ProviderEasyPanel\EasyPanelProvider;

const SCHEDULER_COMMAND = "echo 'ShipperCLI scheduled ''fixture executed'";

$shipper = getenv('SHIPPER_E2E_BIN');
$published = is_string($shipper) && $shipper !== '';
$worker = getenv('SHIPPER_E2E_WORKER') === 'true';
$scheduler = getenv('SHIPPER_E2E_SCHEDULER') === 'true';
$sourceType = getenv('SHIPPER_E2E_SOURCE') ?: 'image';
if ($scheduler && ($worker || $published || $sourceType !== 'git')) {
    throw new RuntimeException('The scheduler fixture requires an unpublished public Git deployment.');
}
$expectedBody = match ($worker ? 'worker' : $sourceType) {
    'worker' => 'ShipperCLI EasyPanel worker fixture web app',
    'git' => 'ShipperCLI EasyPanel public Git fixture',
    'image' => '<title>Welcome to nginx!</title>',
    default => throw new RuntimeException('Unsupported live fixture source.'),
};
require $published ? dirname($shipper, 2).'/autoload.php' : dirname(__DIR__).'/vendor/autoload.php';

$url = getenv('EASYPANEL_URL');
$token = getenv('EASYPANEL_AUTH_TOKEN');

if (! is_string($url) || $url === '' || ! is_string($token) || $token === '') {
    throw new RuntimeException('EASYPANEL_URL and EASYPANEL_AUTH_TOKEN are required.');
}

$name = $published ? getenv('SHIPPER_E2E_PROJECT') : 'e'.bin2hex(random_bytes(6));
if (! is_string($name) || ! preg_match('/\Ae[a-f0-9]{12}\z/', $name)) {
    throw new RuntimeException('Invalid disposable project identity.');
}
$prefix = 's-';
$expectedProject = $prefix.$name.'-v1-'.substr(hash('sha256', json_encode([$name, 'v1'], JSON_THROW_ON_ERROR)), 0, 20);
$expectedService = 'web';
$source = $sourceType === 'git' ? [
    'type' => 'git',
    'repo' => 'https://github.com/shippercli/provider-easypanel.git',
    'ref' => getenv('GITHUB_REF_NAME') ?: 'main',
    'path' => '/tests/fixtures/public-git',
] : [
    'type' => 'image',
    'image' => 'nginx:alpine',
];
if ($worker) {
    $source = [
        'type' => 'dockerfile',
        'dockerfile' => file_get_contents(__DIR__.'/fixtures/worker.Dockerfile'),
    ];
}
$client = new EasyPanelClient($url, $token, timeout: 45);
$provider = new EasyPanelProvider([
    'url' => $url,
    'auth_token' => $token,
    'managed_prefix' => $prefix,
    'service_name' => $expectedService,
    'source' => $source,
], $client);

$project = new class($name, $worker, $scheduler) {
    public function __construct(private readonly string $name, private readonly bool $worker, private readonly bool $scheduler) {}

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function repository(): array
    {
        return [];
    }

    public function queues(): array
    {
        return $this->worker ? ['default' => new class {
            public function sleep(): int { return 30; }
            public function maxTries(): int { return 1; }
            public function timeout(): int { return 60; }
        }] : [];
    }

    public function cron(): array
    {
        return $this->scheduler ? ['sample' => new class {
            public function frequency(): string { return '* * * * *'; }
            public function command(): string { return SCHEDULER_COMMAND; }
        }] : [];
    }

    public function hasSection(string $section): bool
    {
        return match ($section) {
            'queues' => $this->worker,
            'cron' => $this->scheduler,
            default => false,
        };
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

$runShipper = static function (array $arguments) use ($shipper): void {
    $process = proc_open([$shipper, ...$arguments], [STDIN, STDOUT, STDERR], $pipes);
    if (! is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException('Published Shipper command failed: '.$arguments[0]);
    }
};
if ($published) {
    foreach (['shippercli/cli' => '1.0.4', 'shippercli/provider-easypanel' => '2.0.2'] as $package => $version) {
        $installed = Composer\InstalledVersions::getPrettyVersion($package);
        if (! is_string($installed) || ltrim($installed, 'v') !== $version) {
            throw new RuntimeException('Unexpected published version for '.$package);
        }
        echo $package.' '.$installed.' '.Composer\InstalledVersions::getReference($package).PHP_EOL;
    }
    if (Composer\InstalledVersions::getReference('shippercli/provider-easypanel') !== 'd74d5411f2ba99b7dd1e6d990badc24353c03a3e') {
        throw new RuntimeException('Unexpected published EasyPanel source reference.');
    }
    $runShipper(['validate']);
    $runShipper(['plan', $name, '--profile=v1']);
}

$projectExists = static fn (): bool => in_array(
    $expectedProject,
    array_column($client->listProjectsAndServices()['projects'], 'name'),
    true,
);
if ($projectExists()) {
    throw new RuntimeException('Disposable project name is already in use.');
}
echo "Disposable lifecycle project: {$expectedProject}\n";
$applyFailed = false;

try {
    if ($published) {
        $runShipper(['apply', $name, '--profile=v1', '--force']);
    } elseif (! $provider->apply($project, $profile)) {
        $applyFailed = true;
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
        throw new RuntimeException('The exact Shipper disposable service was not found after apply.');
    }

    $inspected = $client->inspectAppService($expectedProject, $expectedService);
    $env = is_string($inspected['env'] ?? null) ? $inspected['env'] : '';
    if (
        ! in_array('SHIPPERCLI_MANAGED=1', preg_split('/\R/', $env) ?: [], true)
        || ! in_array('SHIPPERCLI_MANAGED_PROJECT='.$expectedProject, preg_split('/\R/', $env) ?: [], true)
    ) {
        throw new RuntimeException('The Shipper ownership markers were not applied to the disposable service.');
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
        throw new RuntimeException('The Shipper disposable service has no generated HTTPS domain.');
    }

    $ready = false;
    for ($attempt = 1; $attempt <= 18; $attempt++) {
        $handle = curl_init('https://'.$domainHost.'/');
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the disposable readiness request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS_STR => 'HTTPS',
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        if ($status === 200 && is_string($body) && str_contains($body, $expectedBody)) {
            $ready = true;
            break;
        }

        sleep(5);
    }

    if (! $ready) {
        throw new RuntimeException('The Shipper disposable service did not become ready.');
    }

    echo "EasyPanel disposable deployment is ready.\n";
    if ($worker) {
        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $logs = $client->queryServiceLogs($expectedProject, 'worker-default', ['limit' => 20]);
            if (str_contains(json_encode($logs, JSON_THROW_ON_ERROR), 'ShipperCLI EasyPanel worker command executed')) {
                echo "EasyPanel disposable queue worker command verified.\n";
                break;
            }
            if ($attempt === 12) {
                throw new RuntimeException('The disposable worker command did not emit its marker.');
            }
            sleep(5);
        }
    }
    if ($scheduler) {
        $box = $client->inspectBoxService($expectedProject, 'scheduler');
        $scripts = $box['scripts'] ?? null;
        if (! is_array($scripts)) {
            throw new RuntimeException('The disposable Box inspection did not include its scripts.');
        }
        $registered = false;
        foreach ($scripts as $script) {
            if (is_array($script) && ($script['name'] ?? null) === 'sample'
                && ($script['schedule'] ?? null) === '* * * * *'
                && ($script['enabled'] ?? false) === true
                && ($script['content'] ?? null) === SCHEDULER_COMMAND) {
                $registered = true;
                break;
            }
        }
        if (! $registered) {
            throw new RuntimeException('The disposable Box did not report the configured scheduled script.');
        }
        echo "EasyPanel disposable Box script registration verified.\n";
        for ($attempt = 1; $attempt <= 18; $attempt++) {
            $logs = $client->queryServiceLogs($expectedProject, 'scheduler', ['limit' => 30]);
            foreach ($logs as $record) {
                $message = $record['message'] ?? $record['line'] ?? $record['text'] ?? null;
                if (is_string($message) && str_contains($message, 'ShipperCLI scheduled fixture executed')) {
                    echo "EasyPanel disposable scheduled command execution verified.\n";
                    break 2;
                }
            }
            if ($attempt === 18) {
                throw new RuntimeException('The scheduled command did not emit its expected log output.');
            }
            sleep(5);
        }
    }
    if ($published) {
        $runShipper(['status', $name, '--profile=v1']);
        $runShipper(['logs', $name, '--profile=v1', '--lines=10']);
    }
} finally {
    if ($published) {
        $runShipper(['destroy', $name, '--profile=v1', '--force']);
    } elseif (! $provider->destroy($project, $profile)) {
        if (! $scheduler || ! $applyFailed) {
            throw new RuntimeException('Disposable lifecycle cleanup failed: '.$provider->getLastError());
        }

        // The random project was absent immediately before this fixture attempted to create it.
        $services = [];
        foreach ($client->listProjectsAndServices()['services'] as $service) {
            if (($service['projectName'] ?? null) === $expectedProject) {
                $services[] = [$service['name'] ?? null, $service['type'] ?? null];
            }
        }
        sort($services);
        if ($services !== [['scheduler', 'box'], [$expectedService, 'app']]) {
            throw new RuntimeException('Disposable scheduler cleanup found an unexpected service inventory.');
        }
        $web = $client->inspectAppService($expectedProject, $expectedService);
        $environment = is_string($web['env'] ?? null) ? preg_split('/\R/', $web['env']) : [];
        if (! in_array('SHIPPERCLI_MANAGED=1', $environment, true)
            || ! in_array('SHIPPERCLI_MANAGED_PROJECT='.$expectedProject, $environment, true)) {
            throw new RuntimeException('Disposable scheduler cleanup could not verify web service ownership.');
        }
        try {
            $client->destroyProject($expectedProject);
        } catch (RuntimeException $exception) {
            if ($projectExists()) {
                throw $exception;
            }
        }
    }
    if ($projectExists()) {
        throw new RuntimeException('The disposable project is still present after cleanup.');
    }
    echo "Disposable lifecycle project cleanup verified.\n";
}
