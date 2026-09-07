<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Support;

final class PathGuard
{
    public static function relativeLinkStaysWithin(string $relativeLink, string $target): bool
    {
        if ($target === '' || str_starts_with($target, '/') || str_contains($target, "\0")) {
            return false;
        }
        return self::collapse(dirname($relativeLink) . '/' . str_replace('\\', '/', $target)) !== null;
    }

    public static function linkTargetStaysWithin(string $root, string $link, string $target): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($root === '' || $target === '' || str_contains($target, "\0")) {
            return false;
        }
        if (str_starts_with($target, '/')) {
            $collapsed = self::collapse(ltrim(str_replace('\\', '/', $target), '/'));
            $normalized = $collapsed === null ? null : '/' . $collapsed;
            return $normalized === $root || ($normalized !== null && str_starts_with($normalized, $root . DIRECTORY_SEPARATOR));
        }
        $relativeLink = ltrim(str_replace('\\', '/', substr($link, strlen($root))), '/');
        return self::relativeLinkStaysWithin($relativeLink, $target);
    }

    private static function collapse(string $path): ?string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $stack = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($stack === []) {
                    return null;
                }
                array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }
        return $stack === [] ? null : implode('/', $stack);
    }
}
