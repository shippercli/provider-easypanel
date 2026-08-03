<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel;

use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\ProviderCapabilitiesInterface;

final class EasyPanelProvider implements DeploymentProviderInterface, ProviderCapabilitiesInterface
{
    private const MANAGED_MARKER = 'SHIPPERCLI_MANAGED=1';

    /** @var array<string, mixed> */
    private readonly array $config;

    private ?EasyPanelClient $client;

    private string $lastError = '';

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [], ?EasyPanelClient $client = null)
    {
        $this->config = $config;
        $this->client = $client;
    }

    public function getName(): string
    {
        return 'easypanel';
    }

    public function capabilities(): array
    {
        return [
            'app_deploy' => ['state' => 'supported'],
            'domain_management' => ['state' => 'supported'],
            'ssl' => [
                'state' => 'partial',
                'notes' => 'HTTPS is requested through EasyPanel domain configuration; certificate lifecycle details are provider-managed.',
            ],
            'env' => ['state' => 'supported'],
            'profiles' => ['state' => 'supported'],
            'observability' => [
                'state' => 'partial',
                'limitations' => ['Deployment logs are not yet exposed through the provider contract.'],
            ],
            'rollback' => ['state' => 'unsupported'],
            'previews' => [
                'state' => 'partial',
                'notes' => 'Preview cleanup is supported when Shipper ownership markers are present.',
            ],
            'server_lifecycle' => ['state' => 'unsupported'],
            'databases' => ['state' => 'unsupported'],
            'background_workloads' => ['state' => 'unsupported'],
        ];
    }

    public function validate(object $project, object $profile): array
    {
        $errors = $this->validateConnectionConfig();
        $source = $this->source($project, $profile);

        if ($source === null) {
            $errors[] = 'EasyPanel source is required. Configure an image, Git repository, GitHub repository, or Dockerfile.';
        } else {
            $errors = [...$errors, ...$this->validateSource($source)];
        }

        $domain = $this->profileValue($profile, 'domain');
        if ($domain !== null && $domain !== '' && $this->normalizeDomain($domain) === null) {
            $errors[] = 'EasyPanel profile domain must be a valid hostname or URL.';
        }

        return $errors;
    }

    public function plan(object $project, object $profile): array
    {
        $projectName = $this->managedProjectName($project, $profile);
        $serviceName = $this->serviceName();
        $source = $this->source($project, $profile) ?? ['type' => 'unknown'];
        $domain = $this->normalizeDomain($this->profileValue($profile, 'domain'));
        $actions = [
            'Create or reuse managed EasyPanel project: '.$projectName,
            'Create or reuse app service: '.$serviceName,
            'Configure '.$this->sourceDescription($source),
            'Apply environment variables and Shipper ownership marker',
        ];

        if ($domain !== null) {
            $actions[] = 'Create or reuse HTTPS domain: '.$domain;
        } else {
            $actions[] = 'Use the EasyPanel-generated HTTPS domain';
        }

        $actions[] = 'Deploy app service through the EasyPanel API';

        return [
            'provider' => $this->getName(),
            'project' => $this->projectName($project),
            'profile' => $this->profileName($profile),
            'easypanel_project' => $projectName,
            'service' => $serviceName,
            'source_type' => $source['type'] ?? 'unknown',
            'domain' => $domain,
            'actions' => $actions,
            'note' => 'Shipper will only clean up the exact service after verifying its ownership marker.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        $this->lastError = '';
        $errors = $this->validate($project, $profile);
        if ($errors !== []) {
            $this->lastError = \implode("\n", $errors);

            return false;
        }

        $projectName = $this->managedProjectName($project, $profile);
        $serviceName = $this->serviceName();

        try {
            $client = $this->getClient();
            $inventory = $client->listProjectsAndServices();

            if (! $this->projectExists($inventory, $projectName)) {
                $client->createProject($projectName);
            }

            $service = $this->findService($inventory, $projectName, $serviceName);
            if ($service !== null && ($service['type'] ?? null) !== 'app') {
                throw new \RuntimeException('The managed EasyPanel service name is already used by a non-app service.');
            }

            if ($service === null) {
                $client->createAppService($projectName, $serviceName);
            }

            $source = $this->source($project, $profile);
            \assert($source !== null);
            $this->configureSource($client, $projectName, $serviceName, $source, $profile);
            $client->updateEnvironment($projectName, $serviceName, $this->environment($projectName, $profile));

            $domain = $this->normalizeDomain($this->profileValue($profile, 'domain'));
            if ($domain !== null && ! $this->domainExists($client->listDomains($projectName, $serviceName), $domain)) {
                $client->createDomain(
                    $projectName,
                    $serviceName,
                    $domain,
                    $this->integerConfig('port', 80),
                    $this->stringConfig('protocol', 'http'),
                );
            }

            $client->deployAppService($projectName, $serviceName);

            return true;
        } catch (\Throwable $exception) {
            $this->lastError = 'EasyPanel deployment failed: '.$exception->getMessage();

            return false;
        }
    }

    public function destroy(object $project, object $profile): bool
    {
        $this->lastError = '';
        $errors = $this->validateConnectionConfig();
        if ($errors !== []) {
            $this->lastError = \implode("\n", $errors);

            return false;
        }

        $projectName = $this->managedProjectName($project, $profile);
        $serviceName = $this->serviceName();
        $managedPrefix = $this->managedPrefix();

        if (! \str_starts_with($projectName, $managedPrefix)) {
            $this->lastError = 'Refusing to destroy an EasyPanel project outside the configured managed prefix.';

            return false;
        }

        try {
            $client = $this->getClient();
            $inventory = $client->listProjectsAndServices();
            if (! $this->projectExists($inventory, $projectName)) {
                return true;
            }

            $service = $this->findService($inventory, $projectName, $serviceName);
            if ($service === null) {
                return true;
            }

            if (($service['type'] ?? null) !== 'app') {
                throw new \RuntimeException('Refusing to destroy a non-app EasyPanel service.');
            }

            $inspected = $client->inspectAppService($projectName, $serviceName);
            $env = \is_string($inspected['env'] ?? null) ? $inspected['env'] : '';
            $ownershipMarker = 'SHIPPERCLI_MANAGED_PROJECT='.$projectName;
            if (! $this->hasEnvironmentLine($env, self::MANAGED_MARKER) || ! $this->hasEnvironmentLine($env, $ownershipMarker)) {
                throw new \RuntimeException('Refusing to destroy an EasyPanel service without Shipper ownership markers.');
            }

            $client->destroyAppService($projectName, $serviceName);

            if ($this->booleanConfig('destroy_project', true)) {
                $remaining = $client->listProjectsAndServices();
                if (! $this->projectHasServices($remaining, $projectName)) {
                    $client->destroyProject($projectName);
                }
            }

            return true;
        } catch (\Throwable $exception) {
            $this->lastError = 'EasyPanel cleanup failed: '.$exception->getMessage();

            return false;
        }
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    protected function getClient(): EasyPanelClient
    {
        if ($this->client === null) {
            $this->client = new EasyPanelClient(
                $this->stringConfig('url'),
                $this->authToken(),
                $this->integerConfig('timeout', 30),
                $this->booleanConfig('verify_ssl', true),
            );
        }

        return $this->client;
    }

    /** @return array<int, string> */
    private function validateConnectionConfig(): array
    {
        $errors = [];
        $url = $this->stringConfig('url');
        if ($url === '' || ! \in_array(\parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $errors[] = 'EasyPanel URL must be a valid HTTP or HTTPS URL.';
        }

        if ($this->authToken() === '') {
            $errors[] = 'EasyPanel auth token is required.';
        }

        $prefix = $this->managedPrefix();
        if (! \preg_match('/^[a-z0-9][a-z0-9-]*-$/', $prefix)) {
            $errors[] = 'EasyPanel managed_prefix must be a lowercase slug ending in a hyphen.';
        }

        $serviceName = $this->serviceName();
        if (! \preg_match('/^[a-z0-9][a-z0-9-]*$/', $serviceName)) {
            $errors[] = 'EasyPanel service_name must be a lowercase slug.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $source @return array<int, string> */
    private function validateSource(array $source): array
    {
        $type = $source['type'] ?? null;
        if (! \is_string($type) || ! \in_array($type, ['image', 'git', 'github', 'dockerfile'], true)) {
            return ['EasyPanel source.type must be image, git, github, or dockerfile.'];
        }

        return match ($type) {
            'image' => $this->requiredStringErrors($source, ['image'], 'EasyPanel image source'),
            'git' => $this->requiredStringErrors($source, ['repo'], 'EasyPanel Git source'),
            'github' => $this->requiredStringErrors($source, ['owner', 'repo'], 'EasyPanel GitHub source'),
            'dockerfile' => $this->requiredStringErrors($source, ['dockerfile'], 'EasyPanel Dockerfile source'),
        };
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys @return array<int, string> */
    private function requiredStringErrors(array $source, array $keys, string $label): array
    {
        $errors = [];
        foreach ($keys as $key) {
            if (! \is_string($source[$key] ?? null) || \trim($source[$key]) === '') {
                $errors[] = $label.' requires '.$key.'.';
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $source */
    private function configureSource(
        EasyPanelClient $client,
        string $projectName,
        string $serviceName,
        array $source,
        object $profile,
    ): void {
        $type = (string) $source['type'];
        $ref = \is_string($source['ref'] ?? null) ? $source['ref'] : $this->profileBranch($profile);
        $path = \is_string($source['path'] ?? null) ? $source['path'] : '/';

        match ($type) {
            'image' => $client->updateImageSource($projectName, $serviceName, (string) $source['image']),
            'git' => $client->updateGitSource($projectName, $serviceName, (string) $source['repo'], $ref, $path),
            'github' => $client->updateGithubSource(
                $projectName,
                $serviceName,
                (string) $source['owner'],
                (string) $source['repo'],
                $ref,
                $path,
            ),
            'dockerfile' => $client->updateDockerfileSource($projectName, $serviceName, (string) $source['dockerfile']),
            default => throw new \RuntimeException('Unsupported EasyPanel source type.'),
        };
    }

    /** @return array<string, mixed>|null */
    private function source(object $project, object $profile): ?array
    {
        $profileSource = $this->profileValue($profile, 'source');
        if (\is_array($profileSource)) {
            return $profileSource;
        }

        if (\is_array($this->config['source'] ?? null)) {
            return $this->config['source'];
        }

        $image = $this->profileValue($profile, 'image') ?? ($this->config['image'] ?? null);
        if (\is_string($image) && $image !== '') {
            return ['type' => 'image', 'image' => $image];
        }

        $repository = $this->projectRepository($project);
        $name = $repository['name'] ?? null;
        if (! \is_string($name) || $name === '') {
            return null;
        }

        $provider = \is_string($repository['provider'] ?? null) ? \strtolower($repository['provider']) : '';
        $repo = $name;
        if (! \preg_match('#^(https?://|ssh://|git@)#', $repo)) {
            $repo = match ($provider) {
                'github' => 'https://github.com/'.$repo.'.git',
                'gitlab' => 'https://gitlab.com/'.$repo.'.git',
                'bitbucket' => 'https://bitbucket.org/'.$repo.'.git',
                default => '',
            };
        }

        return $repo === '' ? null : [
            'type' => 'git',
            'repo' => $repo,
            'ref' => $this->profileBranch($profile),
            'path' => '/',
        ];
    }

    /** @param array<string, mixed> $source */
    private function sourceDescription(array $source): string
    {
        return match ($source['type'] ?? null) {
            'image' => 'container image source',
            'git' => 'Git source',
            'github' => 'GitHub source',
            'dockerfile' => 'Dockerfile source',
            default => 'application source',
        };
    }

    private function environment(string $projectName, object $profile): string
    {
        $lines = [];
        foreach ([$this->config['env'] ?? null, $this->profileValue($profile, 'env'), $this->profileValue($profile, 'environment')] as $value) {
            if (\is_string($value) && \trim($value) !== '') {
                $lines[] = \trim($value);
            } elseif (\is_array($value)) {
                foreach ($value as $key => $item) {
                    if (\is_string($key) && (\is_scalar($item) || $item === null)) {
                        $lines[] = $key.'='.(string) $item;
                    }
                }
            }
        }

        $lines[] = self::MANAGED_MARKER;
        $lines[] = 'SHIPPERCLI_MANAGED_PROJECT='.$projectName;
        $lines[] = 'SHIPPERCLI_PROFILE='.$this->profileName($profile);

        return \implode("\n", $lines);
    }

    private function hasEnvironmentLine(string $env, string $line): bool
    {
        return \in_array($line, \preg_split('/\R/', $env) ?: [], true);
    }

    /** @param array{projects: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} $inventory */
    private function projectExists(array $inventory, string $projectName): bool
    {
        foreach ($inventory['projects'] as $project) {
            if (($project['name'] ?? null) === $projectName) {
                return true;
            }
        }

        return false;
    }

    /** @param array{projects: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} $inventory @return array<string, mixed>|null */
    private function findService(array $inventory, string $projectName, string $serviceName): ?array
    {
        foreach ($inventory['services'] as $service) {
            if (($service['projectName'] ?? null) === $projectName && ($service['name'] ?? null) === $serviceName) {
                return $service;
            }
        }

        return null;
    }

    /** @param array{projects: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} $inventory */
    private function projectHasServices(array $inventory, string $projectName): bool
    {
        foreach ($inventory['services'] as $service) {
            if (($service['projectName'] ?? null) === $projectName) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $domains */
    private function domainExists(array $domains, string $host): bool
    {
        foreach ($domains as $domain) {
            if (($domain['host'] ?? null) === $host) {
                return true;
            }
        }

        return false;
    }

    private function managedProjectName(object $project, object $profile): string
    {
        return $this->managedPrefix().$this->slugify($this->projectName($project)).'-'.$this->slugify($this->profileName($profile));
    }

    private function managedPrefix(): string
    {
        return $this->stringConfig('managed_prefix', 'shipper-');
    }

    private function serviceName(): string
    {
        return $this->stringConfig('service_name', 'app');
    }

    private function authToken(): string
    {
        $token = $this->config['auth_token'] ?? ($this->config['token'] ?? '');

        return \is_string($token) ? $token : '';
    }

    private function projectName(object $project): string
    {
        return \method_exists($project, 'name') ? (string) $project->name() : 'unknown';
    }

    /** @return array<string, mixed> */
    private function projectRepository(object $project): array
    {
        $repository = \method_exists($project, 'repository') ? $project->repository() : [];

        return \is_array($repository) ? $repository : [];
    }

    private function profileName(object $profile): string
    {
        return \method_exists($profile, 'name') ? (string) $profile->name() : 'unknown';
    }

    private function profileBranch(object $profile): string
    {
        return \method_exists($profile, 'branch') ? (string) $profile->branch() : 'main';
    }

    private function profileValue(object $profile, string $key): mixed
    {
        return \method_exists($profile, 'get') ? $profile->get($key) : null;
    }

    private function normalizeDomain(mixed $value): ?string
    {
        if (! \is_string($value) || \trim($value) === '') {
            return null;
        }

        $candidate = \trim($value);
        $host = \parse_url(\str_contains($candidate, '://') ? $candidate : 'https://'.$candidate, PHP_URL_HOST);
        if (! \is_string($host) || $host === '' || ! \filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        return \strtolower($host);
    }

    private function slugify(string $value): string
    {
        $slug = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($value));
        $slug = \trim($slug, '-');

        return $slug !== '' ? $slug : 'unnamed';
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return \is_string($value) ? $value : $default;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;

        return \is_int($value) || \is_numeric($value) ? (int) $value : $default;
    }

    private function booleanConfig(string $key, bool $default): bool
    {
        $value = $this->config[$key] ?? $default;

        return \is_bool($value) ? $value : $default;
    }
}
