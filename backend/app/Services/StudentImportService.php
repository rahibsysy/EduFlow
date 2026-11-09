<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class StudentImportService
{
    private const REQUIRED_COLUMNS = [
        'nom' => 'Nom',
        'prenom' => 'Prénom',
    ];

    public function import(array $file, array $data): array
    {
        if (!$this->confirmationProvided($data)) {
            return ['error' => 'Confirmation explicite obligatoire avant importation.'];
        }
        $plan = $this->prepare($file, $data);
        if (isset($plan['error'])) {
            return $plan;
        }

        $pdo = Database::connect();
        $studentService = new StudentService();
        $created = 0;
        $ignored = $plan['report']['ignored'];
        $errors = $plan['report']['errors'];
        $pdo->beginTransaction();
        try {
            foreach ($plan['rows'] as $row) {
                if ($row['status'] !== 'ready') {
                    continue;
                }
                $payload = $row['payload'];
                if ($this->massarExists($pdo, $plan['school_id'], (string)$payload['massar_code'], true)) {
                    $ignored++;
                    continue;
                }
                $result = $studentService->create($payload);
                if (isset($result['error'])) {
                    if ($this->massarExists($pdo, $plan['school_id'], (string)$payload['massar_code'])) {
                        $ignored++;
                    } else {
                        $errors[] = 'Ligne ' . $row['line'] . ': ' . $result['error'];
                    }
                    continue;
                }
                $created++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => 'Import interrompu de maniere securisee.'];
        }

        return [
            'message' => "Import termine: {$created} eleve(s) cree(s).",
            'created_count' => $created,
            'ignored_count' => $ignored,
            'duplicate_count' => $plan['report']['duplicates'],
            'error_count' => count($errors),
            'errors' => array_slice($errors, 0, 20),
            'class_level_id' => $plan['class']['id'],
            'class_name' => $plan['class']['name'],
            'school_year' => $plan['school_year'],
        ];
    }

    public function preview(array $file, array $data): array
    {
        $plan = $this->prepare($file, $data);
        if (isset($plan['error'])) {
            return $plan;
        }
        return [
            'preview' => true,
            'class_level_id' => $plan['class']['id'],
            'class_name' => $plan['class']['name'],
            'level_name' => $plan['class']['level_name'] ?? '',
            'school_year' => $plan['school_year'],
            'report' => $plan['report'],
            'rows' => array_map(fn (array $row) => [
                'line' => $row['line'], 'status' => $row['status'], 'reason' => $row['reason'],
                'first_name' => $row['payload']['first_name'] ?? '', 'last_name' => $row['payload']['last_name'] ?? '',
                'massar_code' => $row['payload']['massar_code'] ?? '',
            ], $plan['rows']),
        ];
    }

    private function rowToPayload(
        int $schoolId,
        int $classLevelId,
        string $className,
        string $schoolYear,
        float $defaultAmount,
        array $row,
        array $indexes,
        int $rowNumber
    ): array {
        $value = fn (string ...$keys): string => $this->firstValue($row, $indexes, $keys);
        $lastName = $value('nom');
        $parentName = $value('nom_du_parent', 'parent', 'responsable');
        $parentPhone = $value('telephone', 'telephone_parent', 'tel', 'gsm', 'phone');

        if ($parentName === '') {
            $parentName = 'Parent ' . $lastName;
        }

        if ($parentPhone === '') {
            $parentPhone = sprintf('IMP-%d-%d-%d', $schoolId, $classLevelId, $rowNumber);
        }

        return [
            'school_id' => $schoolId,
            'massar_code' => $value('massar_code', 'code_massar', 'massar'),
            'last_name' => $lastName,
            'first_name' => $value('prenom'),
            'date_of_birth' => $this->normalizeDate($value('date_de_naissance')),
            'gender' => $value('sexe', 'genre'),
            'class_name' => $className,
            'class_level_id' => $classLevelId,
            'school_year' => $schoolYear,
            'parent_name' => $parentName,
            'parent_phone' => $parentPhone,
            'address' => $value('adresse'),
            'monthly_amount' => $defaultAmount,
            'discount_percent' => 0,
            'status' => 'ACTIVE',
        ];
    }

    /**
     * Parse and validate an import without writing business data.  The selected
     * class is the authority: workbook metadata never creates or changes a class.
     */
    private function prepare(array $file, array $data): array
    {
        [$role, $schoolId] = $this->authScope();
        if ($role === 'super_admin') {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
        }
        if (!$schoolId || $schoolId <= 0) {
            return ['error' => 'Ecole obligatoire pour importer des eleves.'];
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? '');
        if ($tmpPath === '' || !$this->isAcceptedUpload($tmpPath)) {
            return ['error' => 'Fichier import invalide ou manquant.'];
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            return ['error' => 'Format incorrect. Formats acceptes: .xlsx ou .csv.'];
        }

        $schoolYear = $this->normalizeSchoolYear((string)($data['school_year'] ?? ''));
        $classLevelId = (int)($data['class_level_id'] ?? 0);
        if ($schoolYear === '' || $classLevelId <= 0) {
            return ['error' => 'Annee scolaire et classe precise obligatoires.'];
        }

        $pdo = Database::connect();
        $class = $this->selectedClass($pdo, $schoolId, $classLevelId, $schoolYear);
        if (isset($class['error'])) {
            return $class;
        }

        try {
            $rows = $extension === 'csv' ? $this->readCsv($tmpPath) : $this->readXlsx($tmpPath);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
        $headerInfo = $this->findHeaderRow($rows);
        if ($headerInfo === null) {
            return ['error' => 'Colonnes manquantes: Nom, Prénom.'];
        }
        [$headerRowIndex, $indexes] = $headerInfo;
        $studentRows = array_slice($rows, $headerRowIndex + 1);
        if (!$studentRows) {
            return ['error' => 'Le fichier doit contenir au moins un eleve.'];
        }

        $plannedRows = [];
        $seenMassar = [];
        $report = ['ready' => 0, 'ignored' => 0, 'duplicates' => 0, 'invalid' => 0, 'ambiguous' => 0, 'errors' => []];
        foreach ($studentRows as $offset => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }
            $line = $headerRowIndex + $offset + 2;
            $payload = $this->rowToPayload(
                $schoolId,
                (int)$class['id'],
                (string)$class['name'],
                $schoolYear,
                0.0,
                $row,
                $indexes,
                $line
            );
            $reason = $this->validatePayload($payload, $line);
            $status = 'ready';
            $massar = trim((string)$payload['massar_code']);
            if ($reason !== null) {
                $status = 'invalid';
                $report['invalid']++;
                $report['errors'][] = $reason;
            } elseif ($massar !== '') {
                $key = strtolower($massar);
                if (isset($seenMassar[$key]) || $this->massarExists($pdo, $schoolId, $massar)) {
                    $status = 'duplicate';
                    $reason = "Ligne {$line}: code Massar deja present.";
                    $report['ignored']++;
                    $report['duplicates']++;
                }
                $seenMassar[$key] = true;
            } elseif ($this->potentialMatchExists($pdo, $schoolId, $payload)) {
                $status = 'ambiguous';
                $reason = "Ligne {$line}: correspondance potentielle sans code Massar; verification manuelle requise.";
                $report['ambiguous']++;
                $report['ignored']++;
            }
            if ($status === 'ready') {
                $report['ready']++;
            }
            $plannedRows[] = compact('line', 'status', 'reason', 'payload');
        }
        if (!$plannedRows) {
            return ['error' => 'Le fichier ne contient aucune ligne eleve.'];
        }

        return [
            'school_id' => $schoolId,
            'school_year' => $schoolYear,
            'class' => $class,
            'rows' => $plannedRows,
            'report' => $report,
        ];
    }

    private function isAcceptedUpload(string $path): bool
    {
        return is_uploaded_file($path)
            || ((string)getenv('MIRANDA_FINANCE_TEST') === '1' && is_file($path));
    }

    private function confirmationProvided(array $data): bool
    {
        return in_array($data['confirm'] ?? null, [true, 1, '1', 'true'], true);
    }

    private function selectedClass(\PDO $pdo, int $schoolId, int $classLevelId, string $schoolYear): array
    {
        $stmt = $pdo->prepare('SELECT * FROM class_levels WHERE id = ? AND school_id = ? AND status = "ACTIVE" LIMIT 1');
        $stmt->execute([$classLevelId, $schoolId]);
        $class = $stmt->fetch();
        if (!$class) {
            return ['error' => 'Classe invalide pour cette ecole.'];
        }
        if ($this->normalizeSchoolYear((string)($class['school_year'] ?? '')) !== $schoolYear) {
            return ['error' => 'La classe ne correspond pas a l annee scolaire selectionnee.'];
        }
        return $class;
    }

    private function massarExists(\PDO $pdo, int $schoolId, string $massar, bool $forUpdate = false): bool
    {
        if ($massar === '') {
            return false;
        }
        $sql = 'SELECT id FROM students WHERE school_id = ? AND massar_code = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$schoolId, $massar]);
        return (bool)$stmt->fetchColumn();
    }

    private function potentialMatchExists(\PDO $pdo, int $schoolId, array $payload): bool
    {
        $sql = 'SELECT id FROM students WHERE school_id = ? AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?)';
        $values = [$schoolId, (string)$payload['first_name'], (string)$payload['last_name']];
        if (!empty($payload['date_of_birth'])) {
            $sql .= ' AND date_of_birth = ?';
            $values[] = $payload['date_of_birth'];
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        return (bool)$stmt->fetchColumn();
    }

    private function validatePayload(array $payload, int $rowNumber): ?string
    {
        foreach (['last_name', 'first_name'] as $field) {
            if (trim((string)($payload[$field] ?? '')) === '') {
                return "Ligne {$rowNumber}: champ obligatoire vide ({$field}).";
            }
        }

        if ((float)($payload['monthly_amount'] ?? 0) < 0) {
            return "Ligne {$rowNumber}: montant de mensualite invalide.";
        }

        $discount = (float)($payload['discount_percent'] ?? 0);
        if ($discount < 0 || $discount > 100) {
            return "Ligne {$rowNumber}: reduction invalide.";
        }

        return null;
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new RuntimeException('Impossible de lire le fichier CSV.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            return [];
        }
        rewind($handle);

        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = array_map(fn ($value) => trim((string)$value), $row);
        }

        fclose($handle);
        return $rows;
    }

    private function findHeaderRow(array $rows): ?array
    {
        foreach ($rows as $rowIndex => $row) {
            $header = array_map(fn ($value) => $this->normalizeHeader((string)$value), $row);
            $missing = array_values(array_filter(
                array_keys(self::REQUIRED_COLUMNS),
                fn ($key) => !in_array($key, $header, true)
            ));

            if ($missing) {
                continue;
            }

            $indexes = [];
            foreach ($header as $index => $key) {
                if ($key !== '' && !isset($indexes[$key])) {
                    $indexes[$key] = $index;
                }
            }

            return [$rowIndex, $indexes];
        }

        return null;
    }

    private function firstValue(array $row, array $indexes, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($indexes[$key])) {
                continue;
            }

            $value = trim((string)($row[$indexes[$key]] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function extractMetadata(array $rows, int $headerRowIndex): array
    {
        $metadata = [
            'class_name' => '',
            'level_name' => '',
            'group_name' => '',
            'school_year' => '',
        ];

        foreach (array_slice($rows, 0, $headerRowIndex) as $row) {
            foreach ($row as $cellIndex => $cell) {
                $raw = trim((string)$cell);
                if ($raw === '') {
                    continue;
                }

                $inlineValue = $this->valueAfterInlineLabel($raw);
                $key = $this->normalizeHeader($inlineValue['label']);
                $value = $inlineValue['value'] !== ''
                    ? $inlineValue['value']
                    : $this->valueAfterCellLabel($row, $cellIndex);

                if ($value === '') {
                    continue;
                }

                if ($key === 'classe') {
                    $metadata['class_name'] = $value;
                    $metadata['group_name'] = $value;
                } elseif ($key === 'niveau') {
                    $metadata['level_name'] = $value;
                } elseif ($key === 'annee_scolaire') {
                    $metadata['school_year'] = $value;
                }
            }
        }

        return $metadata;
    }

    private function valueAfterInlineLabel(string $raw): array
    {
        $parts = preg_split('/\s*:\s*/u', $raw, 2);
        return [
            'label' => trim((string)($parts[0] ?? $raw)),
            'value' => isset($parts[1]) ? trim((string)$parts[1]) : '',
        ];
    }

    private function valueAfterCellLabel(array $row, int $labelIndex): string
    {
        $max = count($row);
        for ($index = $labelIndex + 1; $index < $max; $index++) {
            $value = trim((string)($row[$index] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeSchoolYear(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/(\d{4})\D+(\d{4})/', $value, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }

        return $value;
    }

    private function resolveOrCreateClassLevel(
        \PDO $pdo,
        int $schoolId,
        int $requestedClassLevelId,
        string $className,
        string $groupName,
        string $levelName,
        string $schoolYear
    ): array {
        if ($requestedClassLevelId > 0) {
            $stmt = $pdo->prepare('SELECT * FROM class_levels WHERE id = ? AND school_id = ? LIMIT 1');
            $stmt->execute([$requestedClassLevelId, $schoolId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                return ['error' => 'Classe invalide pour cette ecole'];
            }

            $this->updateClassLevelMetadata($pdo, (int)$existing['id'], $groupName, $levelName, $schoolYear);
            return $existing;
        }

        $stmt = $pdo->prepare('SELECT * FROM class_levels WHERE school_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$schoolId, $className]);
        $existing = $stmt->fetch();
        if ($existing) {
            $this->updateClassLevelMetadata($pdo, (int)$existing['id'], $groupName, $levelName, $schoolYear);
            return $existing;
        }

        $nextSortOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_value FROM class_levels WHERE school_id = ?');
        $nextSortOrderStmt->execute([$schoolId]);
        $sortOrder = (int)($nextSortOrderStmt->fetch()['next_value'] ?? 1);

        $columns = ['school_id', 'name', 'code', 'sort_order', 'status'];
        $values = [$schoolId, $className, $className, $sortOrder, 'ACTIVE'];

        if ($this->classLevelColumnExists($pdo, 'level_name')) {
            $columns[] = 'level_name';
            $values[] = $levelName !== '' ? $levelName : null;
        }

        if ($this->classLevelColumnExists($pdo, 'group_name')) {
            $columns[] = 'group_name';
            $values[] = $groupName !== '' ? $groupName : null;
        }

        if ($this->classLevelColumnExists($pdo, 'school_year')) {
            $columns[] = 'school_year';
            $values[] = $schoolYear !== '' ? $schoolYear : null;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf(
            'INSERT INTO class_levels (%s) VALUES (%s)',
            implode(', ', $columns),
            $placeholders
        );
        $insert = $pdo->prepare($sql);
        $insert->execute($values);

        $classLevelId = (int)$pdo->lastInsertId();
        (new SubjectService())->ensureSubjectsForClassLevel($classLevelId);

        return [
            'id' => $classLevelId,
            'school_id' => $schoolId,
            'name' => $className,
            'code' => $className,
            'sort_order' => $sortOrder,
            'status' => 'ACTIVE',
            'level_name' => $levelName,
            'group_name' => $groupName,
            'school_year' => $schoolYear,
        ];
    }

    private function updateClassLevelMetadata(\PDO $pdo, int $classLevelId, string $groupName, string $levelName, string $schoolYear): void
    {
        $sets = [];
        $values = [];

        if ($levelName !== '' && $this->classLevelColumnExists($pdo, 'level_name')) {
            $sets[] = 'level_name = ?';
            $values[] = $levelName;
        }

        if ($groupName !== '' && $this->classLevelColumnExists($pdo, 'group_name')) {
            $sets[] = 'group_name = ?';
            $values[] = $groupName;
        }

        if ($schoolYear !== '' && $this->classLevelColumnExists($pdo, 'school_year')) {
            $sets[] = 'school_year = ?';
            $values[] = $schoolYear;
        }

        if (!$sets) {
            return;
        }

        $values[] = $classLevelId;
        $stmt = $pdo->prepare('UPDATE class_levels SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    private function classLevelColumnExists(\PDO $pdo, string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            $stmt = $pdo->query("
                SELECT column_name AS column_name
                FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name = 'class_levels'
            ");
            $columns = array_fill_keys(array_map('strtolower', array_column($stmt->fetchAll(), 'column_name')), true);
        }

        return isset($columns[strtolower($column)]);
    }

    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Impossible de lire le fichier Excel.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('Le fichier Excel ne contient pas de premiere feuille lisible.');
        }

        $xml = simplexml_load_string($sheetXml);
        if (!$xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Le fichier Excel est invalide.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $rowNode) {
            $row = [];
            foreach ($rowNode->c as $cell) {
                $cellRef = (string)$cell['r'];
                $columnIndex = $this->columnIndexFromReference($cellRef);
                $row[$columnIndex] = $this->cellValue($cell, $sharedStrings);
            }

            if ($row) {
                ksort($row);
                $max = max(array_keys($row));
                $rows[] = array_map(fn ($index) => $row[$index] ?? '', range(0, $max));
            }
        }

        return $rows;
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xmlString = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlString === false) {
            return [];
        }

        $xml = simplexml_load_string($xmlString);
        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string)$item->t;
                continue;
            }

            $text = '';
            foreach ($item->r as $run) {
                $text .= (string)$run->t;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string)$cell['t'];
        if ($type === 's') {
            $index = (int)$cell->v;
            return trim((string)($sharedStrings[$index] ?? ''));
        }

        if ($type === 'inlineStr') {
            return trim((string)($cell->is->t ?? ''));
        }

        return trim((string)($cell->v ?? ''));
    }

    private function columnIndexFromReference(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference));
        $index = 0;
        foreach (str_split((string)$letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string)$value, '_');
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[3], (int)$matches[2], (int)$matches[1]);
        }

        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int)$matches[1], (int)$matches[2], (int)$matches[3]);
        }

        if (is_numeric($value)) {
            $timestamp = ((int)$value - 25569) * 86400;
            return gmdate('Y-m-d', $timestamp);
        }

        return null;
    }

    private function isEmptyRow(array $row): bool
    {
        return implode('', array_map(fn ($value) => trim((string)$value), $row)) === '';
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }
}
