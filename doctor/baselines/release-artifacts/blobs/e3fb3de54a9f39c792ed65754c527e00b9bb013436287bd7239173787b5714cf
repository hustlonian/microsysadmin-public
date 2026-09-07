<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support\Value;

use SysAdmin\Doctor\engine\Support\ArrayValue;

final readonly class Evidence extends ArrayValue
{
    public static function observation(string $type, string $message, mixed $expected = null, mixed $actual = null, array $details = []): self
    {
        return new self([
            'type' => $type,
            'message' => $message,
            'expected' => $expected,
            'actual' => $actual,
            'details' => $details,
            'timestamp' => gmdate(DATE_ATOM),
        ]);
    }
}
