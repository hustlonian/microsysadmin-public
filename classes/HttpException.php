<?php

declare(strict_types=1);

namespace SysAdmin;

class HttpException extends \RuntimeException
{
    public function __construct(private readonly int $statusCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
