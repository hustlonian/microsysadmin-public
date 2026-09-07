<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface ArtisanOperationsInterface
{
    public function runArtisan(string $project, string $command, bool $allowAny = false): array;
}
