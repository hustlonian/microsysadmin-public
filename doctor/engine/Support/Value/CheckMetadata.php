<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class CheckMetadata extends ArrayValue
{
    public function __construct(array $data)
    {
        foreach (['check_id', 'module', 'name', 'summary', 'severity', 'version'] as $key) {
            if (!isset($data[$key]) || trim((string) $data[$key]) === '') {
                throw new \InvalidArgumentException("Check metadata is missing {$key}.");
            }
        }
        parent::__construct($data);
    }

    public function id(): string { return (string) $this->get('check_id'); }
    public function module(): string { return (string) $this->get('module'); }
}
