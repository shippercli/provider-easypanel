<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderEasyPanel\EasyPanelClient;

$client = new EasyPanelClient($argv[1], 'synthetic-authorization-secret');
foreach ([301, 302, 303, 307, 308, 400, 401, 403, 404, 422, 429, 500, 502] as $status) {
    foreach (['json', 'text'] as $format) {
        $failure = null;
        try {
            $client->call("status-{$status}-{$format}", ['password' => 'synthetic-body-secret']);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
        if ($failure === null) {
            throw new RuntimeException("Non-success HTTP status was not rejected clearly: {$status}/{$format}");
        }
        foreach (['synthetic-authorization-secret', 'synthetic-body-secret', 'synthetic-error-secret'] as $secret) {
            if (str_contains($failure->getMessage(), $secret)) {
                throw new RuntimeException('Error response leaked synthetic credentials');
            }
        }
        if (!str_contains($failure->getMessage(), "HTTP {$status}")) {
            throw new RuntimeException("HTTP status missing from diagnostics: {$status}/{$format}");
        }
    }
}
foreach (['direct', 'nested'] as $format) {
    if ($client->call('success-'.$format) !== ['accepted' => true]) {
        throw new RuntimeException('Successful response decoding changed');
    }
}
$failure = null;
try {
    (new EasyPanelClient('http://fixture:synthetic-url-secret@127.0.0.1:not-a-port', 'synthetic-token'))->call('fixture');
} catch (RuntimeException $exception) {
    $failure = $exception;
}
if ($failure === null || !str_contains($failure->getMessage(), 'transport error (cURL ')
    || str_contains($failure->getMessage(), 'synthetic-url-secret')) {
    throw new RuntimeException('Transport diagnostics were not safe and actionable');
}
echo "26 non-success status checks and two successful response controls passed\n";

foreach (['direct', 'nested'] as $format) {
    foreach (['duplicate-project', 'padded-project', 'duplicate-service', 'conflicting-type', 'orphan', 'padded-service', 'padded-parent', 'padded-type', 'valid'] as $case) {
        $api = new EasyPanelClient($argv[1].'/inventory/'.$case.'/'.$format, 'synthetic-authorization-secret');
        $failure = null;
        try { $inventory = $api->listProjectsAndServices(); } catch (RuntimeException $exception) { $failure = $exception; }
        if ($case === 'valid') {
            if ($failure !== null || count($inventory['projects']) !== 2 || count($inventory['services']) !== 2) {
                throw new RuntimeException('Valid scoped inventory rejected');
            }
        } elseif ($failure === null) throw new RuntimeException('Ambiguous EasyPanel inventory accepted: '.$case);
    }
}
echo "16 real HTTP inventory rejections and two scoped-name controls passed\n";

foreach (['missing', 'array', 'null', 'number', 'string', 'error', 'direct-error', 'errors', 'false', 'data-string', 'nested-error', 'ambiguous', 'syntax', 'valid-direct', 'valid-nested'] as $case) {
    $api = new EasyPanelClient($argv[1].'/envelope/'.$case, 'synthetic-authorization-secret');
    $failure = null;
    try { $api->createProject('fixture'); } catch (Throwable $exception) { $failure = $exception; }
    if (str_starts_with($case, 'valid-')) {
        if ($failure !== null) throw new RuntimeException('Valid explicit null mutation rejected');
    } elseif (!$failure instanceof RuntimeException || $failure->getPrevious() !== null
        || str_contains($failure->getMessage(), 'synthetic-authorization-secret')) {
        throw new RuntimeException('Invalid mutation envelope accepted or exposed: '.$case);
    }
}
foreach (['direct', 'nested'] as $format) {
    foreach (['projects-object', 'services-object', 'numeric-object', 'array-record'] as $case) {
        $api = new EasyPanelClient($argv[1].'/raw-inventory/'.$case.'/'.$format, 'synthetic-authorization-secret');
        $failure = null;
        try { $api->listProjectsAndServices(); } catch (RuntimeException $exception) { $failure = $exception; }
        if ($failure === null) throw new RuntimeException('Raw inventory shape lost: '.$case);
    }
}
echo "13 invalid mutation envelopes, two explicit null controls and eight raw inventory shape rejections passed\n";

foreach (['direct', 'nested'] as $format) {
    foreach (['app', 'box', 'mysql', 'domains'] as $kind) {
        $cases = $kind === 'domains' ? ['null', 'object', 'string', 'array-record', 'missing-host', 'padded-host', 'empty', 'valid'] : ['null', 'list', 'string', 'valid'];
        foreach ($cases as $case) {
            $api = new EasyPanelClient($argv[1].'/read/'.$kind.'/'.$case.'/'.$format, 'synthetic-authorization-secret');
            $failure = null;
            try {
                $result = match ($kind) {
                    'app' => $api->inspectAppService('fixture', 'app'),
                    'box' => $api->inspectBoxService('fixture', 'worker'),
                    'mysql' => $api->inspectDatabaseService('mysql', 'fixture', 'db'),
                    'domains' => $api->listDomains('fixture', 'app'),
                };
            } catch (Throwable $exception) { $failure = $exception; }
            if (in_array($case, ['valid', 'empty'], true)) {
                if ($failure !== null || ($case === 'empty' && $result !== [])) throw new RuntimeException('Valid read changed');
            } elseif (!$failure instanceof RuntimeException || str_contains($failure->getMessage(), 'synthetic-secret')) {
                throw new RuntimeException('Malformed read normalized as valid: '.$kind.'/'.$case);
            }
        }
    }
}
echo "40 real HTTP service/domain read shape checks passed\n";

foreach (['direct', 'nested'] as $format) {
    foreach (['app', 'box', 'mysql'] as $kind) {
        foreach (['parent', 'null-parent', 'name', 'service-name', 'type', 'array-name', 'valid'] as $case) {
            $api = new EasyPanelClient($argv[1].'/inspection-identity/'.$kind.'/'.$case.'/'.$format, 'synthetic-authorization-secret');
            $failure = null;
            try {
                $result = match ($kind) {
                    'app' => $api->inspectAppService('fixture', 'target'),
                    'box' => $api->inspectBoxService('fixture', 'target'),
                    default => $api->inspectDatabaseService($kind, 'fixture', 'target'),
                };
            } catch (Throwable $exception) { $failure = $exception; }
            if ($case === 'valid') {
                if ($failure !== null || ($result['name'] ?? null) !== 'target') throw new RuntimeException('Matched inspection changed');
            } elseif (!$failure instanceof RuntimeException || $failure->getMessage() !== 'EasyPanel returned a mismatched service inspection identity.') {
                throw new RuntimeException('Foreign inspection identity accepted: '.$case.'/'.$kind);
            }
        }
    }
}
echo "36 real HTTP inspection identity rejections and six matched controls passed\n";

$targetClient = new EasyPanelClient($argv[1].'/database-target', 'synthetic-authorization-secret');
foreach (['projectName', 'serviceName'] as $field) {
    foreach (['other', null, false, ['synthetic-secret']] as $value) {
        $failure = null;
        try { $targetClient->createDatabaseService('mysql', 'fixture', 'db', [$field => $value]); }
        catch (InvalidArgumentException $exception) { $failure = $exception; }
        if ($failure === null) throw new RuntimeException('Database target override accepted');
    }
}
foreach (['mysql', 'mariadb', 'postgres', 'mongo', 'redis', 'postgresql'] as $type) {
    $targetClient->createDatabaseService($type, 'fixture', 'db', ['projectName' => 'fixture', 'serviceName' => 'db',
        'databaseName' => 'application', 'password' => 'synthetic-database-password']);
}
echo "Database target overrides blocked before HTTP; six valid engine/alias payloads sent unchanged\n";

$cleanupProject = new class { public function name(): string { return 'fixture'; } };
$cleanupProfile = new class { public function name(): string { return 'production'; } };
foreach (['absent', 'retain-project', 'other-service', 'pending-app', 'pending-project', 'unreadable'] as $case) {
    $config = ['url' => $argv[1], 'auth_token' => 'synthetic-authorization-secret', 'service_name' => 'web',
        'destroy_project' => !in_array($case, ['retain-project', 'pending-app'], true)];
    $temporary = new \ShipperCli\ProviderEasyPanel\EasyPanelProvider($config);
    $projectName = (new ReflectionMethod($temporary, 'managedProjectName'))->invoke($temporary, $cleanupProject, $cleanupProfile);
    $api = new EasyPanelClient($argv[1].'/cleanup/'.$case.'/'.$projectName, 'synthetic-authorization-secret');
    $provider = new \ShipperCli\ProviderEasyPanel\EasyPanelProvider($config, $api);
    $expected = in_array($case, ['absent', 'retain-project', 'other-service'], true);
    if ($provider->destroy($cleanupProject, $cleanupProfile) !== $expected) throw new RuntimeException('Invalid public cleanup verification: '.$case);
}
echo "Six public-provider cleanup HTTP flows passed\n";

foreach (['worker', 'scheduler', 'database'] as $kind) {
    foreach (['absent', 'visible', 'replaced', 'unreadable', 'malformed'] as $state) {
        $name = ['worker' => 'worker-default', 'scheduler' => 'scheduler', 'database' => 'db-fixture'][$kind];
        $type = ['worker' => 'app', 'scheduler' => 'box', 'database' => 'mysql'][$kind];
        $service = ['projectName' => 'fixture', 'name' => $name, 'type' => $type];
        $inventory = ['projects' => [['name' => 'fixture']], 'services' => [$service]];
        $api = new EasyPanelClient($argv[1].'/workload-delete/'.$kind.'/'.$state, 'synthetic-authorization-secret');
        $provider = new \ShipperCli\ProviderEasyPanel\EasyPanelProvider([], $api);
        $failure = null;
        try {
            match ($kind) {
                'worker' => (new ReflectionMethod($provider, 'reconcileWorkers'))->invoke($provider, $api, $inventory, 'fixture', []),
                'scheduler' => (new ReflectionMethod($provider, 'destroyOwnedCronService'))->invoke($provider, $api, 'fixture', $service),
                'database' => (new ReflectionMethod($provider, 'reconcileDatabases'))->invoke($provider, $api, new stdClass, 'fixture', $inventory),
            };
        } catch (RuntimeException $exception) { $failure = $exception; }
        $expectedSuccess = $state === 'absent' || ($state === 'malformed' && $kind !== 'scheduler');
        if ($expectedSuccess !== ($failure === null)) throw new RuntimeException('Unconfirmed workload deletion accepted: '.$kind.'/'.$state);
    }
}
echo "15 real HTTP worker/scheduler/database deletion verification flows passed\n";


$passwordApi = new EasyPanelClient($argv[1].'/database-password', 'synthetic-authorization-secret');
$passwordProvider = new \ShipperCli\ProviderEasyPanel\EasyPanelProvider(['database_password' => 'shared-fallback'], $passwordApi);
$passwordProject = new class {
    public function databases(): array { return [new class {
        public function name(): string { return 'Main_DB'; }
        public function user(): string { return 'AppUser'; }
    }]; }
};
$literalPassword = 'literal-${PROJECT_NAME}-${PROFILE}-+%:@';
$connection = (new ReflectionMethod($passwordProvider, 'applyDatabases'))->invoke($passwordProvider, $passwordApi,
    $passwordProject, 'fixture', "DB_PASSWORD='".$literalPassword."'");
if ($connection['DB_PASSWORD'] !== $literalPassword || $connection['DB_DATABASE'] !== 'main_db' || $connection['DB_USERNAME'] !== 'appuser') {
    throw new RuntimeException('Explicit EasyPanel database connection changed');
}
echo "Literal explicit password and normalized identity passed real HTTP creation\n";


foreach (['running', 'missing', 'invalid', 'url'] as $case) {
    $project = new class { public function name(): string { return 'fixture'; } };
    $profile = new class { public function name(): string { return 'production'; } };
    $temporary = new \ShipperCli\ProviderEasyPanel\EasyPanelProvider;
    $name = (new ReflectionMethod($temporary, 'managedProjectName'))->invoke($temporary, $project, $profile);
    $api = new EasyPanelClient($argv[1].'/status-safety/'.$case.'/'.$name, 'synthetic-authorization-secret');
    $provider = new \ShipperCli\ProviderEasyPanel\EasyPanelProvider([], $api);
    $status = $provider->status($project, $profile);
    if ($status['state'] !== ($case === 'running' ? 'running' : 'unknown')
        || str_contains(json_encode($status), 'synthetic-status-secret')
        || array_keys($status) !== ['provider', 'state', 'project', 'service']) {
        throw new RuntimeException('Status leaked inspection data or invented a successful state');
    }
}
echo "Four real HTTP status privacy and unknown-state controls passed\n";
