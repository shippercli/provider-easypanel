<?php

declare(strict_types=1);

namespace ShipperCli\ProviderEasyPanel;

use ShipperCli\Contracts\DeploymentProviderInterface;

final class EasyPanelProvider implements DeploymentProviderInterface
{
    /** @var array<string, mixed> */
    private readonly array $config;

    private string $lastError = '';

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getName(): string
    {
        return 'easypanel';
    }

    public function validate(object $project, object $profile): array
    {
        $errors = [];

        $url = $this->config['url'] ?? null;
        if (! \is_string($url) || $url === '') {
            $errors[] = 'EasyPanel URL is required';
        }

        $token = $this->config['auth_token'] ?? null;
        if (! \is_string($token) || $token === '') {
            $errors[] = 'EasyPanel auth token is required';
        }

        return $errors;
    }

    public function plan(object $project, object $profile): array
    {
        return [
            'provider' => $this->getName(),
            'project' => \method_exists($project, 'name') ? $project->name() : 'unknown',
            'profile' => \method_exists($profile, 'name') ? $profile->name() : 'unknown',
            'note' => 'EasyPanel provider is configured as an external package in this installation.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        return true;
    }

    public function destroy(object $project, object $profile): bool
    {
        return true;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }
}
