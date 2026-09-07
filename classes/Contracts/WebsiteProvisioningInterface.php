<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface WebsiteProvisioningInterface
{
    public function overview(bool $includeSizes = true): array;
    public function createSite(string $name, string $root = '', string $domain = ''): array;
    public function deleteSite(string $documentRoot): array;
}
