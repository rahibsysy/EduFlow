<?php

declare(strict_types=1);

namespace App\Services;

final class InitialSetupAccessPolicy
{
    private const MINIMUM_SECRET_BYTES = 32;

    public static function allows(?string $providedSecret, ?string $clientIp): bool
    {
        if (!filter_var($_ENV['INITIAL_SETUP_ENABLED'] ?? false, FILTER_VALIDATE_BOOL)) {
            return false;
        }

        $environment = strtolower(trim((string)($_ENV['APP_ENV'] ?? 'production')));
        if (!in_array($environment, ['local', 'production'], true)) {
            return false;
        }

        $configuredSecret = $_ENV['INITIAL_SETUP_SECRET'] ?? null;
        if (!self::isStrongSecret($configuredSecret)
            || !is_string($providedSecret)
            || !hash_equals($configuredSecret, $providedSecret)) {
            return false;
        }

        if ($environment === 'production') {
            return self::isProductionClientAllowed($clientIp, (string)($_ENV['INITIAL_SETUP_ALLOWED_IPS'] ?? ''));
        }

        return true;
    }

    public static function unavailableResponse(): array
    {
        return ['error' => 'Initial setup is unavailable.', 'status' => 404];
    }

    private static function isStrongSecret(mixed $secret): bool
    {
        if (!is_string($secret) || trim($secret) === '') {
            return false;
        }

        $decoded = base64_decode($secret, true);
        return $decoded !== false && strlen($decoded) >= self::MINIMUM_SECRET_BYTES;
    }

    private static function isProductionClientAllowed(?string $clientIp, string $configuredRanges): bool
    {
        if (!is_string($clientIp) || filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ranges = array_filter(array_map('trim', explode(',', $configuredRanges)));
        foreach ($ranges as $range) {
            if (self::ipMatchesRange($clientIp, $range)) {
                return true;
            }
        }

        return false;
    }

    private static function ipMatchesRange(string $ip, string $range): bool
    {
        [$network, $prefix] = array_pad(explode('/', $range, 2), 2, null);
        $ipBytes = inet_pton($ip);
        $networkBytes = inet_pton($network);
        if ($ipBytes === false || $networkBytes === false || strlen($ipBytes) !== strlen($networkBytes)) {
            return false;
        }

        if ($prefix === null) {
            return hash_equals($networkBytes, $ipBytes);
        }

        if (!ctype_digit($prefix)) {
            return false;
        }

        $prefixLength = (int)$prefix;
        $maximumBits = strlen($ipBytes) * 8;
        if ($prefixLength < 0 || $prefixLength > $maximumBits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;
        if ($fullBytes > 0 && !hash_equals(substr($networkBytes, 0, $fullBytes), substr($ipBytes, 0, $fullBytes))) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($networkBytes[$fullBytes]) & $mask) === (ord($ipBytes[$fullBytes]) & $mask);
    }
}
