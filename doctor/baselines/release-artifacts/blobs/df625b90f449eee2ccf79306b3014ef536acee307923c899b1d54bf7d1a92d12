<?php

declare(strict_types=1);

function config_value(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS['sysadmin_config'] ?? [];
    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function app_debug(): bool
{
    return (bool) config_value('app.debug', false);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    return rtrim((string) config_value('app.base_url', '/microsysadmin'), '/');
}

function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return base_url() . ($path === '/' ? '' : $path);
}

function asset_url(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function is_json_request(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($accept, 'application/json')
        || str_starts_with($uri, base_url() . '/api/')
        || str_starts_with($uri, base_url() . '/agent/v1/')
        || str_starts_with($uri, base_url() . '/api/v1/');
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    if (class_exists(\SysAdmin\Security::class)) {
        \SysAdmin\Security::sendHeaders();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect_to(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function human_bytes(int|float $bytes, int $precision = 1): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $bytes = max(0, (float) $bytes);
    $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;
    $value = $bytes / (1024 ** $power);
    return round($value, $precision) . ' ' . $units[$power];
}

function elapsed(float $seconds): string
{
    $seconds = (int) max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return "{$days}d {$hours}h";
    }
    if ($hours > 0) {
        return "{$hours}h {$minutes}m";
    }
    return "{$minutes}m";
}

function request_input(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function current_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_url();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }

    return trim($path, '/');
}
