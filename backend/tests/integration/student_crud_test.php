<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Services\StudentService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string)getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing unsafe database.\n");
    exit(2);
}

function studentExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = Database::connect();
$suffix = (string)getmypid();
$pdo->prepare('INSERT INTO schools(name,code,slug,email_domain,primary_color,secondary_color,currency,status) VALUES(?,?,?,?,?,?,?,"ACTIVE")')
    ->execute(['Student test', 'ST' . $suffix, 'student-test-' . $suffix, 'student-' . $suffix . '.test', '#111111', '#222222', 'MAD']);
$schoolId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO academic_years(school_id,label,name,status,is_current) VALUES(?,?,?,"ACTIVE",0)')
    ->execute([$schoolId, '2026/2027', '2026/2027']);
$academicYearId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO class_levels(school_id,name,level_name,group_name,school_year,status) VALUES(?,?,?,?,?,"ACTIVE")')
    ->execute([$schoolId, '1AC 1', '1AC', '1', '2026/2027']);
$classLevelId = (int)$pdo->lastInsertId();

Request::set('auth_user', ['id' => 1, 'role' => 'admin', 'school_id' => $schoolId]);
$service = new StudentService();
$created = $service->createModern([
    'first_name' => 'Student',
    'last_name' => 'Crud',
    'massar_code' => 'CNE-' . $suffix,
    'class_level_id' => $classLevelId,
    'parent_name' => 'Guardian Crud',
    'parent_phone' => '060000' . $suffix,
    'guardians' => [['full_name' => 'Guardian Crud', 'phone_primary' => '060000' . $suffix, 'relationship_type' => 'Responsable']],
    'charges' => [],
]);
studentExpect(!isset($created['error']), 'Student creation without an active academic year failed: ' . ($created['error'] ?? 'unknown'));
$studentId = (int)$created['id'];
$persisted = $pdo->prepare('SELECT school_id,class_level_id,school_year,academic_year_id,massar_code,cne FROM students WHERE id=?');
$persisted->execute([$studentId]);
$student = $persisted->fetch();
studentExpect((int)$student['school_id'] === $schoolId && (int)$student['class_level_id'] === $classLevelId, 'Student was not linked to the selected class');
studentExpect($student['school_year'] === '2026/2027', 'Student school year was not derived from the class');
studentExpect((int)$student['academic_year_id'] === $academicYearId, 'Student academic year was not derived from the selected class');
studentExpect($student['massar_code'] === 'CNE-' . $suffix, 'CNE was not persisted in the canonical massar_code column');
studentExpect($student['cne'] === null, 'Student creation wrote the deprecated cne column');
$enrollment = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id=? AND school_id=? AND academic_year_id=? AND class_level_id=?');
$enrollment->execute([$studentId, $schoolId, $academicYearId, $classLevelId]);
studentExpect((int)$enrollment->fetchColumn() === 1, 'Enrollment was not persisted for the class year');
$fees = $pdo->prepare('SELECT (SELECT COUNT(*) FROM monthly_fees WHERE student_id=?) + (SELECT COUNT(*) FROM student_financial_items WHERE student_id=?)');
$fees->execute([$studentId, $studentId]);
studentExpect((int)$fees->fetchColumn() === 0, 'Student creation created financial debt');

$updated = $service->updateModern($studentId, ['first_name' => 'Updated', 'massar_code' => 'CNE-UPDATED-' . $suffix, 'class_level_id' => $classLevelId, 'charges' => []]);
studentExpect(!isset($updated['error']), 'Student update failed');
$reloaded = $service->getById($studentId);
studentExpect(is_array($reloaded) && ($reloaded['student']['first_name'] ?? '') === 'Updated', 'Student update was not persisted after reload');
studentExpect(($reloaded['student']['massar_code'] ?? '') === 'CNE-UPDATED-' . $suffix, 'Updated CNE was not persisted after reload');
$canonical = $pdo->prepare('SELECT massar_code,cne FROM students WHERE id=?');
$canonical->execute([$studentId]);
$canonicalRow = $canonical->fetch();
studentExpect($canonicalRow['massar_code'] === 'CNE-UPDATED-' . $suffix && $canonicalRow['cne'] === null, 'CNE update duplicated the identifier into the deprecated column');
$legacyApi = $service->create([
    'first_name' => 'Legacy',
    'last_name' => 'Cne',
    'cne' => 'CNE-LEGACY-API-' . $suffix,
    'class_level_id' => $classLevelId,
    'parent_name' => 'Legacy Guardian',
    'parent_phone' => '061000' . $suffix,
    'monthly_amount' => 0,
]);
studentExpect(!isset($legacyApi['error']), 'Legacy CNE API key was not accepted');
$legacyId = (int)$legacyApi['id'];
$legacyCanonical = $pdo->prepare('SELECT massar_code,cne FROM students WHERE id=?');
$legacyCanonical->execute([$legacyId]);
$legacyCanonicalRow = $legacyCanonical->fetch();
studentExpect($legacyCanonicalRow['massar_code'] === 'CNE-LEGACY-API-' . $suffix && $legacyCanonicalRow['cne'] === null, 'Legacy CNE API key was not normalized to massar_code');
$pdo->prepare('UPDATE students SET massar_code=NULL,cne=? WHERE id=?')->execute(['CNE-HISTORICAL-' . $suffix, $legacyId]);
$historical = $service->getById($legacyId);
studentExpect(($historical['student']['cne'] ?? '') === 'CNE-HISTORICAL-' . $suffix, 'Historical CNE-only record is no longer readable');
$invalid = $service->createModern(['first_name' => 'Invalid', 'last_name' => 'Class', 'class_level_id' => 999999, 'parent_phone' => '069999' . $suffix, 'charges' => []]);
studentExpect(isset($invalid['error']), 'Invalid class was accepted');

echo "student CRUD integration: OK\n";
