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

        if (! \is_array($data) || ! \is_array($data['projects'] ?? null) || ! \array_is_list($data['projects'])
            || ! \is_array($data['services'] ?? null) || ! \array_is_list($data['services'])) {
            throw new \RuntimeException('EasyPanel returned an invalid project/service inventory.');
        }
        $projects = [];
        $services = [];
        foreach ($data['projects'] as $project) {
            if (! \is_array($project) || ! \is_string($project['name'] ?? null) || $project['name'] === ''
                || \trim($project['name']) !== $project['name'] || isset($projects[$project['name']])) {
                throw new \RuntimeException('EasyPanel returned an invalid project inventory entry.');
            }
            $projects[$project['name']] = true;
        }
        foreach ($data['services'] as $service) {
            foreach (['projectName', 'name', 'type'] as $key) {
                if (! \is_array($service) || ! \is_string($service[$key] ?? null) || $service[$key] === ''
                    || \trim($service[$key]) !== $service[$key]) {
                    throw new \RuntimeException('EasyPanel returned an invalid service inventory entry.');
                }
            }
            $identity = \json_encode([$service['projectName'], $service['name']], JSON_THROW_ON_ERROR);
            if (! isset($projects[$service['projectName']]) || isset($services[$identity])) {
                throw new \RuntimeException('EasyPanel returned an ambiguous or inconsistent service inventory.');
            }
            $services[$identity] = true;
        }

        return [
            'projects' => $data['projects'],
            'services' => $data['services'],
        ];
    }

    public function createProject(string $projectName): void
    {
        $this->call('projects.createProject', ['name' => $projectName]);
    }

    public function destroyProject(string $projectName): void
    {
        $this->call('projects.destroyProject', ['name' => $projectName]);
    }

    public function createAppService(string $projectName, string $serviceName, ?string $environment = null): void
    {
        $input = [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ];
        if ($environment === null) {
            $this->call('services.app.createService', $input);
            return;
        }

        $input['env'] = $environment;
        if ($this->transport !== null) {
            ($this->transport)('createAppService', $input);
            return;
        }

        // The provider verifies the newly created app and its ownership through inspection.
        $this->post('/api/createAppService', 'Bearer '.$this->authToken, $input);
    }

    public function createBoxService(string $projectName, string $serviceName): void
    {
        $this->call('services.box.createService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    public function initializeBoxService(string $projectName, string $serviceName): void
    {
        $input = ['projectName' => $projectName, 'serviceName' => $serviceName];
        if ($this->transport !== null) {
            ($this->transport)('initBoxService', $input);
            return;
        }

        $response = \trim($this->post('/api/initBoxService', 'Bearer '.$this->authToken, $input));
        if ($response !== '' && $response !== 'null') {
            throw new \RuntimeException('EasyPanel returned an unexpected Box initialization response.');
        }
    }

    public function cloneBoxRepository(string $projectName, string $serviceName, string $url, string $branch, bool $private = false): void
    {
        $input = [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'url' => $url,
            'branch' => $branch,
            'private' => $private,
        ];
        if ($this->transport !== null) {
            ($this->transport)('gitBoxClone', $input);
            return;
        }

        try {
            $response = $this->post('/api/gitBoxClone', 'Bearer '.$this->authToken, $input);
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException('EasyPanel Box Git clone failed: '.$exception->getMessage(), $exception->getCode(), $exception);
        }
        $body = \trim($response);
        // EasyPanel can acknowledge the clone with an empty 2xx body.
        if ($body !== '' && $body !== 'null') {
            $shape = match (true) {
                \str_starts_with($body, '<') => 'markup',
                default => \get_debug_type(\json_decode($body)).' decoded body',
            };
            throw new \RuntimeException('EasyPanel returned an unexpected Box Git clone response ('.$shape.').');
        }
    }

    public function updateBoxEnvironment(string $projectName, string $serviceName, string $env): void
    {
        $this->call('services.box.updateEnv', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'env' => ['content' => $env],
        ]);
    }

    /** @param array<int, array<string, mixed>> $scripts */
    public function updateBoxScripts(string $projectName, string $serviceName, array $scripts): void
    {
        $this->call('services.box.updateScripts', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'scripts' => $scripts,
        ]);
    }

    public function restartBoxService(string $projectName, string $serviceName): void
    {
        $this->call('services.box.restartService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    /** @return array<string, mixed> */
    public function inspectBoxService(string $projectName, string $serviceName): array
    {
        $data = $this->call('services.box.inspectService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);

        return $this->inspectionData($data, $projectName, $serviceName, 'box');
    }

    public function destroyBoxService(string $projectName, string $serviceName): void
    {
        $this->call('services.box.destroyService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function createDatabaseService(string $type, string $projectName, string $serviceName, array $payload = []): void
    {
        $type = $this->databaseType($type);
        foreach (['projectName' => $projectName, 'serviceName' => $serviceName] as $key => $expected) {
            if (\array_key_exists($key, $payload) && $payload[$key] !== $expected) {
                throw new \InvalidArgumentException('EasyPanel database payload must not override the target identity.');
            }
        }
        $this->call('services.'.$type.'.createService', [
            ...$payload,
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    /** @return array<string, mixed> */
    public function inspectDatabaseService(string $type, string $projectName, string $serviceName): array
    {
        $type = $this->databaseType($type);
        $data = $this->call('services.'.$type.'.inspectService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);

        return $this->inspectionData($data, $projectName, $serviceName, $type);
    }

    public function destroyDatabaseService(string $type, string $projectName, string $serviceName): void
    {
        $type = $this->databaseType($type);
        $this->call('services.'.$type.'.destroyService', [
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

        return $this->inspectionData($data, $projectName, $serviceName, 'app');
    }

    public function updateImageSource(string $projectName, string $serviceName, string $image): void
    {
        $result = $this->call('services.app.updateSourceImage', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'image' => $image,
        ]);
        if ($result instanceof \stdClass && (array) $result === []) {
            $inspection = $this->inspectAppService($projectName, $serviceName);
            $savedImage = $inspection['source']['image'] ?? $inspection['image'] ?? null;
            if ($savedImage !== $image) {
                throw new \RuntimeException('EasyPanel image source update could not be verified.');
            }
        }
    }

    public function updateGitSource(string $projectName, string $serviceName, string $repo, string $ref, string $path): void
    {
        $result = $this->call('services.app.updateSourceGit', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'repo' => $repo,
            'ref' => $ref,
            'path' => $path,
        ]);
        if ($result instanceof \stdClass && (array) $result === []) {
            $inspection = $this->inspectAppService($projectName, $serviceName);
            $source = $inspection['source'] ?? null;
            if (($source['type'] ?? null) !== 'git'
                || ($source['repo'] ?? null) !== $repo
                || ($source['ref'] ?? null) !== $ref
                || ($source['path'] ?? null) !== $path) {
                throw new \RuntimeException('EasyPanel Git source update could not be verified.');
            }
        }
    }

    public function updateGithubSource(
        string $projectName,
        string $serviceName,
        string $owner,
        string $repo,
        string $ref,
        string $path,
    ): void {
        $result = $this->call('services.app.updateSourceGithub', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'owner' => $owner,
            'repo' => $repo,
            'ref' => $ref,
            'path' => $path,
        ]);
        if ($result instanceof \stdClass && (array) $result === []) {
            $inspection = $this->inspectAppService($projectName, $serviceName);
            $source = $inspection['source'] ?? null;
            if (($source['type'] ?? null) !== 'github'
                || ($source['owner'] ?? null) !== $owner
                || ($source['repo'] ?? null) !== $repo
                || ($source['ref'] ?? null) !== $ref
                || ($source['path'] ?? null) !== $path) {
                throw new \RuntimeException('EasyPanel GitHub source update could not be verified.');
            }
        }
    }

    public function updateDockerfileSource(string $projectName, string $serviceName, string $dockerfile): void
    {
        $result = $this->call('services.app.updateSourceDockerfile', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'dockerfile' => $dockerfile,
        ]);
        if ($result instanceof \stdClass && (array) $result === []) {
            $inspection = $this->inspectAppService($projectName, $serviceName);
            if (($inspection['source']['type'] ?? null) !== 'dockerfile'
                || ($inspection['source']['dockerfile'] ?? null) !== $dockerfile) {
                throw new \RuntimeException('EasyPanel Dockerfile source update could not be verified.');
            }
        }
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

        if (! \is_array($data) || ! \array_is_list($data)) {
            throw new \RuntimeException('EasyPanel returned an invalid domain inventory.');
        }
        foreach ($data as $domain) {
            if (! \is_array($domain) || ! \is_string($domain['host'] ?? null) || $domain['host'] === ''
                || \trim($domain['host']) !== $domain['host']) {
                throw new \RuntimeException('EasyPanel returned an invalid domain inventory.');
            }
        }
        return $data;
    }

    public function serviceBaseDomain(): ?string
    {
        try {
            $settings = $this->transport !== null
                ? ($this->transport)('getServiceDomain', [])
                : \json_decode($this->request('/api/getServiceDomain', 'Bearer '.$this->authToken), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\RuntimeException $exception) {
            if (\in_array($exception->getCode(), [403, 404], true)) {
                return null;
            }
            throw $exception;
        }

        if ($settings === null) {
            return null;
        }
        if (! \is_array($settings)) {
            throw new \RuntimeException('EasyPanel returned invalid service domain settings.');
        }

        foreach (['customServiceDomain', 'defaultServiceDomain'] as $key) {
            $domain = $settings[$key] ?? '';
            if ($domain === '') {
                continue;
            }
            if (! \is_string($domain) || ! \filter_var($domain, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME)
                || ! \str_contains($domain, '.') || \str_ends_with($domain, '.') || \filter_var($domain, \FILTER_VALIDATE_IP)) {
                throw new \RuntimeException('EasyPanel returned an invalid service base domain.');
            }

            return \strtolower($domain);
        }

        return null;
    }

    public function createDomain(
        string $projectName,
        string $serviceName,
        string $host,
        int $port = 80,
        string $protocol = 'http',
    ): void {
        $response = $this->call('domains.createDomain', [
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

        if (! $response instanceof \stdClass || (array) $response !== []) {
            return;
        }

        foreach ($this->listDomains($projectName, $serviceName) as $domain) {
            if (\strcasecmp($domain['host'], $host) === 0
                && $this->matchesHttpsBinding($domain, $projectName, $serviceName, $port, $protocol)) {
                return;
            }
        }

        throw new \RuntimeException('EasyPanel did not persist the requested HTTPS binding.');
    }

    /** @param array<string, mixed> $domain */
    public function matchesHttpsBinding(array $domain, string $projectName, string $serviceName, int $port, string $protocol): bool
    {
        $destination = $domain['serviceDestination'] ?? null;

        return ($domain['https'] ?? null) === true
            && ($domain['destinationType'] ?? null) === 'service'
            && ($destination['projectName'] ?? null) === $projectName
            && ($destination['serviceName'] ?? null) === $serviceName
            && ($domain['path'] ?? null) === '/'
            && ($destination['path'] ?? null) === '/'
            && ($destination['protocol'] ?? null) === $protocol
            && ($destination['port'] ?? null) === $port;
    }

    public function deployAppService(string $projectName, string $serviceName): void
    {
        $this->call('services.app.deployService', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
        ]);
    }

    /** @param array<string, mixed> $deploy */
    public function updateAppDeployment(string $projectName, string $serviceName, array $deploy): void
    {
        $result = $this->call('services.app.updateDeploy', [
            'projectName' => $projectName,
            'serviceName' => $serviceName,
            'deploy' => $deploy,
        ]);
        if ($result instanceof \stdClass && (array) $result === []) {
            $inspection = $this->inspectAppService($projectName, $serviceName);
            $saved = $inspection['deploy'] ?? null;
            if (! \is_array($saved) || $deploy === []) {
                throw new \RuntimeException('EasyPanel app deployment settings could not be verified.');
            }
            foreach ($deploy as $key => $value) {
                if (! \array_key_exists($key, $saved) || $saved[$key] !== $value) {
                    throw new \RuntimeException('EasyPanel app deployment settings could not be verified.');
                }
            }
        }
    }

    /** @param array<string, mixed> $filters @return array<int, array<string, mixed>> */
    public function queryServiceLogs(string $projectName, string $serviceName, array $filters = []): array
    {
        $allowedFilters = array_intersect_key($filters, array_flip(['start', 'end', 'limit', 'search', 'levels', 'stream']));
        $data = $this->call('logs.queryServiceLogs', [
            ...$allowedFilters,
            'projectName' => $projectName,
            'serviceName' => $serviceName,
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

        $response = $this->post('/api/trpc/'.$procedure, $this->authToken, ['json' => (object) $input]);

        return $this->decodeResponse($response, $procedure);
    }

    public function hasRunningAppContainer(string $projectName, string $serviceName): bool
    {
        $identity = $projectName.'_'.$serviceName;
        $query = ['service' => $identity];
        $containers = $this->transport !== null
            ? ($this->transport)('getDockerContainers', $query)
            : \json_decode($this->request('/api/getDockerContainers?'.\http_build_query($query), 'Bearer '.$this->authToken));

        if (! \is_array($containers) || ! \array_is_list($containers)) {
            return false;
        }

        foreach ($containers as $container) {
            if (($container->State ?? null) === 'running'
                && ($container->Labels->{'com.docker.swarm.service.name'} ?? null) === $identity) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $input */
    private function post(string $path, #[\SensitiveParameter] string $authorization, #[\SensitiveParameter] array $input): string
    {
        return $this->request($path, $authorization, $input);
    }

    /** @param array<string, mixed>|null $input */
    private function request(string $path, #[\SensitiveParameter] string $authorization, #[\SensitiveParameter] ?array $input = null): string
    {
        if (! \function_exists('curl_init')) {
            throw new \RuntimeException('The EasyPanel provider requires the PHP cURL extension.');
        }

        $handle = \curl_init(\rtrim($this->baseUrl, '/').$path);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize the EasyPanel API request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: '.$authorization],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ];
        if ($input !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = \json_encode($input, JSON_THROW_ON_ERROR);
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        \curl_setopt_array($handle, $options);

        $response = \curl_exec($handle);
        $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlErrorNumber = \curl_errno($handle);
        unset($handle);

        if ($response === false) {
            throw new \RuntimeException('EasyPanel API transport error (cURL '.$curlErrorNumber.').');
        }

        if ($status < 200 || $status >= 300) {
            $operation = 'request';
            if (\preg_match('~\A/api/trpc/([A-Za-z][A-Za-z0-9.-]{0,95})\z~', $path, $matches) === 1) {
                $operation = 'procedure '.$matches[1];
            }

            $hint = '';
            if ($status === 400 && $path === '/api/trpc/services.app.deployService' && \strlen($response) <= 16384) {
                $error = \json_decode($response);
                $message = $error->error->json->message ?? $error->error->message ?? null;
                if (\is_string($message)) {
                    if (\preg_match('/\b(rate limit exceeded|too many requests)\b/i', $message) === 1) {
                        $hint = ' EasyPanel reported an upstream rate limit.';
                    } elseif (\stripos($message, 'github') !== false
                        && \preg_match('/\b(token|credentials?|unauthorized|forbidden|authentication)\b/i', $message) === 1) {
                        $hint = ' EasyPanel reported a GitHub authentication or token error. Check its GitHub settings.';
                    } elseif (\preg_match('/\b(repository not found|branch not found|reference not found)\b/i', $message) === 1) {
                        $hint = ' EasyPanel could not find the source repository or reference.';
                    } elseif (\preg_match('/\b((invalid|missing) build path|dockerfile not found|failed to read (the )?dockerfile)\b/i', $message) === 1) {
                        $hint = ' EasyPanel reported a build path or Dockerfile error.';
                    }
                }
            }

            throw new \RuntimeException('EasyPanel API '.$operation.' failed (HTTP '.$status.').'.$hint, $status);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function inspectionData(mixed $data, string $projectName, string $serviceName, string $type): array
    {
        if (! \is_array($data) || ($data !== [] && \array_is_list($data))) {
            throw new \RuntimeException('EasyPanel returned an invalid service inspection.');
        }
        foreach (['projectName' => $projectName, 'serviceName' => $serviceName, 'name' => $serviceName, 'type' => $type] as $key => $expected) {
            if (\array_key_exists($key, $data) && $data[$key] !== $expected) {
                throw new \RuntimeException('EasyPanel returned a mismatched service inspection identity.');
            }
        }
        return $data;
    }

    private function decodeResponse(string $response, string $procedure): mixed
    {
        try {
            $shape = \json_decode($response, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('EasyPanel API returned invalid JSON.');
        }
        if (! $shape instanceof \stdClass) {
            throw new \RuntimeException('EasyPanel API returned an invalid response.');
        }
        // These void mutations can return an empty object on the legacy API.
        if ((array) $shape === [] && in_array($procedure, [
            'domains.createDomain',
            'projects.destroyProject',
            'services.app.deployService',
            'services.app.updateDeploy',
            'services.app.updateSourceImage',
            'services.app.updateSourceGit',
            'services.app.updateSourceGithub',
            'services.app.updateSourceDockerfile',
            'services.app.updateEnv',
            'services.app.destroyService',
            'services.box.updateEnv',
            'services.box.updateScripts',
            'services.box.restartService',
            'services.box.destroyService',
        ], true)) {
            return $shape;
        }
        $result = $shape->result ?? null;
        foreach ([$shape, $result] as $envelope) {
            if (! $envelope instanceof \stdClass) continue;
            foreach (['error', 'errors'] as $key) {
                if (\property_exists($envelope, $key) && ! \in_array($envelope->{$key}, [null, '', [], false], true)) {
                    throw new \RuntimeException('EasyPanel API reported failure.');
                }
            }
            if (\property_exists($envelope, 'success') && $envelope->success !== true) {
                throw new \RuntimeException('EasyPanel API reported failure.');
            }
        }
        $direct = \property_exists($shape, 'json');
        $data = $result instanceof \stdClass ? ($result->data ?? null) : null;
        $nested = $data instanceof \stdClass && \property_exists($data, 'json');
        if ($direct === $nested) {
            throw new \RuntimeException('EasyPanel API returned an invalid response.');
        }
        $payload = $direct ? $shape->json : $data->json;
        if ($procedure === 'projects.listProjectsAndServices') {
            if (! $payload instanceof \stdClass || ! \is_array($payload->projects ?? null)
                || ! \is_array($payload->services ?? null)) {
                throw new \RuntimeException('EasyPanel returned an invalid project/service inventory.');
            }
            foreach ([...$payload->projects, ...$payload->services] as $entry) {
                if (! $entry instanceof \stdClass) {
                    throw new \RuntimeException('EasyPanel returned an invalid project/service inventory.');
                }
            }
        }
        if ($procedure === 'domains.listDomains') {
            if (! \is_array($payload)) {
                throw new \RuntimeException('EasyPanel returned an invalid domain inventory.');
            }
            foreach ($payload as $entry) {
                if (! $entry instanceof \stdClass) {
                    throw new \RuntimeException('EasyPanel returned an invalid domain inventory.');
                }
            }
        }
        if (\preg_match('/^services\.(app|box|mysql|mariadb|postgres|mongo|redis)\.inspectService$/D', $procedure) === 1
            && ! $payload instanceof \stdClass) {
            throw new \RuntimeException('EasyPanel returned an invalid service inspection.');
        }
        return \json_decode(\json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function databaseType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = match ($type) {
            'postgresql' => 'postgres',
            'mongodb' => 'mongo',
            default => $type,
        };
        if (! in_array($type, ['mysql', 'mariadb', 'postgres', 'mongo', 'redis'], true)) {
            throw new \InvalidArgumentException('Unsupported EasyPanel database type: '.$type);
        }

        return $type;
    }
}
