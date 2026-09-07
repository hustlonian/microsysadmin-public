<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support;

use SysAdmin\Doctor\engine\Context\DoctorContext;

final class TemplateResolver
{
    public function string(string $value, DoctorContext $context): string
    {
        $replacements = [
            '{APP_ROOT}' => APP_ROOT,
            '{STORAGE_PATH}' => STORAGE_PATH,
            '{PROJECT}' => $context->projectPath,
            '{EXECUTION_ID}' => $context->executionId,
            '{WEB_USER}' => (string) $context->option('web_user', ''),
        ];
        $value = strtr($value, $replacements);
        $value = (string) preg_replace_callback(
            '/\{COMMAND:([A-Za-z0-9_.-]+)\}/',
            static fn (array $match): string => (string) ($context->environment->command($match[1]) ?? ''),
            $value
        );
        return (string) preg_replace_callback(
            '/\{ENV:([A-Za-z0-9_.-]+)\}/',
            static fn (array $match): string => (string) $context->environment->get($match[1], ''),
            $value
        );
    }

    public function value(mixed $value, DoctorContext $context): mixed
    {
        if (is_string($value)) {
            return $this->string($value, $context);
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->value($item, $context), $value);
        }
        return $value;
    }
}
