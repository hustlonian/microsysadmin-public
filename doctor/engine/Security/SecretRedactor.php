<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Security;

final class SecretRedactor
{
    private const SENSITIVE_KEY = '/(?:pass(?:word|wd)?|secret|token|api[_-]?key|private[_-]?key|authorization|cookie|session|credential|dsn|connection[_-]?string)/i';

    /** @var array<int, string> */
    private array $knownSecrets = [];

    public function register(string $secret): void
    {
        if (strlen($secret) >= 4 && !in_array($secret, $this->knownSecrets, true)) {
            $this->knownSecrets[] = $secret;
            usort($this->knownSecrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }
    }

    public function text(string $value): string
    {
        foreach ($this->knownSecrets as $secret) {
            $value = str_replace($secret, '[REDACTED]', $value);
        }
        $patterns = [
            '/-----BEGIN [^-]*(?:PRIVATE KEY|CERTIFICATE)-----.*?-----END [^-]*(?:PRIVATE KEY|CERTIFICATE)-----/s' => '[REDACTED PEM]',
            '/\b(Authorization\s*:\s*)([^\r\n]+)/i' => '$1[REDACTED]',
            '/\b(Cookie|Set-Cookie)\s*:\s*[^\r\n]+/i' => '$1: [REDACTED]',
            '/\b([A-Z0-9_]*(?:PASSWORD|PASSWD|SECRET|TOKEN|API_KEY|PRIVATE_KEY)[A-Z0-9_]*)\s*=\s*([^\s\r\n]+)/i' => '$1=[REDACTED]',
            '#\b([a-z][a-z0-9+.-]*://[^\s:/@]+):([^\s@/]+)@#i' => '$1:[REDACTED]@',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i' => '$1 [REDACTED]',
            '/\b(?:ghp|github_pat|glpat|sk_live|sk_test)_[A-Za-z0-9_\-]{12,}\b/' => '[REDACTED TOKEN]',
        ];
        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }

    public function value(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $key !== 'PASS' && preg_match(self::SENSITIVE_KEY, $key)) {
            return '[REDACTED]';
        }
        if (is_string($value)) {
            return $this->text($value);
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $clean[$childKey] = $this->value($childValue, is_string($childKey) ? $childKey : null);
            }
            return $clean;
        }
        if ($value instanceof \JsonSerializable) {
            return $this->value($value->jsonSerialize());
        }
        return $value;
    }

    /** @param array<int, string> $command */
    public function command(array $command): array
    {
        $safe = [];
        $redactNext = false;
        foreach ($command as $argument) {
            if ($redactNext) {
                $safe[] = '[REDACTED]';
                $redactNext = false;
                continue;
            }
            if (preg_match(self::SENSITIVE_KEY, $argument)) {
                if (str_contains($argument, '=')) {
                    [$name] = explode('=', $argument, 2);
                    $safe[] = $name . '=[REDACTED]';
                } elseif (preg_match('/^(--?(?:password|passwd|passphrase|secret|token|api[_-]?key|private[_-]?key|p))(.*)$/i', $argument, $match)) {
                    $safe[] = $match[1] . ($match[2] === '' ? '' : '[REDACTED]');
                    $redactNext = $match[2] === '';
                } else {
                    $safe[] = str_starts_with($argument, '-') ? '[REDACTED OPTION]' : '[REDACTED]';
                    $redactNext = false;
                }
                continue;
            }
            $safe[] = $this->text($argument);
        }
        return $safe;
    }
}
