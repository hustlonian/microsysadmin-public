<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk;

/** A failed action retains its captured compensation state instead of losing it in an exception. */
final class PartialActionFailure extends \RuntimeException
{
    public function __construct(string $message, public readonly array $change, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
