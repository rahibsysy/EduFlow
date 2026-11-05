<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Services\DashboardService;
use App\Services\FinancialDataService;
use App\Services\FamilyService;
use App\Services\PaymentDocumentService;
use App\Services\StudentService;
use Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string) (getenv('DB_NAME') ?: ''))) {
    fwrite(STDERR, "Refusing to run outside a dedicated miranda_finance_data_test_* database.\n");
    exit(2);
}

Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();

function expectFinancial(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectAmount(float $expected, mixed $actual, string $message): void
{
    expectFinancial(abs($expected - (float) $actual) < 0.0001, $message . " (expected {$expected}, got {$actual})");
}

$pdo = Database::connect();
$suffix = (string) getmypid();

$insertSchool = $pdo->prepare('INSERT INTO schools (name, code, slug, email_domain) VALUES (?, ?, ?, ?)');
$insertSchool->execute(['Financial data test ' . $suffix, 'FD' . $suffix, 'financial-data-' . $suffix, 'financial-data-' . $suffix . '.test']);
$schoolId = (int) $pdo->lastInsertId();
$insertSchool->execute(['Other financial data test ' . $suffix, 'OD' . $suffix, 'other-financial-data-' . $suffix, 'other-financial-data-' . $suffix . '.test']);
$otherSchoolId = (int) $pdo->lastInsertId();

$insertYear = $pdo->prepare('INSERT INTO academic_years (school_id, label, name, is_current) VALUES (?, ?, ?, ?)');
$insertYear->execute([$schoolId, '2026-2027', '2026-2027', 1]);
$activeYearId = (int) $pdo->lastInsertId();
$insertYear->execute([$schoolId, '2025-2026', '2025-2026', 0]);
$historicYearId = (int) $pdo->lastInsertId();
$insertYear->execute([$otherSchoolId, '2026-2027', '2026-2027', 1]);
$otherYearId = (int) $pdo->lastInsertId();
$pdo->prepare('INSERT INTO school_settings (school_id, setting_key, setting_value) VALUES (?, "active_academic_year_id", ?)')->execute([$schoolId, (string) $activeYearId]);

$pdo->prepare('INSERT INTO families (school_id, family_reference, family_name) VALUES (?, ?, ?)')->execute([$schoolId, 'FD-FAMILY-' . $suffix, 'Financial test family']);
$familyId = (int) $pdo->lastInsertId();
$insertStudent = $pdo->prepare('INSERT INTO students (school_id, family_id, first_name, last_name, class_level, monthly_amount, status) VALUES (?, ?, ?, ?, ?, ?, "REGISTERED")');
$insertStudent->execute([$schoolId, $familyId, 'Both', 'Sources', 'Test', 100]);
$bothStudentId = (int) $pdo->lastInsertId();
$insertStudent->execute([$schoolId, $familyId, 'Monthly', 'Only', 'Test', 40]);
$monthlyOnlyStudentId = (int) $pdo->lastInsertId();
$insertStudent->execute([$schoolId, null, 'Flexible', 'Only', 'Test', 0]);
$flexibleOnlyStudentId = (int) $pdo->lastInsertId();
$insertStudent->execute([$otherSchoolId, null, 'Other', 'School', 'Test', 0]);
$otherStudentId = (int) $pdo->lastInsertId();

$insertMonthly = $pdo->prepare('INSERT INTO monthly_fees (school_id, student_id, month_label, year_value, total_amount, amount_paid, remaining_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$insertMonthly->execute([$schoolId, $bothStudentId, '01', 2026, 100, 50, 50, 'PARTIAL']);
$bothMonthlyPartialId = (int) $pdo->lastInsertId();
$insertMonthly->execute([$schoolId, $bothStudentId, '02', 2026, 80, 0, 80, 'UNPAID']);
$insertMonthly->execute([$schoolId, $bothStudentId, '01', 2025, 70, 0, 70, 'UNPAID']);
$insertMonthly->execute([$schoolId, $monthlyOnlyStudentId, '01', 2026, 40, 10, 30, 'PARTIAL']);
$monthlyOnlyFeeId = (int) $pdo->lastInsertId();

$insertMonthlyPayment = $pdo->prepare('INSERT INTO payments (school_id, student_id, monthly_fee_id, amount_paid, payment_date, payment_method, status) VALUES (?, ?, ?, ?, ?, "CASH", ?)');
$insertMonthlyPayment->execute([$schoolId, $bothStudentId, $bothMonthlyPartialId, 50, '2026-01-05', 'COMPLETED']);
$insertMonthlyPayment->execute([$schoolId, $bothStudentId, $bothMonthlyPartialId, 10, '2026-01-06', 'CANCELLED']);
$insertMonthlyPayment->execute([$schoolId, $monthlyOnlyStudentId, $monthlyOnlyFeeId, 10, '2026-01-05', 'COMPLETED']);

$insertItem = $pdo->prepare('INSERT INTO student_financial_items (school_id, student_id, academic_year_id, item_code, label, unit_amount, total_amount, discount_amount, paid_amount, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insertDetail = $pdo->prepare('INSERT INTO student_financial_item_details (student_financial_item_id, school_id, due_date, original_amount, discount_type, discount_value, final_amount) VALUES (?, ?, ?, ?, ?, ?, ?)');
$createItem = static function (int $school, int $student, int $year, string $code, string $label, float $original, float $final, ?string $dueDate, string $status) use ($insertItem, $insertDetail): int {
    $insertItem->execute([$school, $student, $year, $code, $label, $original, $final, $original - $final, 0, $status]);
    $id = (int) Database::connect()->lastInsertId();
    $insertDetail->execute([$id, $school, $dueDate, $original, $original === $final ? null : 'FIXED', $original - $final ?: null, $final]);
    return $id;
};

$pastDate = date('Y-m-d', strtotime('-10 days'));
$futureDate = date('Y-m-d', strtotime('+10 days'));
$itemA = $createItem($schoolId, $bothStudentId, $activeYearId, 'FD-A-' . $suffix, 'Discounted overdue item', 120, 100, $pastDate, 'PARTIAL');
$itemB = $createItem($schoolId, $bothStudentId, $activeYearId, 'FD-B-' . $suffix, 'Future item', 80, 80, $futureDate, 'PARTIAL');
$itemC = $createItem($schoolId, $bothStudentId, $activeYearId, 'FD-C-' . $suffix, 'No due date item', 60, 60, null, 'UNPAID');
$createItem($schoolId, $bothStudentId, $activeYearId, 'FD-X-' . $suffix, 'Exempt item', 55, 55, $pastDate, 'EXEMPT');
$createItem($schoolId, $bothStudentId, $historicYearId, 'FD-H-' . $suffix, 'Historic item', 25, 25, $pastDate, 'UNPAID');
$flexibleOnlyItemId = $createItem($schoolId, $flexibleOnlyStudentId, $activeYearId, 'FD-F-' . $suffix, 'Flexible only item', 90, 90, $pastDate, 'UNPAID');
$createItem($otherSchoolId, $otherStudentId, $otherYearId, 'FD-O-' . $suffix, 'Other school item', 30, 30, $pastDate, 'UNPAID');

$insertPayment = $pdo->prepare('INSERT INTO payments (school_id, student_id, amount_paid, payment_date, payment_method, status) VALUES (?, ?, ?, ?, "CASH", ?)');
$insertAllocation = $pdo->prepare('INSERT INTO payment_allocations (school_id, payment_id, student_financial_item_id, amount, item_label_snapshot) VALUES (?, ?, ?, ?, ?)');
$insertPayment->execute([$schoolId, $bothStudentId, 50, '2026-01-10', 'COMPLETED']);
$paymentOne = (int) $pdo->lastInsertId();
$insertAllocation->execute([$schoolId, $paymentOne, $itemA, 30, 'Discounted overdue item']);
$insertAllocation->execute([$schoolId, $paymentOne, $itemB, 20, 'Future item']);
$insertPayment->execute([$schoolId, $bothStudentId, 15, '2026-01-11', 'COMPLETED']);
$paymentTwo = (int) $pdo->lastInsertId();
$insertAllocation->execute([$schoolId, $paymentTwo, $itemA, 15, 'Discounted overdue item']);
$insertPayment->execute([$schoolId, $bothStudentId, 10, '2026-01-12', 'CANCELLED']);
$cancelledPayment = (int) $pdo->lastInsertId();
$insertAllocation->execute([$schoolId, $cancelledPayment, $itemA, 10, 'Discounted overdue item']);

$service = new FinancialDataService();
$both = $service->getStudentFinancialSummary($schoolId, $bothStudentId);
expectAmount(440, $both['total_initial'], 'Initial total must include active monthly and flexible items only');
expectAmount(20, $both['total_discount'], 'Flexible discount must be reported');
expectAmount(420, $both['total_due'], 'Due total must use final discounted amounts');
expectAmount(115, $both['total_paid'], 'Completed payments and stored monthly partial payment must be counted once');
expectAmount(305, $both['total_remaining'], 'Remaining balance must account for partial payments');
expectAmount(180, $both['monthly']['total_final'], 'Monthly subtotal mismatch');
expectAmount(240, $both['flexible']['total_final'], 'Flexible subtotal must exclude exempt item');
expectAmount(65, $both['flexible']['total_paid'], 'Flexible subtotal must exclude cancelled allocation');
expectFinancial($both['monthly']['partial_count'] === 1 && $both['monthly']['unpaid_count'] === 1, 'Monthly status counts mismatch');
expectFinancial($both['flexible']['partial_count'] === 2 && $both['flexible']['unpaid_count'] === 1, 'Flexible status counts mismatch');
expectAmount(55, $both['overdue']['total_remaining'], 'Only explicitly due overdue balance must be marked late');

$unpaid = $service->getStudentUnpaidItems($schoolId, $bothStudentId);
expectAmount(305, $unpaid['total_remaining'], 'Unpaid includes future and undated remaining balances');
expectFinancial(count($unpaid['items']) === 5, 'Unpaid items must include two monthly and three active flexible obligations');
$overdue = $service->getStudentOverdueItems($schoolId, $bothStudentId);
expectAmount(55, $overdue['total_remaining'], 'Overdue must exclude future, undated, and exempt items');
expectFinancial(count($overdue['items']) === 1, 'Only one explicit due-date item is overdue');

$monthlyOnly = $service->getStudentFinancialSummary($schoolId, $monthlyOnlyStudentId);
expectAmount(40, $monthlyOnly['total_due'], 'Monthly-only student due total mismatch');
expectAmount(10, $monthlyOnly['total_paid'], 'Monthly-only student paid total mismatch');
$flexibleOnly = $service->getStudentFinancialSummary($schoolId, $flexibleOnlyStudentId);
expectAmount(90, $flexibleOnly['total_due'], 'Flexible-only student due total mismatch');
expectAmount(90, $flexibleOnly['total_remaining'], 'Flexible-only student remaining total mismatch');

$historic = $service->getStudentFinancialSummary($schoolId, $bothStudentId, $historicYearId);
expectAmount(95, $historic['total_due'], 'Historic year must use its own monthly and flexible records');
expectAmount(95, $historic['total_remaining'], 'Historic year remaining total mismatch');
$family = $service->getFamilyFinancialSummary($schoolId, $familyId);
expectFinancial(count($family['children']) === 2, 'Family must contain both children');
expectAmount(460, $family['summary']['total_due'], 'Family due total mismatch');
expectAmount(125, $family['summary']['total_paid'], 'Family paid total mismatch');
expectAmount(335, $family['summary']['total_remaining'], 'Family remaining total mismatch');

$pdo->prepare('DELETE FROM school_settings WHERE school_id = ? AND setting_key = "active_academic_year_id"')->execute([$schoolId]);
$familyWithoutConfiguredYear = $service->getFamilyFinancialSummary($schoolId, $familyId);
expectFinancial($familyWithoutConfiguredYear['academic_year_id'] === null, 'Family summary must tolerate a missing active academic year');
expectAmount(555, $familyWithoutConfiguredYear['summary']['total_due'], 'Family without configured year must include all known obligations');
Request::set('auth_user', ['role' => 'admin', 'school_id' => $schoolId]);
$dashboardWithoutConfiguredYear = (new DashboardService())->stats();
expectAmount(645, $dashboardWithoutConfiguredYear['total_due'], 'Dashboard must remain deterministic without an active academic year');
$pdo->prepare('INSERT INTO school_settings (school_id, setting_key, setting_value) VALUES (?, "active_academic_year_id", ?)')->execute([$schoolId, (string) $activeYearId]);

$other = $service->getStudentFinancialSummary($otherSchoolId, $otherStudentId);
expectAmount(30, $other['total_due'], 'Other school own data mismatch');
try {
    $service->getStudentFinancialSummary($schoolId, $otherStudentId);
    throw new RuntimeException('Cross-school student access was allowed');
} catch (DomainException) {
}

$schoolUnpaid = $service->getSchoolUnpaidItems($schoolId);
expectFinancial(count($schoolUnpaid['items']) === 9, 'School projection must include all unsold monthly and flexible obligations across years');
expectAmount(520, $schoolUnpaid['summary']['total_remaining'], 'School consolidated remaining total mismatch');
expectAmount(170, $schoolUnpaid['summary']['overdue']['total_remaining'], 'School overdue total must only include explicit due dates');
expectFinancial($schoolUnpaid['summary']['monthly']['total_remaining'] === 230.0, 'School monthly subtotal mismatch');
expectFinancial($schoolUnpaid['summary']['flexible']['total_remaining'] === 290.0, 'School flexible subtotal mismatch');

$activeSchoolUnpaid = $service->getSchoolUnpaidItems($schoolId, ['academic_year_id' => $activeYearId]);
expectAmount(425, $activeSchoolUnpaid['summary']['total_remaining'], 'Active-year consolidated total mismatch');
$periodUnpaid = $service->getSchoolUnpaidItems($schoolId, ['month_label' => '01', 'year_value' => 2026]);
expectAmount(80, $periodUnpaid['summary']['total_remaining'], 'Monthly period filter must preserve matching monthly obligations');

$schoolDashboard = $service->getSchoolFinancialSummary($schoolId);
expectAmount(645, $schoolDashboard['summary']['total_due'], 'Dashboard source must include monthly and flexible obligations across years');
expectAmount(125, $schoolDashboard['summary']['total_paid'], 'Dashboard source must exclude cancelled payments');
expectAmount(520, $schoolDashboard['summary']['total_remaining'], 'Dashboard source remaining balance mismatch');
expectAmount(170, $schoolDashboard['summary']['overdue']['total_remaining'], 'Dashboard source must only mark explicitly dated items overdue');
expectFinancial($schoolDashboard['late_students'] === 2, 'Dashboard late students must be based on explicit due dates only');
expectFinancial(count($schoolDashboard['top_unpaid_students']) === 3, 'Dashboard ranking must include monthly and flexible balances');

Request::set('auth_user', ['role' => 'admin', 'school_id' => $schoolId]);
$dashboard = (new DashboardService())->stats();
expectAmount(645, $dashboard['total_due'], 'Dashboard due total mismatch');
expectAmount(125, $dashboard['total_collected'], 'Dashboard collected total mismatch');
expectAmount(520, $dashboard['total_unpaid'], 'Dashboard unpaid total mismatch');
expectAmount(170, $dashboard['total_overdue'], 'Dashboard overdue total mismatch');
expectAmount(230, $dashboard['unpaid_breakdown']['monthly'], 'Dashboard monthly unpaid breakdown mismatch');
expectAmount(290, $dashboard['unpaid_breakdown']['flexible'], 'Dashboard flexible unpaid breakdown mismatch');
expectFinancial($dashboard['late_students'] === 2, 'Dashboard must not infer late monthly fees from creation dates');
expectFinancial($dashboard['payments_count'] === 4, 'Dashboard must not count cancelled payments as active collections');
expectAmount(19.38, $dashboard['coverage_rate'], 'Dashboard coverage rate mismatch');
expectFinancial((int) $dashboard['top_unpaid_students'][0]['student_id'] === $bothStudentId, 'Dashboard ranking must use consolidated balances');

Request::set('auth_user', ['role' => 'super_admin']);
$globalDashboard = (new DashboardService())->stats();
expectAmount(675, $globalDashboard['total_due'], 'Super admin dashboard must aggregate schools without omitting flexible fees');
expectAmount(550, $globalDashboard['total_unpaid'], 'Super admin dashboard school isolation aggregation mismatch');

Request::set('auth_user', ['role' => 'admin', 'school_id' => $schoolId]);
$unpaidPdf = (new PaymentDocumentService())->unpaid([]);
expectFinancial(!isset($unpaidPdf['error']) && str_starts_with((string) ($unpaidPdf['content'] ?? ''), '%PDF'), 'Consolidated unpaid PDF was not generated.');

$studentDetails = (new StudentService())->getById($bothStudentId);
expectAmount(420, $studentDetails['finance']['total_due'], 'Student card due total must match the active-year projection');
expectAmount(115, $studentDetails['finance']['total_paid'], 'Student card paid total must match the active-year projection');
expectAmount(305, $studentDetails['finance']['total_remaining'], 'Student card remaining total must match the active-year projection');
expectFinancial(count($studentDetails['financial_items']) === 5, 'Student card must expose monthly and flexible active-year items.');
expectFinancial(count($studentDetails['financial_payments']) === 5, 'Student card history must retain monthly, flexible, and cancelled payments.');
expectFinancial(count(array_filter($studentDetails['financial_payments'], static fn(array $payment): bool => $payment['status'] === 'CANCELLED')) === 2, 'Cancelled payments must remain identifiable in the student history.');

$familyDetails = (new FamilyService())->show($familyId);
expectAmount(460, $familyDetails['financial_projection']['summary']['total_due'], 'Family card due total must match the active-year projection');
expectAmount(125, $familyDetails['financial_projection']['summary']['total_paid'], 'Family card paid total must match the active-year projection');
expectAmount(335, $familyDetails['financial_projection']['summary']['total_remaining'], 'Family card remaining total must match the active-year projection');

echo "financial data service integration: OK\n";
