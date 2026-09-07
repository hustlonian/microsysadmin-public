<?php

declare(strict_types=1);

namespace SysAdmin;

final class CommercialContext
{
    private static ?int $organizationId = null;
    private static ?int $machineId = null;

    public static function organizationId(): ?int
    {
        if (self::$organizationId !== null) {
            return self::$organizationId;
        }

        try {
            $userId = Auth::id();
            if ($userId) {
                $statement = Database::pdo()->prepare(
                    "SELECT organization_id FROM organization_members
                     WHERE user_id = ? AND status = 'active'
                     ORDER BY id LIMIT 1"
                );
                $statement->execute([$userId]);
                $id = (int) $statement->fetchColumn();
                if ($id > 0) {
                    return self::$organizationId = $id;
                }
            }

            $id = (int) Database::pdo()->query(
                "SELECT id FROM organizations WHERE status = 'active' ORDER BY id LIMIT 1"
            )->fetchColumn();
            return $id > 0 ? self::$organizationId = $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function machineId(): ?int
    {
        if (self::$machineId !== null) {
            return self::$machineId;
        }
        try {
            $identity = (new MachineIdentity())->get();
            $statement = Database::pdo()->prepare('SELECT id FROM machines WHERE machine_uuid = ? LIMIT 1');
            $statement->execute([(string) $identity['machine_uuid']]);
            $id = (int) $statement->fetchColumn();
            return $id > 0 ? self::$machineId = $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
