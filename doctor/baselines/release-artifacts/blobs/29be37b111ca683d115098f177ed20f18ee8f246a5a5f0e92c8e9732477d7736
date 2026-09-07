<?php

declare(strict_types=1);

namespace SysAdmin;

final class RateLimitException extends HttpException
{
    public function __construct(string $message = 'Too many requests. Try again later.')
    {
        parent::__construct(429, $message);
    }
}
