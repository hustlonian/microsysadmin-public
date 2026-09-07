<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface LicensingServiceInterface
{
    public function status(): array;
    public function entitlement(string $key, mixed $default = null): mixed;
    public function allows(string $key): bool;
    public function assert(string $key): void;
}
