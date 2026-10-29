<?php

declare(strict_types=1);

namespace App\Core;

class Request
{
    private static array $attributes = [];

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function uri(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function header(string $key): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, $key) === 0) {
                return $value;
            }
        }

        return null;
    }

    public static function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) ? $ip : null;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$attributes[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$attributes[$key] ?? $default;
    }

    public static function param(string $key, mixed $default = null): mixed
    {
        $params = self::get('route_params', []);
        return $params[$key] ?? $default;
    }

    public static function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }
}
