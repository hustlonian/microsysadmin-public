<?php

declare(strict_types=1);

namespace SysAdmin;

final class PathGuard
{
    private const RESERVED_METADATA_NAMES = ['.agents', '.git'];

    public static function allowedRoots(): array
    {
        $roots = [];
        foreach (EnvironmentConfig::paths('allowed_filesystem_roots') as $root) {
            $real = realpath((string) $root);
            if ($real && is_dir($real)) {
                $roots[] = rtrim($real, DIRECTORY_SEPARATOR);
            }
        }

        return array_values(array_unique($roots));
    }

    public static function defaultRoot(): string
    {
        $configured = EnvironmentConfig::paths('document_roots')[0] ?? config_value('paths.default_file_root', APP_ROOT);
        $root = realpath((string) $configured);
        if (!$root) {
            $root = APP_ROOT;
        }

        return self::assertAllowedDirectory($root);
    }

    public static function storageScopeRoot(): string
    {
        $configured = EnvironmentConfig::paths('document_roots')[0] ?? APP_ROOT;
        $root = realpath((string) $configured);
        if (!$root) {
            $root = APP_ROOT;
        }

        return self::assertAllowedDirectory($root);
    }

    public static function assertAllowed(string $path): string
    {
        $real = realpath($path);
        if (!$real) {
            $parent = realpath(dirname($path));
            if (!$parent) {
                throw new \InvalidArgumentException('Path does not exist and parent is invalid.');
            }
            $real = $parent . DIRECTORY_SEPARATOR . basename($path);
        }

        foreach (self::allowedRoots() as $root) {
            if ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        throw new \InvalidArgumentException('Path is outside configured root paths.');
    }

    public static function assertAllowedDirectory(string $path): string
    {
        $real = self::assertAllowed($path);
        if (!is_dir($real)) {
            throw new \InvalidArgumentException('Path is not a directory.');
        }

        return $real;
    }

    public static function safeName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid file name.');
        }
        self::assertNotReservedMetadataName($name);

        return $name;
    }

    public static function isReservedMetadataName(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::RESERVED_METADATA_NAMES, true);
    }

    public static function assertNotReservedMetadataName(string $name): void
    {
        if (self::isReservedMetadataName($name)) {
            throw new \InvalidArgumentException('.agents and .git are reserved metadata folders and cannot be created here.');
        }
    }
}
