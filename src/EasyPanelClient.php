<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel;

final class EasyPanelClient
{
    /** @var (\Closure(string, array<string, mixed>): mixed)|null */
    private readonly ?\Closure $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authToken,
        private readonly int $timeout = 30,
        private readonly bool $verifySsl = true,
        ?callable $transport = null,
    ) {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => '[redacted]',
            'authToken' => '[redacted]',
            'timeout' => $this->timeout,
            'verifySsl' => $this->verifySsl,
            'transport' => $this->transport === null ? null : 'custom',
        ];
    }

    /** @return array{projects: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
    public function listProjectsAndServices(): array
    {
        $data = $this->call('projects.listProjectsAndServices');

        return [
            'projects' => \is_array($data) && \is_array($data['projects'] ?? null) ? \array_values($data['projects']) : [],
            'services' => \is_array($data) && \is_array($data['services'] ?? null) ? \array_values($data['services']) : [],
        ];
    }

    public function createProject(string $projectName): void
    {
        $this->call('projects.createProject', ['name' => $projectName]);
    }

    public function destroyProject(string $projectName): void
    {
        $this->call('projects.destroyProject', ['projectName' => $projectName]);
    }

    public function createAppService(string $projectName, string $serviceName): void
    {
        $this->call('services.app.createService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    /** @return array<string, mixed> */
    public function inspectAppService(string $projectName, string $serviceName): array
    {
        $data = $this->call('services.app.inspectService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);

        return \is_array($data) ? $data : [];
    }

    public function updateImageSource(string $projectName, string $serviceName, string $image): void
    {
        $this->call('services.app.updateSourceImage', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'image' => $image,
        ]);
    }

    public function updateGitSource(string $projectName, string $serviceName, string $repo, string $ref, string $path): void
    {
        $this->call('services.app.updateSourceGit', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'repo' => $repo,
            'ref' => $ref,
            'path' => $path,
        ]);
    }

    public function updateGithubSource(
        string $projectName,
        string $serviceName,
        string $owner,
        string $repo,
        string $ref,
        string $path,
    ): void {
        $this->call('services.app.updateSourceGithub', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'owner' => $owner,
            'repo' => $repo,
            'ref' => $ref,
            'path' => $path,
        ]);
    }

    public function updateDockerfileSource(string $projectName, string $serviceName, string $dockerfile): void
    {
        $this->call('services.app.updateSourceDockerfile', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'dockerfile' => $dockerfile,
        ]);
    }

    public function updateEnvironment(string $projectName, string $serviceName, string $env): void
    {
        $this->call('services.app.updateEnv', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'env' => $env,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function listDomains(string $projectName, string $serviceName): array
    {
        $data = $this->call('domains.listDomains', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);

        return \is_array($data) ? \array_values($data) : [];
    }

    public function createDomain(
        string $projectName,
        string $serviceName,
        string $host,
        int $port = 80,
        string $protocol = 'http',
    ): void {
        $this->call('domains.createDomain', [
            'certificateResolver' => '',
            'destinationType' => 'service',
            'host' => $host,
            'https' => true,
            'id' => '',
            'middlewares' => [],
            'path' => '/',
            'serviceDestination' => [
                'path' => '/',
                'port' => $port,
                'projectName' => $projectName,
                'protocol' => $protocol,
                'serviceName' => $serviceName,
            ],
            'wildcard' => false,
        ]);
    }

    public function deployAppService(string $projectName, string $serviceName): void
    {
        $this->call('services.app.deployService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    public function queryServiceLogs(string $projectName, string $serviceName, array $filters = []): array
    {
        $data = $this->call('logs.queryServiceLogs', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            ...$filters,
        ]);

        if (! is_array($data)) {
            return [];
        }

        $logs = $data['logs'] ?? $data;
        return is_array($logs) ? array_values(array_filter($logs, 'is_array')) : [];
    }

    public function destroyAppService(string $projectName, string $serviceName): void
    {
        $this->call('services.app.destroyService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    /** @param array<string, mixed> $input */
    public function call(string $procedure, array $input = []): mixed
    {
        if ($this->transport !== null) {
            return ($this->transport)($procedure, $input);
        }

        if (! \function_exists('curl_init')) {
            throw new \RuntimeException('The EasyPanel provider requires the PHP cURL extension.');
        }

        $payload = \json_encode(['json' => (object) $input], JSON_THROW_ON_ERROR);
        $handle = \curl_init(\rtrim($this->baseUrl, '/').'/api/trpc/'.$procedure);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize the EasyPanel API request.');
        }

        \curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: '.$this->authToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $response = \curl_exec($handle);
        $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = \curl_error($handle);
        unset($handle);

        if ($response === false) {
            throw new \RuntimeException('EasyPanel API transport error: '.$curlError);
        }

        try {
            $decoded = \json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('EasyPanel API returned invalid JSON.', 0, $exception);
        }

        if (! \is_array($decoded)) {
            throw new \RuntimeException('EasyPanel API returned an invalid response.');
        }

        if ($status >= 400) {
            $message = $decoded['json']['message'] ?? ('HTTP '.$status);
            $validation = $decoded['json']['data']['zodErrors'] ?? null;
            if ($validation !== null) {
                $message .= ': '.\json_encode($validation, JSON_UNESCAPED_SLASHES);
            }

            throw new \RuntimeException('EasyPanel API request failed: '.$message);
        }

        if (\array_key_exists('json', $decoded)) {
            return $decoded['json'];
        }

        if (\array_key_exists('json', $decoded['result']['data'] ?? [])) {
            return $decoded['result']['data']['json'];
        }

        return null;
    }
}
