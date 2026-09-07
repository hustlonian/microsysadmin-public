<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface ServiceControlDriverInterface
{
    public function name(): string;

    /**
     * @return array<int, string>
     */
    public function services(): array;

    /**
     * @return array<int, string>
     */
    public function actions(string $service): array;

    /**
     * @return array<string, mixed>
     */
    public function status(string $service): array;

    /**
     * @return array<string, mixed>
     */
    public function perform(string $service, string $action): array;
}
