<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface ComposerOperationsInterface
{
    public function runComposer(string $project, string $command): array;
}
