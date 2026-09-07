<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Logging;

use SysAdmin\Doctor\engine\Security\SecretRedactor;
use SysAdmin\Doctor\engine\Support\Value\AuditEvent;

final readonly class DoctorAuditLogger
{
    public function __construct(
        private DoctorRepository $repository,
        private SecretRedactor $redactor,
        private string $executionId,
    ) {
    }

    public function event(string $type, string $message, array $context = [], string $level = 'INFO'): AuditEvent
    {
        $safeMessage = $this->redactor->text($message);
        $safeContext = (array) $this->redactor->value($context);
        $event = new AuditEvent([
            'execution_id' => $this->executionId,
            'level' => strtoupper($level),
            'event_type' => $type,
            'message' => $safeMessage,
            'context' => $safeContext,
            'created_at' => gmdate(DATE_ATOM),
        ]);
        $this->repository->audit($this->executionId, strtoupper($level), $type, $safeMessage, $safeContext);
        return $event;
    }
}
