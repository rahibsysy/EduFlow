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
        [$role, $schoolId] = $this->authScope();

        if ($role === 'super_admin') {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
        }

        if (!$schoolId || $schoolId <= 0) {
            return ['error' => 'Ecole obligatoire pour importer des eleves'];
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['error' => 'Fichier import invalide ou manquant.'];
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            return ['error' => 'Format incorrect. Formats acceptes: .xlsx, .xls, .csv.'];
        }

        if ($extension === 'xls' && !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return ['error' => 'Le format .xls necessite PhpSpreadsheet. Utilisez .xlsx ou .csv.'];
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
        $metadata = $this->extractMetadata($rows, $headerRowIndex);
        $studentRows = array_slice($rows, $headerRowIndex + 1);
        if (!$studentRows) {
            return ['error' => 'Le fichier doit contenir au moins un eleve.'];
        }

        $schoolYear = $this->normalizeSchoolYear((string)($data['school_year'] ?? ''));
        if ($schoolYear === '') {
            $schoolYear = $this->normalizeSchoolYear($metadata['school_year']);
        }

        $className = trim((string)($data['class_name'] ?? ''));
        if ($className === '') {
            $className = $metadata['class_name'];
        }

        if ($className === '') {
            return ['error' => 'Classe introuvable dans le fichier.'];
        }

        $levelName = trim((string)($data['level_name'] ?? ''));
        if ($levelName === '') {
            $levelName = $metadata['level_name'];
        }
        $groupName = trim((string)($data['group_name'] ?? ''));
        if ($groupName === '') {
            $groupName = $metadata['group_name'] !== '' ? $metadata['group_name'] : $className;
        }

        $defaultAmount = round((float)($data['monthly_amount'] ?? 0), 2);
        if ($defaultAmount < 0) {
            return ['error' => 'La mensualite ne peut pas etre negative'];
        }

        $pdo = Database::connect();
        $studentService = new StudentService();
        $imported = 0;
        $rowErrors = [];

        $pdo->beginTransaction();
        $classLevel = $this->resolveOrCreateClassLevel(
            $pdo,
            $schoolId,
            isset($data['class_level_id']) ? (int)$data['class_level_id'] : 0,
            $className,
            $groupName,
            $levelName,
            $schoolYear
        );
        if (isset($classLevel['error'])) {
            $pdo->rollBack();
            return $classLevel;
        }

        $classLevelId = (int)$classLevel['id'];
        foreach ($studentRows as $offset => $row) {
            $rowNumber = $headerRowIndex + $offset + 2;
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $payload = $this->rowToPayload(
                $schoolId,
                $classLevelId,
                $className,
                $schoolYear,
                $defaultAmount,
                $row,
                $indexes,
                $rowNumber
            );
            $validationError = $this->validatePayload($payload, $rowNumber);
            if ($validationError !== null) {
                $rowErrors[] = $validationError;
                continue;
            }

            $created = $studentService->create($payload);
            if (isset($created['error'])) {
                $rowErrors[] = "Ligne {$rowNumber}: " . $created['error'];
                continue;
            }

            $imported++;
        }

        if ($rowErrors) {
            $pdo->rollBack();
            return [
                'error' => 'Import annule. Corrigez les erreurs puis reessayez.',
                'details' => array_slice($rowErrors, 0, 12),
            ];
        }

        if ($imported === 0) {
            $pdo->rollBack();
            return ['error' => 'Aucune ligne eleve valide a importer.'];
        }

        $pdo->commit();

        return [
            'message' => "Import termine avec succes: {$imported} eleve(s) importe(s) dans {$className}.",
            'imported_count' => $imported,
            'class_level_id' => $classLevelId,
            'class_name' => $className,
            'group_name' => $groupName,
            'level_name' => $levelName,
            'school_year' => $schoolYear,
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
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
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
