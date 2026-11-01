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
        if (is_resource($this->handle)) {
            throw new \RuntimeException('Doctor execution lock is already held by this instance.');
        }
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

    public function attempted(string $identity): bool
    {
        $directory = $this->path . '.journal';
        if (is_file($directory . '/' . hash('sha256', $identity) . '.json')) { return true; }
        $parts = json_decode($identity, true, 16, JSON_THROW_ON_ERROR);
        $resource = hash('sha256', json_encode([$parts[1], $parts[2]], JSON_THROW_ON_ERROR));
        foreach (glob($directory . '/*.json') ?: [] as $saved) {
            $previous = json_decode((string)file_get_contents($saved), true, 64, JSON_THROW_ON_ERROR);
            if (($previous['resource'] ?? null) === $resource && (($previous['status'] ?? '') === 'STARTED_RECONCILE_BEFORE_RETRY' || !empty($previous['repair']['recovery_required']))) { return true; }
        }
        return false;
    }

    /** Durable identity survives a new controller or a crashed worker. Never retry an unknown effect. */
    public function claim(string $identity): bool
    {
        if (!is_resource($this->handle)) { throw new \RuntimeException('Execution lease is required.'); }
        $directory = $this->path . '.journal';
        if (!is_dir($directory) && !mkdir($directory, 0700)) { throw new \RuntimeException('Cannot create repair journal.'); }
        $this->syncDirectory(dirname($directory));
        if ($this->attempted($identity)) { return false; }
        $parts = json_decode($identity, true, 16, JSON_THROW_ON_ERROR);
        $resource = hash('sha256', json_encode([$parts[1], $parts[2]], JSON_THROW_ON_ERROR));
        $path = $directory . '/' . hash('sha256', $identity) . '.json';
        $file = fopen($path, 'x');
        if ($file === false) { throw new \RuntimeException('Cannot claim repair execution.'); }
        try {
            chmod($path, 0600);
            $data = json_encode(['status' => 'STARTED_RECONCILE_BEFORE_RETRY', 'resource' => $resource, 'at' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR);
            if (fwrite($file, $data) !== strlen($data) || !fflush($file) || !fsync($file)) { throw new \RuntimeException('Cannot persist repair intent.'); }
        } finally { fclose($file); }
        $this->syncDirectory($directory);
        return true;
    }

    public function complete(string $identity, array $sanitizedResult): void
    {
        $path = $this->path . '.journal/' . hash('sha256', $identity) . '.json';
        $parts = json_decode($identity, true, 16, JSON_THROW_ON_ERROR);
        $sanitizedResult['resource'] = hash('sha256', json_encode([$parts[1], $parts[2]], JSON_THROW_ON_ERROR));
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $file = fopen($temporary, 'x');
        if ($file === false) { throw new \RuntimeException('Cannot save repair outcome.'); }
        try {
            chmod($temporary, 0600);
            $data = json_encode($sanitizedResult, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (fwrite($file, $data) !== strlen($data) || !fflush($file) || !fsync($file) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot persist repair outcome; reconcile the original intent.');
            }
        } finally { fclose($file); }
        $this->syncDirectory(dirname($path));
    }

    private function syncDirectory(string $directory): void
    {
        $handle = fopen($directory, 'r');
        if ($handle === false) { throw new \RuntimeException('Cannot open repair journal directory for durability.'); }
        try { if (!fsync($handle)) { throw new \RuntimeException('Cannot persist repair journal directory.'); } }
        finally { fclose($handle); }
    }

    public function __destruct()
    {
        $this->release();
    }
}
