<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Middleware\PermissionMiddleware;
use Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string) getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing to run outside a dedicated miranda_finance_data_test_* database.\n");
    exit(2);
}

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

function financePermissionExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = Database::connect();
$suffix = (string) getmypid();
$pdo->prepare('INSERT INTO schools (name, code, slug, email_domain) VALUES (?, ?, ?, ?)')
    ->execute(['Finance permission test ' . $suffix, 'FP' . $suffix, 'finance-permission-' . $suffix, 'finance-permission-' . $suffix . '.test']);
$schoolId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (school_id, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, "user", "ACTIVE")')
    ->execute([$schoolId, 'Finance', 'None', 'finance-none-' . $suffix . '@example.test', 'test']);
$noneId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (school_id, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, "user", "ACTIVE")')
    ->execute([$schoolId, 'Finance', 'View', 'finance-view-' . $suffix . '@example.test', 'test']);
$viewId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (school_id, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, "user", "ACTIVE")')
    ->execute([$schoolId, 'Finance', 'Manage', 'finance-manage-' . $suffix . '@example.test', 'test']);
$manageId = (int) $pdo->lastInsertId();

$permissions = $pdo->query('SELECT code, id FROM permissions WHERE code IN ("finance.view", "finance.manage")')->fetchAll(PDO::FETCH_KEY_PAIR);
financePermissionExpect(isset($permissions['finance.view'], $permissions['finance.manage']), 'Finance permissions are missing from the bootstrap.');
$grant = $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id) VALUES (?, ?)');
$grant->execute([$viewId, $permissions['finance.view']]);
$grant->execute([$manageId, $permissions['finance.manage']]);

Request::set('auth_user', ['id' => $noneId, 'role' => 'user', 'school_id' => $schoolId]);
financePermissionExpect(!PermissionMiddleware::allows('finance.view'), 'User without finance permission received finance.view.');
financePermissionExpect(!PermissionMiddleware::allows('finance.manage'), 'User without finance permission received finance.manage.');

Request::set('auth_user', ['id' => $viewId, 'role' => 'user', 'school_id' => $schoolId]);
financePermissionExpect(PermissionMiddleware::allows('finance.view'), 'finance.view grant was rejected.');
financePermissionExpect(!PermissionMiddleware::allows('finance.manage'), 'finance.view unexpectedly grants finance.manage.');

Request::set('auth_user', ['id' => $manageId, 'role' => 'user', 'school_id' => $schoolId]);
financePermissionExpect(PermissionMiddleware::allows('finance.view'), 'finance.manage must imply finance.view on the server.');
financePermissionExpect(PermissionMiddleware::allows('finance.manage'), 'finance.manage grant was rejected.');

Request::set('auth_user', ['id' => 0, 'role' => 'super_admin']);
financePermissionExpect(PermissionMiddleware::allows('finance.view') && PermissionMiddleware::allows('finance.manage'), 'Super administrator permissions were rejected.');

echo "finance permission security: OK\n";
