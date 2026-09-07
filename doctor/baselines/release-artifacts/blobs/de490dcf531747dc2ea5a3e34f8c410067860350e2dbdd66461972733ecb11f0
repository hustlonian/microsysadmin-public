<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\sdk\Support;

final class DotenvDocument
{
    /** @return array<int, array{index:int, value:string, prefix:string, suffix:string}> */
    public static function assignments(string $contents, string $key): array
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $key)) {
            throw new \InvalidArgumentException('Dotenv key is outside the supported syntax.');
        }
        $parts = preg_split('/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            throw new \RuntimeException('Unable to parse the dotenv document.');
        }
        $pattern = '/^(?<prefix>[ \t]*(?:export[ \t]+)?' . preg_quote($key, '/') . '[ \t]*=[ \t]*)(?<raw>"[^"\r\n]*"|\'[^\'\r\n]*\'|[^#\r\n]*?)(?<suffix>[ \t]*(?:#.*)?)$/';
        $assignments = [];
        foreach ($parts as $index => $part) {
            if (($index % 2) === 1 || preg_match($pattern, $part, $matches) !== 1) {
                continue;
            }
            $raw = trim((string) $matches['raw']);
            if (strlen($raw) >= 2 && (($raw[0] === '"' && $raw[-1] === '"') || ($raw[0] === "'" && $raw[-1] === "'"))) {
                $raw = substr($raw, 1, -1);
            }
            $assignments[] = [
                'index' => $index,
                'value' => trim($raw),
                'prefix' => (string) $matches['prefix'],
                'suffix' => (string) $matches['suffix'],
            ];
        }
        return $assignments;
    }

    public static function replaceSingle(string $contents, string $key, string $value): string
    {
        if (str_contains($value, "\0") || preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException('Dotenv replacement value must be one line.');
        }
        $assignments = self::assignments($contents, $key);
        if (count($assignments) !== 1) {
            throw new \RuntimeException('Dotenv repair requires exactly one unambiguous assignment.');
        }
        $parts = preg_split('/(\r\n|\n|\r)/', $contents, -1, PREG_SPLIT_DELIM_CAPTURE);
        $assignment = $assignments[0];
        $parts[$assignment['index']] = $assignment['prefix'] . $value . $assignment['suffix'];
        return implode('', $parts);
    }
}
