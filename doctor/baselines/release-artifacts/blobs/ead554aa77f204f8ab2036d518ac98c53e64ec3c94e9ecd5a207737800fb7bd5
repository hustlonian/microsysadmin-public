<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\CheckStatus;

final readonly class CheckResult implements \JsonSerializable
{
    /** @param array<int, Evidence> $evidence */
    public function __construct(
        public CheckMetadata $metadata,
        public CheckStatus $status,
        public string $message,
        public array $evidence = [],
        public float $durationMs = 0.0,
        public string $checkedAt = '',
    ) {
    }

    public function failed(): bool
    {
        return in_array($this->status, [CheckStatus::FAIL, CheckStatus::ERROR], true);
    }

    public function jsonSerialize(): array
    {
        return [
            'metadata' => $this->metadata->toArray(),
            'status' => $this->status->value,
            'message' => $this->message,
            'evidence' => array_map(static fn (Evidence $item): array => $item->toArray(), $this->evidence),
            'duration_ms' => $this->durationMs,
            'checked_at' => $this->checkedAt !== '' ? $this->checkedAt : gmdate(DATE_ATOM),
        ];
    }
}
