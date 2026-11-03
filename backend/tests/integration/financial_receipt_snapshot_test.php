<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Services\PaymentService;
use App\Services\ReceiptService;
use Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_test_/', (string)(getenv('DB_NAME') ?: ''))) {
    fwrite(STDERR, "Refusing to run outside a dedicated miranda_finance_test_* database.\n");
    exit(2);
}

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = Database::connect();
$suffix = (string)getmypid();

$pdo->prepare('INSERT INTO schools (name, code, slug, email_domain) VALUES (?, ?, ?, ?)')
    ->execute(['Finance snapshot test ' . $suffix, 'SNAP' . $suffix, 'snapshot-test-' . $suffix, 'snapshot-' . $suffix . '.test']);
$schoolId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO schools (name, code, slug, email_domain) VALUES (?, ?, ?, ?)')
    ->execute(['Other school ' . $suffix, 'OTHR' . $suffix, 'other-school-' . $suffix, 'other-' . $suffix . '.test']);
$otherSchoolId = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO users (school_id, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, "admin", "ACTIVE")')
    ->execute([$schoolId, 'Finance', 'Tester', 'finance-' . $suffix . '@example.test', 'test']);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO users (school_id, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, "admin", "ACTIVE")')
    ->execute([$otherSchoolId, 'Other', 'Tester', 'other-' . $suffix . '@example.test', 'test']);
$otherUserId = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO students (school_id, first_name, last_name, class_level, monthly_amount, status) VALUES (?, ?, ?, ?, ?, "REGISTERED")')
    ->execute([$schoolId, 'Receipt', 'Student', 'Test level', 500]);
$studentId = (int)$pdo->lastInsertId();

$insertItem = $pdo->prepare('INSERT INTO student_financial_items (school_id, student_id, item_code, label, unit_amount, total_amount, paid_amount, status) VALUES (?, ?, ?, ?, ?, ?, 0, "UNPAID")');
$insertDetail = $pdo->prepare('INSERT INTO student_financial_item_details (student_financial_item_id, school_id, original_amount, final_amount) VALUES (?, ?, ?, ?)');
$insertItem->execute([$schoolId, $studentId, 'SNAP-A-' . $suffix, 'Snapshot original A', 100, 100]);
$itemA = (int)$pdo->lastInsertId();
$insertDetail->execute([$itemA, $schoolId, 100, 100]);
$insertItem->execute([$schoolId, $studentId, 'SNAP-B-' . $suffix, 'Snapshot original B', 200, 200]);
$itemB = (int)$pdo->lastInsertId();
$insertDetail->execute([$itemB, $schoolId, 200, 200]);

Request::set('auth_user', ['id' => $userId, 'role' => 'admin', 'school_id' => $schoolId]);
$paymentService = new PaymentService();
$created = $paymentService->create([
    'student_id' => $studentId,
    'amount_paid' => 300,
    'payment_date' => '2026-11-02',
    'payment_method_id' => 1,
    'allocations' => [
        ['student_financial_item_id' => $itemA, 'amount' => 100],
        ['student_financial_item_id' => $itemB, 'amount' => 200],
    ],
]);
expect(!isset($created['error']), 'Flexible payment creation failed.');
$paymentId = (int)$created['id'];

$snapshots = $pdo->prepare('SELECT item_label_snapshot, amount FROM payment_allocations WHERE payment_id = ? ORDER BY student_financial_item_id');
$snapshots->execute([$paymentId]);
$snapshots = $snapshots->fetchAll();
expect(count($snapshots) === 2, 'Expected two allocation snapshots.');
expect($snapshots[0]['item_label_snapshot'] === 'Snapshot original A' && (float)$snapshots[0]['amount'] === 100.0, 'First snapshot mismatch.');
expect($snapshots[1]['item_label_snapshot'] === 'Snapshot original B' && (float)$snapshots[1]['amount'] === 200.0, 'Second snapshot mismatch.');

$receiptService = new ReceiptService();
$beforeRename = $receiptService->generateData($paymentId);
expect(array_column($beforeRename['allocations'], 'label') === ['Snapshot original A', 'Snapshot original B'], 'Receipt did not use allocation snapshots.');
expect(array_sum(array_column($beforeRename['allocations'], 'amount')) === 300.0, 'Receipt allocation total mismatch.');
expect(str_starts_with((string)$receiptService->generatePdf($paymentId)['content'], '%PDF'), 'Flexible receipt PDF was not generated.');

$pdo->prepare('UPDATE student_financial_items SET label = ? WHERE id = ? AND school_id = ?')
    ->execute(['Renamed after payment', $itemA, $schoolId]);
$afterRename = $receiptService->generateData($paymentId);
expect(array_column($afterRename['allocations'], 'label') === ['Snapshot original A', 'Snapshot original B'], 'Receipt label changed after item rename.');
expect(str_starts_with((string)$receiptService->generatePdf($paymentId)['content'], '%PDF'), 'Receipt PDF was not regenerated after item rename.');

$cancelled = $paymentService->cancel($paymentId, ['reason' => 'Snapshot test cancellation']);
expect(($cancelled['status'] ?? '') === 'CANCELLED', 'Flexible payment cancellation failed.');
expect(($receiptService->generateData($paymentId)['is_cancelled'] ?? false) === true, 'Cancelled receipt was not marked cancelled.');

$pdo->prepare('INSERT INTO monthly_fees (school_id, student_id, month_label, year_value, total_amount, amount_paid, remaining_amount, status) VALUES (?, ?, "11", 2026, 500, 100, 400, "PARTIAL")')
    ->execute([$schoolId, $studentId]);
$monthlyFeeId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO payments (school_id, student_id, monthly_fee_id, receipt_number, amount_paid, fee_total_at_payment, paid_before_payment, remaining_after_payment, payment_date, payment_method_id, payment_method, issued_by_user_id, status) VALUES (?, ?, ?, ?, 100, 500, 0, 400, "2026-11-02", 1, "CASH", ?, "COMPLETED")')
    ->execute([$schoolId, $studentId, $monthlyFeeId, 'SNAP-M-' . $suffix, $userId]);
$monthlyPaymentId = (int)$pdo->lastInsertId();
expect(str_starts_with((string)$receiptService->generatePdf($monthlyPaymentId)['content'], '%PDF'), 'Monthly receipt PDF was not generated.');

Request::set('auth_user', ['id' => $otherUserId, 'role' => 'admin', 'school_id' => $otherSchoolId]);
expect(isset($receiptService->generateData($paymentId)['error']), 'Cross-school receipt access was allowed.');

echo "financial receipt snapshot integration: OK\n";
