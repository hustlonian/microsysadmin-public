<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Execution;

final class ExecutionLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(int $waitSeconds = 2): void
    {
        $this->handle = fopen($this->path, 'c+');
        if (!is_resource($this->handle)) {
            throw new \RuntimeException('Unable to open Doctor execution lock.');
        }
        $deadline = microtime(true) + max(0, $waitSeconds);
        do {
            if (flock($this->handle, LOCK_EX | LOCK_NB)) {
                ftruncate($this->handle, 0);
                fwrite($this->handle, (string) getmypid());
                fflush($this->handle);
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);
        fclose($this->handle);
        $this->handle = null;
        throw new \RuntimeException('Another Doctor repair execution is already active.');
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
