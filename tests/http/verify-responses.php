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
