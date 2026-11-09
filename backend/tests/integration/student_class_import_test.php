<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Request;
use App\Services\StudentImportService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (getenv('MIRANDA_FINANCE_TEST') !== '1' || !preg_match('/^miranda_finance_data_test_/', (string)getenv('DB_NAME'))) {
    fwrite(STDERR, "Refusing unsafe database.\n");
    exit(2);
}
function importExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function importCsv(string $content): array {
    $path = tempnam(sys_get_temp_dir(), 'miranda-import-');
    file_put_contents($path, $content);
    return ['tmp_name' => $path, 'name' => 'students.csv'];
}
function importXlsx(array $rows): array {
    $path = tempnam(sys_get_temp_dir(), 'miranda-import-');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $cells = '';
    foreach ($rows as $rowNumber => $row) {
        $rowCells = '';
        foreach (array_values($row) as $column => $value) {
            $reference = chr(65 + $column) . ($rowNumber + 1);
            $rowCells .= '<c r="' . $reference . '" t="inlineStr"><is><t>' . htmlspecialchars((string)$value, ENT_XML1) . '</t></is></c>';
        }
        $cells .= '<row r="' . ($rowNumber + 1) . '">' . $rowCells . '</row>';
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $cells . '</sheetData></worksheet>');
    $zip->close();
    return ['tmp_name' => $path, 'name' => 'students.xlsx'];
}

$pdo = Database::connect();
$suffix = (string)getmypid();
$insertSchool = $pdo->prepare('INSERT INTO schools(name,code,slug,email_domain,status) VALUES(?,?,?,? ,"ACTIVE")');
$insertSchool->execute(['Import A', 'IA'.$suffix, 'import-a-'.$suffix, 'import-a-'.$suffix.'.test']);
$schoolA = (int)$pdo->lastInsertId();
$insertSchool->execute(['Import B', 'IB'.$suffix, 'import-b-'.$suffix, 'import-b-'.$suffix.'.test']);
$schoolB = (int)$pdo->lastInsertId();
$insertClass = $pdo->prepare('INSERT INTO class_levels(school_id,name,level_name,group_name,school_year,status) VALUES(?,?,?,?,?,"ACTIVE")');
$insertClass->execute([$schoolA, '1AC 1', '1AC', '1', '2026/2027']); $classA1 = (int)$pdo->lastInsertId();
$insertClass->execute([$schoolA, '1AC 2', '1AC', '2', '2026/2027']); $classA2 = (int)$pdo->lastInsertId();
$insertClass->execute([$schoolA, '1AC Historique', '1AC', 'H', '2025/2026']); $classOld = (int)$pdo->lastInsertId();
$insertClass->execute([$schoolB, '1AC B', '1AC', 'B', '2026/2027']); $classB = (int)$pdo->lastInsertId();

Request::set('auth_user', ['id' => 1, 'role' => 'admin', 'school_id' => $schoolA]);
$service = new StudentImportService();
$csv = importCsv("Nom,Prénom,Code Massar,Date de naissance\nAit Alpha,Amal,MASSAR-{$suffix}-1,2012-01-02\nAit Beta,Youssef,MASSAR-{$suffix}-2,2012-02-03\n");
$selectionA1 = ['class_level_id' => $classA1, 'school_year' => '2026/2027'];
$before = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE school_id=' . $schoolA)->fetchColumn();
$preview = $service->preview($csv, $selectionA1);
importExpect(!isset($preview['error']) && $preview['preview'] === true && $preview['report']['ready'] === 2, 'Preview did not validate importable rows');
importExpect((int)$pdo->query('SELECT COUNT(*) FROM students WHERE school_id=' . $schoolA)->fetchColumn() === $before, 'Preview wrote student data');
$result = $service->import($csv, $selectionA1 + ['confirm' => true]);
importExpect(($result['created_count'] ?? 0) === 2 && (int)$result['class_level_id'] === $classA1, 'Import into first parallel class failed');
$classCount = $pdo->prepare('SELECT COUNT(*) FROM students WHERE school_id=? AND class_level_id=?'); $classCount->execute([$schoolA, $classA1]);
importExpect((int)$classCount->fetchColumn() === 2, 'Students were not assigned to the selected first class');
$financial = $pdo->prepare('SELECT COUNT(*) FROM monthly_fees mf JOIN students s ON s.id=mf.student_id WHERE s.school_id=?'); $financial->execute([$schoolA]);
importExpect((int)$financial->fetchColumn() === 0, 'Import created monthly fees');
$financialItems = $pdo->prepare('SELECT COUNT(*) FROM student_financial_items fi JOIN students s ON s.id=fi.student_id WHERE s.school_id=?'); $financialItems->execute([$schoolA]);
importExpect((int)$financialItems->fetchColumn() === 0, 'Import created financial items');

$csvSecond = importCsv("Nom,Prénom,Code Massar\nAit Gamma,Salma,MASSAR-{$suffix}-3\n");
$resultSecond = $service->import($csvSecond, ['class_level_id' => $classA2, 'school_year' => '2026/2027', 'confirm' => true]);
importExpect(($resultSecond['created_count'] ?? 0) === 1 && (int)$resultSecond['class_level_id'] === $classA2, 'Import into second parallel class failed');
$xlsx = importXlsx([['Nom', 'Prénom', 'Code Massar'], ['Xlsx', 'Student', 'MASSAR-'.$suffix.'-4']]);
$xlsxResult = $service->import($xlsx, ['class_level_id' => $classA2, 'school_year' => '2026/2027', 'confirm' => true]);
importExpect(($xlsxResult['created_count'] ?? 0) === 1, 'Valid XLSX import failed');
$again = $service->import($csv, $selectionA1 + ['confirm' => true]);
importExpect(($again['created_count'] ?? -1) === 0 && ($again['duplicate_count'] ?? 0) === 2, 'Reimport created duplicates');

$duplicateFile = importCsv("Nom,Prénom,Code Massar\nDup,A,MASSAR-{$suffix}-D\nDup,B,MASSAR-{$suffix}-D\n");
$duplicatePreview = $service->preview($duplicateFile, $selectionA1);
importExpect(($duplicatePreview['report']['duplicates'] ?? 0) === 1, 'Duplicate Massar in file was not detected');
$existingFile = importCsv("Nom,Prénom,Code Massar\nAgain,Alpha,MASSAR-{$suffix}-1\n");
importExpect(($service->preview($existingFile, $selectionA1)['report']['duplicates'] ?? 0) === 1, 'Existing Massar was not detected');
$ambiguousFile = importCsv("Nom,Prénom\nAit Alpha,Amal\n");
importExpect(($service->preview($ambiguousFile, $selectionA1)['report']['ambiguous'] ?? 0) === 1, 'Ambiguous name without Massar was not blocked');
$invalidFile = importCsv("Nom,Email\nBad,test@example.test\n");
importExpect(isset($service->preview($invalidFile, $selectionA1)['error']), 'Invalid file columns were accepted');
importExpect(isset($service->preview($csv, ['class_level_id' => $classB, 'school_year' => '2026/2027'])['error']), 'Foreign-school class was accepted');
importExpect(isset($service->preview($csv, ['class_level_id' => $classOld, 'school_year' => '2026/2027'])['error']), 'Wrong-school-year class was accepted');
importExpect(isset($service->import($csvSecond, ['class_level_id' => $classA2, 'school_year' => '2026/2027'])['error']), 'Import without confirmation was accepted');

foreach ([$csv, $csvSecond, $xlsx, $duplicateFile, $existingFile, $ambiguousFile, $invalidFile] as $file) { @unlink($file['tmp_name']); }
echo "student class import integration: OK\n";
