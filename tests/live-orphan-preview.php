<?php

declare(strict_types=1);

use ShipperCli\ProviderEasyPanel\EasyPanelClient;
use Symfony\Component\Yaml\Yaml;

$shipper = $argv[1] ?? '';
$url = getenv('EASYPANEL_URL');
$token = getenv('EASYPANEL_AUTH_TOKEN');
$repository = getenv('GITHUB_REPOSITORY');
if (! is_executable($shipper) || ! $url || ! $token || ! getenv('GITHUB_TOKEN') || ! $repository) {
    throw new RuntimeException('The published CLI, panel credentials and repository-scoped GitHub token are required.');
}

require dirname($shipper, 2).'/autoload.php';

$client = new EasyPanelClient($url, $token, timeout: 45);
$base = $client->serviceBaseDomain();
if ($base === null) {
    throw new RuntimeException('The disposable EasyPanel instance did not expose a service base domain.');
}

$run = static function (array $arguments) use ($shipper): string {
    $output = tempnam(sys_get_temp_dir(), 'shipper-preview-output-');
    if ($output === false) {
        throw new RuntimeException('Could not allocate CLI output file.');
    }
    try {
        $process = proc_open([$shipper, ...$arguments], [STDIN, ['file', $output, 'w'], STDERR], $pipes);
        $exit = is_resource($process) ? proc_close($process) : 1;
        $result = file_get_contents($output);
        if ($exit !== 0 || $result === false) {
            throw new RuntimeException('Preview fixture command failed: '.$arguments[0].' (exit '.$exit.').');
        }

        return $result;
    } finally {
        unlink($output);
    }
};

$nonce = bin2hex(random_bytes(6));
$name = 'e'.$nonce;
$control = $name.'control';
$template = 'pr-${GITHUB_PR_NUMBER}-'.$nonce.'.'.$base;
$orphanHost = str_replace('${GITHUB_PR_NUMBER}', '30', $template);
$controlHost = 'control-'.$nonce.'.'.$base;
$projectFor = static fn (string $project, string $host): string => 's-'.$project.'-preview-'.substr(
    hash('sha256', json_encode([$project, 'preview', $host], JSON_THROW_ON_ERROR)),
    0,
    20,
);
$orphan = $projectFor($name, $orphanHost);
$sentinel = $projectFor($control, $controlHost);
$exists = static fn (string $project): bool => in_array($project, array_column($client->listProjectsAndServices()['projects'], 'name'), true);
if ($exists($orphan) || $exists($sentinel)) {
    throw new RuntimeException('A randomized preview fixture identity already exists.');
}

$config = tempnam(sys_get_temp_dir(), 'shipper-preview-');
if ($config === false) {
    throw new RuntimeException('Could not allocate preview configuration file.');
}
$writeConfig = static function (string $project, string $domain) use ($config, $repository): void {
    $yaml = Yaml::dump([
        'providers' => ['easypanel' => [
            'url' => '${EASYPANEL_URL}',
            'auth_token' => '${EASYPANEL_AUTH_TOKEN}',
            'managed_prefix' => 's-',
            'service_name' => 'web',
            'source' => ['type' => 'image', 'image' => 'nginx:alpine'],
        ]],
        'projects' => [$project => [
            'provider' => 'easypanel',
            'repository' => ['provider' => 'github', 'name' => $repository],
            'profiles' => ['preview' => ['domain' => $domain, 'branch' => 'main']],
        ]],
    ], 8);
    if (file_put_contents($config, $yaml) === false) {
        throw new RuntimeException('Could not write preview configuration.');
    }
};

putenv('GITHUB_PR_NUMBER');
$targets = [];
try {
    foreach ([[$name, $orphanHost, $orphan], [$control, $controlHost, $sentinel]] as [$project, $host, $identity]) {
        $writeConfig($project, $host);
        $targets[] = [$project, $host, $identity];
        $run(['apply', $project, '--profile=preview', '--config='.$config, '--force']);
        $routes = $client->listDomains($identity, 'web');
        if (array_column($routes, 'host') !== [$host]) {
            throw new RuntimeException('Preview did not have exactly its expected route.');
        }
    }

    $writeConfig($name, $template);
    $dryRun = $run(['cleanup-orphaned', '--dry-run', '--force', '--config='.$config]);
    if (! str_contains($dryRun, '[DRY RUN] Would delete 1 sites')
        || ! str_contains($dryRun, $orphanHost.' (PR #30)')
        || str_contains($dryRun, $controlHost) || ! $exists($orphan)) {
        throw new RuntimeException('The dry run did not preserve and report the expected orphan.');
    }

    $result = $run(['cleanup-orphaned', '--force', '--config='.$config]);
    if (! str_contains($result, 'Cleanup complete: 1 deleted, 0 failed')
        || $exists($orphan) || ! $exists($sentinel) || array_column($client->listDomains($sentinel, 'web'), 'host') !== [$controlHost]) {
        throw new RuntimeException('Orphan cleanup did not remove only the intended preview.');
    }
    echo "Verified preview dry run, orphan removal, and control route preservation.\n";
} finally {
    $cleanupFailures = [];
    try {
        foreach ($targets as [$project, $host, $identity]) {
            try {
                if ($exists($identity)) {
                    $writeConfig($project, $host);
                    $run(['destroy', $project, '--profile=preview', '--config='.$config, '--force']);
                }
                if ($exists($identity)) {
                    $cleanupFailures[] = $identity;
                }
            } catch (Throwable) {
                $cleanupFailures[] = $identity;
            }
        }
    } finally {
        unlink($config);
    }
    if ($cleanupFailures !== []) {
        throw new RuntimeException('Could not verify removal of disposable projects: '.implode(', ', $cleanupFailures));
    }
}
