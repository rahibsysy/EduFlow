<?php
declare(strict_types=1);

foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    if (($value = getenv($key)) !== false) {
        $_ENV[$key] = $value;
    }
}

use App\Core\Database;
use App\Core\Request;
use App\Services\ChargeCategoryService;
use App\Services\StudentService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string) getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing unsafe database.\n");
    exit(2);
}

function enrollmentExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = Database::connect();
$suffix = (string) getmypid();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain) VALUES(?,?,?,?)')
    ->execute(['Recurring enrollment ' . $suffix, 'RE' . $suffix, 'recurring-enrollment-' . $suffix, 're' . $suffix . '.test']);
$schoolId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO academic_years(school_id,label,name,status,is_current) VALUES(?,?,?,"ACTIVE",1)')
    ->execute([$schoolId, '2026/2027', '2026/2027']);
$academicYearId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO class_levels(school_id,name,level_name,group_name,school_year,status) VALUES(?,?,?,?,?,"ACTIVE")')
    ->execute([$schoolId, '1AC 1', '1AC', '1', '2026/2027']);
$classId = (int) $pdo->lastInsertId();

Request::set('auth_user', ['role' => 'admin', 'school_id' => $schoolId]);
(new ChargeCategoryService())->all(true);
$categories = $pdo->prepare('SELECT id, code, default_amount FROM charge_categories WHERE school_id = ? AND code IN ("DAYCARE", "TRANSPORT")');
$categories->execute([$schoolId]);
$byCode = [];
foreach ($categories->fetchAll() as $row) {
    $byCode[$row['code']] = $row;
}
enrollmentExpect(isset($byCode['DAYCARE'], $byCode['TRANSPORT']), 'Recurring categories are unavailable');

$students = new StudentService();
$created = $students->createModern([
    'first_name' => 'Recurring',
    'last_name' => 'Enrollment',
    'parent_phone' => '060' . $suffix,
    'class_level_id' => $classId,
    'tuition_enabled' => false,
    'charges' => [
        [
            'charge_category_id' => (int) $byCode['DAYCARE']['id'],
            'label' => 'Garde janvier',
            'original_amount' => (float) $byCode['DAYCARE']['default_amount'],
            'billing_month' => 1,
            'year_value' => 2027,
            'discount_type' => 'FIXED',
            'discount_value' => 20,
            'discount_reason' => 'Aide de test',
        ],
        [
            'charge_category_id' => (int) $byCode['TRANSPORT']['id'],
            'label' => 'Transport février',
            'original_amount' => (float) $byCode['TRANSPORT']['default_amount'],
            'billing_month' => 2,
            'year_value' => 2027,
        ],
    ],
]);
enrollmentExpect(!isset($created['error']), 'Enrollment with explicit recurring periods failed: ' . ($created['error'] ?? 'unknown'));

$items = $pdo->prepare('SELECT fi.billing_month, fi.idempotency_key, details.discount_value, details.notes FROM student_financial_items fi INNER JOIN student_financial_item_details details ON details.student_financial_item_id = fi.id WHERE fi.student_id = ? ORDER BY fi.billing_month');
$items->execute([(int) $created['id']]);
$rows = $items->fetchAll();
enrollmentExpect(count($rows) === 2, 'Enrollment did not create exactly two requested obligations');
enrollmentExpect((int) $rows[0]['billing_month'] === 1 && (int) $rows[1]['billing_month'] === 2, 'Recurring periods were not stored');
enrollmentExpect((float) $rows[0]['discount_value'] === 20.0 && $rows[0]['notes'] === 'Aide de test', 'Recurring discount was not retained');
enrollmentExpect($rows[0]['idempotency_key'] !== $rows[1]['idempotency_key'], 'Recurring obligations have the same idempotency identity');

$withoutPeriod = $students->createModern([
    'first_name' => 'Missing',
    'last_name' => 'Period',
    'parent_phone' => '061' . $suffix,
    'class_level_id' => $classId,
    'tuition_enabled' => false,
    'charges' => [[
        'charge_category_id' => (int) $byCode['DAYCARE']['id'],
        'original_amount' => (float) $byCode['DAYCARE']['default_amount'],
    ]],
]);
enrollmentExpect(isset($withoutPeriod['error']) && str_contains($withoutPeriod['error'], 'période'), 'Missing recurring period was accepted');

$payments = $pdo->prepare('SELECT COUNT(*) FROM payments WHERE school_id = ?');
$payments->execute([$schoolId]);
enrollmentExpect((int) $payments->fetchColumn() === 0, 'Enrollment created a payment');
echo "student recurring enrollment integration: OK\n";
