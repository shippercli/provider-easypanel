<?php

declare(strict_types=1);

namespace ShipperCli\Contracts;

interface DeploymentProviderInterface
{
    /** @return array<int, string> */
    public function validate(object $project, object $profile): array;

    /** @return array<string, mixed> */
    public function plan(object $project, object $profile): array;

    public function apply(object $project, object $profile): bool;

    public function destroy(object $project, object $profile): bool;

    public function getName(): string;

    public function getLastError(): string;
}
