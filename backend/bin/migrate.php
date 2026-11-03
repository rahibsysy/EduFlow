<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$backendDirectory = dirname(__DIR__);
$storageDirectory = $backendDirectory . '/storage';
$command = $argv[1] ?? '--migrate';

if (!in_array($command, ['--status', '--bootstrap', '--migrate', '--adopt-baseline'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php bin/migrate.php [--status|--bootstrap|--migrate|--adopt-baseline]\n");
    exit(1);
}

if (!is_file($backendDirectory . '/vendor/autoload.php')) {
    fwrite(STDERR, "Backend dependencies are missing. Run composer install in backend.\n");
    exit(1);
}

require $backendDirectory . '/vendor/autoload.php';
Dotenv::createImmutable($backendDirectory)->safeLoad();
$databaseConfig = require $backendDirectory . '/config/database.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function loadManifest(string $storageDirectory): array
{
    $manifestPath = $storageDirectory . '/baseline-manifest.json';
    $raw = @file_get_contents($manifestPath);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest)) {
        fail('Baseline manifest is unreadable or invalid JSON.');
    }

    $baseline = $manifest['baseline'] ?? null;
    $active = $manifest['active_migrations'] ?? null;
    if (!is_array($baseline) || !is_array($active)
        || !is_string($baseline['id'] ?? null)
        || !is_string($baseline['version'] ?? null)
        || !is_string($baseline['state'] ?? null)
        || !is_string($baseline['path'] ?? null)
        || !is_string($baseline['sha256'] ?? null)
        || !is_string($active['path'] ?? null)
        || !is_string($manifest['baseline_includes_through'] ?? null)) {
        fail('Baseline manifest is missing required fields.');
    }

    $storageRoot = realpath($storageDirectory);
    $baselinePath = realpath($storageDirectory . '/' . $baseline['path']);
    $activePath = $storageDirectory . '/' . $active['path'];
    if ($storageRoot === false || $baselinePath === false || !str_starts_with($baselinePath, $storageRoot . DIRECTORY_SEPARATOR)) {
        fail('Baseline path must resolve inside backend/storage.');
    }
    if (!is_dir($activePath) || !preg_match('/^[a-f0-9]{64}$/', $baseline['sha256'])) {
        fail('Baseline manifest references an invalid active directory or checksum.');
    }

    $manifest['baseline']['absolute_path'] = $baselinePath;
    $manifest['active_migrations']['absolute_path'] = $activePath;
    $manifest['active_migrations']['extension'] = $active['extension'] ?? '.sql';
    return $manifest;
}

function connect(array $config): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['dbname']);
    try {
        return new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    } catch (Throwable $exception) {
        fail('Database connection failed: ' . $exception->getMessage());
    }
}

function scalar(PDO $pdo, string $sql, array $parameters = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}

function tableExists(PDO $pdo, string $table): bool
{
    return (int) scalar($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) > 0;
}

function tableCount(PDO $pdo): int
{
    return (int) scalar($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
}

function recordedBaseline(PDO $pdo, array $baseline): ?array
{
    if (!tableExists($pdo, 'schema_migrations')) {
        return null;
    }
    $statement = $pdo->prepare('SELECT version, checksum, execution_type FROM schema_migrations WHERE version = ?');
    $statement->execute([$baseline['version']]);
    return $statement->fetch() ?: null;
}

function baselineIsRecorded(PDO $pdo, array $baseline): bool
{
    $record = recordedBaseline($pdo, $baseline);
    return $record !== null
        && hash_equals($baseline['sha256'], (string) $record['checksum'])
        && $record['execution_type'] === $baseline['state'];
}

function activeMigrationFiles(array $manifest): array
{
    $files = glob($manifest['active_migrations']['absolute_path'] . '/*' . $manifest['active_migrations']['extension']) ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function migrationIsIncludedInBaseline(string $version, array $manifest): bool
{
    return strcmp($version, (string)$manifest['baseline_includes_through']) <= 0;
}

function baselineSchemaCanBeAdopted(PDO $pdo): bool
{
    $required = [
        ['schools', 'id'], ['users', 'id'], ['students', 'school_id'],
        ['monthly_fees', 'school_id'], ['payments', 'receipt_number'],
        ['student_financial_items', 'school_id'], ['payment_allocations', 'school_id'],
    ];
    foreach ($required as [$table, $column]) {
        if (!tableExists($pdo, $table)
            || (int) scalar($pdo, 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]) !== 1) {
            return false;
        }
    }
    return true;
}

function executeSqlScript(PDO $pdo, string $path): void
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('SQL file is unreadable: ' . basename($path));
    }

    $delimiter = ';';
    $buffer = '';
    try {
        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $match)) {
                if (trim($buffer) !== '') {
                    throw new RuntimeException('Delimiter changed with pending SQL in ' . basename($path));
                }
                $delimiter = trim($match[1]);
                continue;
            }
            $buffer .= $line;
            if (str_ends_with(rtrim($buffer), $delimiter)) {
                $sql = substr(rtrim($buffer), 0, -strlen($delimiter));
                if (trim($sql) !== '') {
                    $pdo->exec($sql);
                }
                $buffer = '';
            }
        }
    } finally {
        fclose($handle);
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException('Unterminated SQL statement in ' . basename($path));
    }
}

function assertBaselineDataContract(PDO $pdo, array $manifest): void
{
    foreach ($manifest['technical_reference_counts'] ?? [] as $table => $expectedCount) {
        if (!preg_match('/^[a-z_]+$/', (string) $table)
            || !tableExists($pdo, (string) $table)
            || (int) scalar($pdo, 'SELECT COUNT(*) FROM `' . $table . '`') !== (int) $expectedCount) {
            throw new RuntimeException("Technical reference contract failed for {$table}.");
        }
    }
    foreach ($manifest['business_tables_must_be_empty'] ?? [] as $table) {
        if (!preg_match('/^[a-z_]+$/', (string) $table)
            || !tableExists($pdo, (string) $table)
            || (int) scalar($pdo, 'SELECT COUNT(*) FROM `' . $table . '`') !== 0) {
            throw new RuntimeException("Business data contract failed for {$table}.");
        }
    }
}

function printStatus(PDO $pdo, array $manifest): int
{
    $baseline = $manifest['baseline'];
    $actualChecksum = hash_file('sha256', $baseline['absolute_path']);
    printf("[MANIFEST] baseline=%s expected_sha256=%s actual_sha256=%s\n", $baseline['id'], $baseline['sha256'], $actualChecksum ?: 'unreadable');
    printf("[MIGRATIONS] path=%s count=%d\n", $manifest['active_migrations']['path'], count(activeMigrationFiles($manifest)));

    if (tableCount($pdo) === 0) {
        echo "[BOOTSTRAP REQUIRED] target database is empty.\n";
        return 2;
    }
    if (baselineIsRecorded($pdo, $baseline)) {
        printf("[BASELINE APPLIED] %s\n", $baseline['version']);
        return hash_equals($baseline['sha256'], (string) $actualChecksum) ? 0 : 2;
    }
    echo "[UNRECONCILED] target contains tables but does not hold the declared baseline marker; bootstrap is refused.\n";
    return 2;
}

$manifest = loadManifest($storageDirectory);
$actualChecksum = hash_file('sha256', $manifest['baseline']['absolute_path']);
if ($actualChecksum === false || !hash_equals($manifest['baseline']['sha256'], $actualChecksum)) {
    fail('Baseline checksum mismatch; no database action was performed.');
}
$pdo = connect($databaseConfig);

if ($command === '--status') {
    exit(printStatus($pdo, $manifest));
}

if ($command === '--bootstrap') {
    if (baselineIsRecorded($pdo, $manifest['baseline'])) {
        echo '[ALREADY APPLIED] baseline ' . $manifest['baseline']['version'] . "\n";
        exit(0);
    }
    if (tableCount($pdo) !== 0) {
        fail('[REFUSED] bootstrap requires an empty target database; no write was performed.');
    }
    if ((int) scalar($pdo, 'SELECT @@FOREIGN_KEY_CHECKS') !== 1) {
        fail('[REFUSED] FOREIGN_KEY_CHECKS must be enabled before bootstrap.');
    }

    try {
        executeSqlScript($pdo, $manifest['baseline']['absolute_path']);
        if ((int) scalar($pdo, 'SELECT @@FOREIGN_KEY_CHECKS') !== 1) {
            throw new RuntimeException('Baseline did not restore FOREIGN_KEY_CHECKS to 1.');
        }
        assertBaselineDataContract($pdo, $manifest);
        $statement = $pdo->prepare('INSERT INTO schema_migrations (version, checksum, execution_type) VALUES (?, ?, ?)');
        $statement->execute([$manifest['baseline']['version'], $manifest['baseline']['sha256'], $manifest['baseline']['state']]);
        echo '[BOOTSTRAP APPLIED] baseline ' . $manifest['baseline']['version'] . "\n";
    } catch (Throwable $exception) {
        fail('[BOOTSTRAP FAILED] ' . $exception->getMessage());
    }
    exit(0);
}

if ($command === '--adopt-baseline') {
    $existingBaseline = recordedBaseline($pdo, $manifest['baseline']);
    if ($existingBaseline !== null && hash_equals($manifest['baseline']['sha256'], (string)$existingBaseline['checksum'])) {
        if ($existingBaseline['execution_type'] !== $manifest['baseline']['state']) {
            $pdo->prepare('UPDATE schema_migrations SET execution_type = ? WHERE version = ?')
                ->execute([$manifest['baseline']['state'], $manifest['baseline']['version']]);
        }
        echo '[ALREADY ADOPTED] baseline ' . $manifest['baseline']['version'] . "\n";
        exit(0);
    }
    if (tableCount($pdo) === 0 || !baselineSchemaCanBeAdopted($pdo)) {
        fail('[REFUSED] adoption requires an existing schema compatible with the declared baseline; no write was performed.');
    }
    $statement = $pdo->prepare('INSERT INTO schema_migrations (version, checksum, execution_type) VALUES (?, ?, ?)');
    $statement->execute([$manifest['baseline']['version'], $manifest['baseline']['sha256'], $manifest['baseline']['state']]);
    echo '[ADOPTED] baseline ' . $manifest['baseline']['version'] . ". Review --status, then run --migrate.\n";
    exit(0);
}

if (!baselineIsRecorded($pdo, $manifest['baseline'])) {
    fail('[REFUSED] target is not bootstrapped with the declared baseline; no migration was executed.');
}

foreach (activeMigrationFiles($manifest) as $path) {
    $version = basename($path);
    if (migrationIsIncludedInBaseline($version, $manifest)) {
        echo "[BASELINED] {$version}\n";
        continue;
    }
    $checksum = hash_file('sha256', $path);
    if ($checksum === false) {
        fail("Migration checksum cannot be read: {$version}");
    }
    $statement = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
    $statement->execute([$version]);
    $recordedChecksum = $statement->fetchColumn();
    if ($recordedChecksum !== false) {
        if (!hash_equals((string) $recordedChecksum, $checksum)) {
            fail("[REFUSED] active migration checksum changed: {$version}");
        }
        echo "[SKIPPED] {$version}\n";
        continue;
    }
    try {
        executeSqlScript($pdo, $path);
        $pdo->prepare('INSERT INTO schema_migrations (version, checksum, execution_type) VALUES (?, ?, ?)')
            ->execute([$version, $checksum, 'APPLIED']);
        echo "[APPLIED] {$version}\n";
    } catch (Throwable $exception) {
        fail("[MIGRATION FAILED] {$version}: " . $exception->getMessage());
    }
}

echo "Active migrations are up to date.\n";
