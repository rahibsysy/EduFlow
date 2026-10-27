<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Core\JwtHandler;
use App\Core\JwtSecret;
use Firebase\JWT\JWT;

function expectFailure(callable $action, string $label): void
{
    try {
        $action();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException("Expected failure: {$label}");
}

function testSecret(): string
{
    return base64_encode(random_bytes(48));
}

expectFailure(static fn() => JwtSecret::validate(null), 'missing secret');
expectFailure(static fn() => JwtSecret::validate(''), 'empty secret');
expectFailure(static fn() => JwtSecret::validate('short'), 'non-base64 or short secret');
$reflection = new ReflectionClass(JwtSecret::class);
$rejectedHashes = $reflection->getReflectionConstant('REJECTED_SECRET_HASHES')?->getValue();
if (!is_array($rejectedHashes) || count($rejectedHashes) === 0) {
    throw new RuntimeException('Legacy JWT secret denylist is missing');
}

$secret = testSecret();
if (JwtSecret::validate($secret) !== $secret) {
    throw new RuntimeException('Valid secret was not accepted');
}

$_ENV['JWT_SECRET'] = $secret;
$payload = ['id' => 7, 'iat' => time(), 'exp' => time() + 60];
$token = JwtHandler::encode($payload);
if ((int) JwtHandler::decode($token)->id !== 7) {
    throw new RuntimeException('Valid JWT was not accepted');
}

$oldSecret = testSecret();
$oldToken = JWT::encode($payload, $oldSecret, 'HS256');
expectFailure(static fn() => JwtHandler::decode($oldToken), 'JWT signed with old secret');

$segments = explode('.', $token);
$segments[1] = substr($segments[1], 0, -1) . (substr($segments[1], -1) === 'a' ? 'b' : 'a');
expectFailure(static fn() => JwtHandler::decode(implode('.', $segments)), 'altered JWT');

$expired = JWT::encode(['id' => 7, 'iat' => time() - 120, 'exp' => time() - 60], $secret, 'HS256');
expectFailure(static fn() => JwtHandler::decode($expired), 'expired JWT');

$unexpectedAlgorithm = JWT::encode($payload, $secret, 'HS384');
expectFailure(static fn() => JwtHandler::decode($unexpectedAlgorithm), 'unexpected JWT algorithm');

echo "PASS: JWT secret and token security checks\n";
