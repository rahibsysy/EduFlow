<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\InitialSetupAccessPolicy;
use App\Services\InitialSetupService;

function setupAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function setupSecret(): string
{
    return base64_encode(random_bytes(48));
}

function setupEnvironment(array $values): void
{
    foreach (['APP_ENV', 'INITIAL_SETUP_ENABLED', 'INITIAL_SETUP_SECRET', 'INITIAL_SETUP_ALLOWED_IPS'] as $key) {
        unset($_ENV[$key]);
    }
    foreach ($values as $key => $value) {
        $_ENV[$key] = $value;
    }
}

setupEnvironment([]);
setupAssert(!InitialSetupAccessPolicy::allows(null, '127.0.0.1'), 'Setup must be disabled by default');

$secret = setupSecret();
setupEnvironment(['APP_ENV' => 'local', 'INITIAL_SETUP_ENABLED' => 'true', 'INITIAL_SETUP_SECRET' => $secret]);
setupAssert(!InitialSetupAccessPolicy::allows(null, '127.0.0.1'), 'Missing secret must be rejected');
setupAssert(!InitialSetupAccessPolicy::allows(setupSecret(), '127.0.0.1'), 'Invalid secret must be rejected');
setupAssert(InitialSetupAccessPolicy::allows($secret, '127.0.0.1'), 'Enabled local setup with valid secret must be allowed');
setupEnvironment(['APP_ENV' => 'local', 'INITIAL_SETUP_ENABLED' => 'true', 'INITIAL_SETUP_SECRET' => base64_encode('short')]);
setupAssert(!InitialSetupAccessPolicy::allows(base64_encode('short'), '127.0.0.1'), 'Weak configured secret must be rejected');

setupEnvironment(['APP_ENV' => 'production', 'INITIAL_SETUP_ENABLED' => 'true', 'INITIAL_SETUP_SECRET' => $secret]);
setupAssert(!InitialSetupAccessPolicy::allows($secret, '127.0.0.1'), 'Production setup requires an IP restriction');
setupEnvironment(['APP_ENV' => 'production', 'INITIAL_SETUP_ENABLED' => 'true', 'INITIAL_SETUP_SECRET' => $secret, 'INITIAL_SETUP_ALLOWED_IPS' => '127.0.0.1/32']);
setupAssert(InitialSetupAccessPolicy::allows($secret, '127.0.0.1'), 'Allowed production client must be accepted');
setupAssert(!InitialSetupAccessPolicy::allows($secret, '127.0.0.2'), 'Unexpected production client must be rejected');

$response = InitialSetupAccessPolicy::unavailableResponse();
setupAssert(($response['status'] ?? null) === 404 && !str_contains((string)($response['error'] ?? ''), $secret), 'Denied response must not disclose the secret');
setupAssert(InitialSetupService::canInstallWithCounts(0, 0), 'Empty installation must be allowed after authorization');
setupAssert(!InitialSetupService::canInstallWithCounts(1, 0) && !InitialSetupService::canInstallWithCounts(0, 1), 'Reinstallation must be rejected when an installation record exists');

echo "PASS: initial setup access policy checks\n";
