<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\LicensingServiceInterface;

final class UpdateEntitlementPolicy
{
    public function __construct(
        private readonly LicensingServiceInterface $licensing,
        private readonly bool $developmentMode = false
    ) {
    }

    public function allows(string $releaseDate): bool
    {
        if ($this->developmentMode) {
            return true;
        }
        $value = $this->licensing->entitlement('updates.until', false);
        if ($value === 'unlimited' || $value === 'community') {
            return true;
        }
        $entitledUntil = is_string($value) ? strtotime($value) : false;
        $releasedAt = strtotime($releaseDate);
        return $entitledUntil !== false && $releasedAt !== false && $releasedAt <= $entitledUntil;
    }

    public function assert(string $releaseDate, string $targetVersion): void
    {
        if (!$this->allows($releaseDate)) {
            throw new HttpException(
                403,
                "The installed version may continue to run, but the update entitlement does not permit {$targetVersion}."
            );
        }
    }
}
