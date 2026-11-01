<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Filesystem;

final class SafeFilesystem
{
    /** @var array<int, string> */
    private array $roots = [];
    /** @var array<int, string> */
    private array $readRoots = [];

    public function __construct(array $allowedRoots, array $readOnlyRoots = [])
    {
        foreach ($allowedRoots as $root) {
            $real = realpath((string) $root);
            if ($real !== false && is_dir($real)) {
                $this->roots[] = rtrim($real, DIRECTORY_SEPARATOR);
            }
        }
        $this->roots = array_values(array_unique($this->roots));
        $this->readRoots = $this->roots;
        foreach ($readOnlyRoots as $root) {
            $real = realpath((string) $root);
            if ($real !== false && is_dir($real)) {
                $this->readRoots[] = rtrim($real, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
            }
        }
        $this->readRoots = array_values(array_unique($this->readRoots));
        if ($this->roots === []) {
            throw new \RuntimeException('Doctor filesystem has no valid allowed roots.');
        }
    }

    public function normalize(string $path, bool $mustExist = true, bool $forWrite = false): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid filesystem path.');
        }
        $real = false;
        if (is_link($path)) {
            $parent = realpath(dirname($path));
            if ($parent !== false) {
                $real = $parent . DIRECTORY_SEPARATOR . basename($path);
            }
        } else {
            $real = realpath($path);
        }
        if ($real === false) {
            if ($mustExist) {
                throw new \InvalidArgumentException('Filesystem path does not exist.');
            }
            $parent = realpath(dirname($path));
            if ($parent === false || is_link($parent)) {
                throw new \InvalidArgumentException('Filesystem parent is unavailable or symbolic.');
            }
            $real = $parent . DIRECTORY_SEPARATOR . basename($path);
        }
        foreach ($forWrite ? $this->roots : $this->readRoots as $root) {
            if ($root === DIRECTORY_SEPARATOR || $real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        throw new \InvalidArgumentException('Filesystem path is outside Doctor allowed roots.');
    }

    public function read(string $path): string
    {
        $path = $this->normalize($path);
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new \RuntimeException('Doctor cannot safely read the requested file.');
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Unable to read file.');
        }
        return $content;
    }

    public function atomicWrite(string $path, string $contents, ?int $mode = null): void
    {
        $path = $this->normalize($path, false, true);
        $parent = dirname($path);
        if (!is_dir($parent) || is_link($parent)) {
            throw new \RuntimeException('Atomic-write directory is unavailable or symbolic.');
        }
        $temporary = $parent . DIRECTORY_SEPARATOR . '.' . basename($path) . '.doctor-' . bin2hex(random_bytes(6));
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create atomic-write file.');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Unable to complete atomic write.');
            }
            $targetMode = $mode ?? (is_file($path) ? (fileperms($path) & 0777) : 0640);
            chmod($temporary, $targetMode);
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Unable to install atomic-write file.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function createDirectory(string $path, int $mode = 0775): bool
    {
        $path = $this->normalize($path, false, true);
        if (is_dir($path)) {
            return false;
        }
        if (file_exists($path) || $mode < 0700 || $mode > 0775) {
            throw new \RuntimeException('Unsafe directory creation request.');
        }
        if (!mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create directory.');
        }
        // mkdir is filtered by the process umask. A repair contract declares
        // an exact safe mode, so normalize the newly created target and prove
        // it before returning it to the rollback journal.
        if (!chmod($path, $mode) || (fileperms($path) & 0777) !== $mode) {
            @rmdir($path);
            throw new \RuntimeException('Unable to apply the exact directory mode.');
        }
        return true;
    }

    public function createSymlink(string $target, string $link): bool
    {
        $target = $this->normalize($target);
        $link = $this->normalize($link, false, true);
        if (is_link($link)) {
            return realpath($link) === $target;
        }
        if (file_exists($link)) {
            throw new \RuntimeException('Symlink destination already exists.');
        }
        if (!symlink($target, $link)) {
            throw new \RuntimeException('Unable to create symbolic link.');
        }
        return true;
    }

    public function metadata(string $path): array
    {
        // Metadata collection is read-only and is also used when capturing a
        // backup of a privileged-helper target. Mutation methods perform their
        // own write-root validation before calling this method.
        $path = $this->normalize($path, true, false);
        $stat = lstat($path);
        if ($stat === false) {
            throw new \RuntimeException('Unable to inspect filesystem metadata.');
        }
        return [
            'path' => $path,
            'type' => is_link($path) ? 'link' : (is_dir($path) ? 'directory' : 'file'),
            'mode' => $stat['mode'] & 07777,
            'uid' => $stat['uid'],
            'gid' => $stat['gid'],
            'link_target' => is_link($path) ? readlink($path) : null,
            'sha256' => is_file($path) && !is_link($path) ? hash_file('sha256', $path) : null,
        ];
    }

    public function chmod(string $path, int $mode): array
    {
        $path = $this->normalize($path, true, true);
        if ($mode < 0600 || $mode > 0775 || ($mode & 0002) !== 0) {
            throw new \InvalidArgumentException('Doctor refuses unsafe permission mode.');
        }
        $before = $this->metadata($path);
        if (!chmod($path, $mode)) {
            throw new \RuntimeException('Unable to change scoped permissions.');
        }
        return ['before' => $before, 'after' => $this->metadata($path)];
    }

    public function removeEmptyDirectory(string $path): void
    {
        $path = $this->normalize($path, true, true);
        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        if (!is_dir($path) || is_link($path) || $entries !== []) {
            throw new \RuntimeException('Rollback can remove only an empty, non-symbolic directory.');
        }
        if (!rmdir($path)) {
            throw new \RuntimeException('Unable to remove rollback directory.');
        }
    }

    public function removeSymlink(string $path): void
    {
        $path = $this->normalize($path, true, true);
        if (!is_link($path)) {
            throw new \RuntimeException('Rollback target is not a symbolic link.');
        }
        if (!unlink($path)) {
            throw new \RuntimeException('Unable to remove rollback symbolic link.');
        }
    }

    public function removeFile(string $path, string $expectedSha256): void
    {
        $path = $this->normalize($path, true, true);
        if (!is_file($path) || is_link($path) || !preg_match('/^[a-f0-9]{64}$/', $expectedSha256)) {
            throw new \RuntimeException('Rollback target is not an exact regular file.');
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expectedSha256, $actual)) {
            throw new \RuntimeException('Rollback refuses to remove a file that changed after repair.');
        }
        if (!unlink($path)) {
            throw new \RuntimeException('Unable to remove rollback-created file.');
        }
    }

    public function diff(string $before, string $after): string
    {
        if ($before === $after) {
            return '';
        }
        $old = preg_split('/\R/', $before) ?: [];
        $new = preg_split('/\R/', $after) ?: [];
        $lines = ['--- before', '+++ after'];
        foreach (array_diff($old, $new) as $line) {
            $lines[] = '-' . $line;
        }
        foreach (array_diff($new, $old) as $line) {
            $lines[] = '+' . $line;
        }
        return implode("\n", $lines);
    }
}
