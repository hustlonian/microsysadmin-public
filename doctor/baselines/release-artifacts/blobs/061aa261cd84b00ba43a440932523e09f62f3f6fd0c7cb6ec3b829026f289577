<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Contracts\AgentClientInterface;
use SysAdmin\Contracts\MachineAgentInterface;

final class InProcessAgentClient implements AgentClientInterface
{
    public function __construct(private readonly MachineAgentInterface $agent = new LocalMachineAgent())
    {
    }

    public function request(string $action, array $payload = []): array
    {
        return $this->agent->execute($action, $payload, [
            'transport' => 'in-process',
            'request_id' => RequestContext::requestId(),
            'audit_correlation_id' => RequestContext::auditCorrelationId(),
            'user_id' => Auth::id(),
        ]);
    }
}
