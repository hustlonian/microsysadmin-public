<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\AgentClientInterface;

final class AgentManager
{
    private static ?AgentClientInterface $client = null;

    public static function client(): AgentClientInterface
    {
        return self::$client ??= DemoMode::enabled()
            ? new InProcessAgentClient(new DemoMachineAgent())
            : new InProcessAgentClient();
    }

    public static function useClient(AgentClientInterface $client): void
    {
        self::$client = $client;
    }
}
