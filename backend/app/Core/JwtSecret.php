<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class JwtSecret
{
    private const MINIMUM_BYTES = 32;

    /**
     * SHA-256 fingerprints of legacy/example secrets that must never be used.
     * The literal values are intentionally not retained in source code.
     */
    private const REJECTED_SECRET_HASHES = [
        'a6955c3f5f8600121a9916dffeca375cf12de7b6abfbdcef9563c74f11f54c61',
    ];

    public static function require(): string
    {
        return self::validate($_ENV['JWT_SECRET'] ?? null);
    }

    /**
     * JWT secrets must be Base64 encodings of at least 32 cryptographically
     * random bytes. Base64 keeps environment configuration portable.
     */
    public static function validate(mixed $secret): string
    {
        if (!is_string($secret) || trim($secret) === '') {
            throw new RuntimeException('JWT configuration is invalid.');
        }

        if (in_array(hash('sha256', $secret), self::REJECTED_SECRET_HASHES, true)) {
            throw new RuntimeException('JWT configuration is invalid.');
        }

        $decoded = base64_decode($secret, true);
        if ($decoded === false || strlen($decoded) < self::MINIMUM_BYTES) {
            throw new RuntimeException('JWT configuration is invalid.');
        }

        return $secret;
    }
}
