<?php

declare(strict_types=1);

namespace SysAdmin;

final class Validator
{
    public static function username(string $username): string
    {
        $username = trim($username);
        if (!preg_match('/\A[A-Za-z0-9_.-]{3,80}\z/', $username)) {
            throw new \InvalidArgumentException('Username must be 3-80 characters using letters, numbers, dots, dashes, or underscores.');
        }

        return $username;
    }

    public static function email(string $email, bool $required = false): string
    {
        $email = trim($email);
        if ($email === '' && !$required) {
            return '';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Email address is invalid.');
        }

        return $email;
    }

    public static function role(string $role): string
    {
        $role = match ($role) {
            'Admin' => 'Administrator',
            'Power User' => 'Operator',
            default => trim($role),
        };

        if (!in_array($role, ['Administrator', 'Operator', 'Viewer'], true)) {
            throw new \InvalidArgumentException('Role is invalid.');
        }

        return $role;
    }

    public static function password(string $password): void
    {
        $min = (int) config_value('security.password_min_length', 12);
        $errors = [];
        if (strlen($password) < $min) {
            $errors[] = "at least {$min} characters";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'one lowercase letter';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'one uppercase letter';
        }
        if (!preg_match('/\d/', $password)) {
            $errors[] = 'one number';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'one symbol';
        }
        if (in_array(strtolower($password), ['admin123', 'password', 'password123', 'microsysadmin', 'sysadmin'], true)) {
            $errors[] = 'not a common default password';
        }

        if ($errors) {
            throw new \InvalidArgumentException('Password must include ' . implode(', ', $errors) . '.');
        }
    }

    public static function identifier(string $identifier, string $label = 'identifier'): string
    {
        $identifier = trim($identifier);
        if (!preg_match('/\A[A-Za-z0-9_]+\z/', $identifier)) {
            throw new \InvalidArgumentException('Invalid ' . $label . '.');
        }

        return $identifier;
    }

    public static function id(mixed $id, string $label = 'id'): int
    {
        if (is_string($id) && !ctype_digit($id)) {
            throw new \InvalidArgumentException('Invalid ' . $label . '.');
        }
        $value = (int) $id;
        if ($value < 1) {
            throw new \InvalidArgumentException('Invalid ' . $label . '.');
        }

        return $value;
    }

    public static function filename(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        $name = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $name) ?? '';
        $name = trim($name, " .-\t\n\r\0\x0B");
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid file name.');
        }
        if (strlen($name) > 180) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $base = substr(pathinfo($name, PATHINFO_FILENAME), 0, 150);
            $name = $extension !== '' ? $base . '.' . $extension : $base;
        }

        return $name;
    }

    public static function hostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        if ($hostname === '') {
            return '';
        }
        if (!preg_match('/\A(?=.{1,253}\z)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $hostname)) {
            throw new \InvalidArgumentException('Hostname is invalid.');
        }

        return $hostname;
    }
}
