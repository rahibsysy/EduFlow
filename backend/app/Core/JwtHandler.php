<?php

declare(strict_types=1);

namespace App\Core;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtHandler
{
    public static function encode(array $payload): string
    {
        return JWT::encode($payload, JwtSecret::require(), 'HS256');
    }

    public static function decode(string $token): object
    {
        return JWT::decode($token, new Key(JwtSecret::require(), 'HS256'));
    }
}
