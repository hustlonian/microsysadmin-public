<?php

declare(strict_types=1);

namespace SysAdmin;

final class OrganizationAuthorizer
{
    public static function requireOrganization(int $organizationId): void
    {
        $current = CommercialContext::organizationId();
        if ($current === null || $organizationId !== $current) {
            AuditLogger::warning('organization.access_denied', [
                'requested_organization_id' => $organizationId,
            ]);
            throw new HttpException(403, 'Organization access denied.');
        }
    }

    public static function scopeClause(string $column = 'organization_id'): array
    {
        $organizationId = CommercialContext::organizationId();
        if ($organizationId === null) {
            throw new HttpException(403, 'No organization context is available.');
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) {
            throw new \InvalidArgumentException('Invalid organization scope column.');
        }
        return ["{$column} = ?", [$organizationId]];
    }
}
