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

    public function sanitize(array $value): array
    {
        foreach ($value as $key => &$item) {
            if (in_array(strtolower((string)$key), ['contents', 'diff'], true) && is_string($item)) {
                $item = ['redacted' => true, 'sha256' => hash('sha256', $item), 'bytes' => strlen($item)];
            } elseif (is_array($item)) { $item = $this->sanitize($item); }
        }
        unset($item);
        return (array)$this->redactor->value($value);
    }

    public function event(string $type, string $message, array $context = [], string $level = 'INFO'): AuditEvent
    {
        $safeMessage = $this->redactor->text($message);
        $safeContext = $this->sanitize($context);
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
