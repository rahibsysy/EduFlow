<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class SchoolImportService
{
    private const REQUIRED_COLUMNS = [
        'nom' => 'Nom',
        'prenom' => 'Prénom',
        'date_de_naissance' => 'Date de naissance',
        'sexe' => 'Sexe',
        'classe' => 'Classe',
        'niveau_scolaire' => 'Niveau scolaire',
        'nom_du_parent' => 'Nom du parent',
        'telephone' => 'Téléphone',
        'adresse' => 'Adresse',
        'montant_de_la_mensualite' => 'Montant de la mensualité',
    ];

    public function import(int $schoolId, array $file): array
    {
        if ($schoolId <= 0) {
            return ['error' => 'School is required for import'];
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        $name = (string)($file['name'] ?? '');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['error' => 'Fichier import invalide ou manquant.'];
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx', 'xls'], true)) {
            return ['error' => 'Format incorrect. Formats acceptés: .xlsx, .xls, .csv.'];
        }

        if ($extension === 'xls' && !class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return ['error' => 'Le format .xls nécessite PhpSpreadsheet. Utilisez .xlsx ou .csv pour cet environnement.'];
        }

        try {
            $rows = $extension === 'csv'
                ? $this->readCsv($tmpPath)
                : $this->readXlsx($tmpPath);
        } catch (RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        if (count($rows) < 2) {
            return ['error' => 'Le fichier doit contenir une ligne d’en-tête et au moins un élève.'];
        }

        $header = array_map(fn ($value) => $this->normalizeHeader((string)$value), $rows[0]);
        $missing = array_values(array_filter(
            array_keys(self::REQUIRED_COLUMNS),
            fn ($key) => !in_array($key, $header, true)
        ));

        if ($missing) {
            $labels = array_map(fn ($key) => self::REQUIRED_COLUMNS[$key], $missing);
            return ['error' => 'Colonnes manquantes: ' . implode(', ', $labels) . '.'];
        }

        $indexes = [];
        foreach ($header as $index => $key) {
            $indexes[$key] = $index;
        }

        $studentService = new StudentService();
        $pdo = Database::connect();
        $imported = 0;
        $rowErrors = [];

        $pdo->beginTransaction();
        foreach (array_slice($rows, 1) as $offset => $row) {
            $rowNumber = $offset + 2;
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $payload = $this->rowToStudentPayload($schoolId, $row, $indexes);
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
                'error' => 'Import annulé. Corrigez les erreurs puis réessayez.',
                'details' => array_slice($rowErrors, 0, 12),
            ];
        }

        if ($imported === 0) {
            $pdo->rollBack();
            return ['error' => 'Aucune ligne élève valide à importer.'];
        }

        $pdo->commit();

        return [
            'message' => "Import terminé avec succès: {$imported} élève(s) importé(s).",
            'imported_count' => $imported,
        ];
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
            throw new RuntimeException('Le fichier Excel ne contient pas de première feuille lisible.');
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

    private function rowToStudentPayload(int $schoolId, array $row, array $indexes): array
    {
        $value = fn (string $key): string => trim((string)($row[$indexes[$key]] ?? ''));

        return [
            'school_id' => $schoolId,
            'last_name' => $value('nom'),
            'first_name' => $value('prenom'),
            'date_of_birth' => $this->normalizeDate($value('date_de_naissance')),
            'gender' => $value('sexe'),
            'class_name' => $value('classe'),
            'class_level' => $value('niveau_scolaire'),
            'school_year' => $value('classe'),
            'parent_name' => $value('nom_du_parent'),
            'parent_phone' => $value('telephone'),
            'address' => $value('adresse'),
            'monthly_amount' => $this->normalizeAmount($value('montant_de_la_mensualite')),
            'discount_percent' => 0,
            'status' => 'ACTIVE',
        ];
    }

    private function validatePayload(array $payload, int $rowNumber): ?string
    {
        foreach (['last_name', 'first_name', 'class_level', 'parent_name', 'parent_phone'] as $field) {
            if (trim((string)($payload[$field] ?? '')) === '') {
                return "Ligne {$rowNumber}: champ obligatoire vide ({$field}).";
            }
        }

        if ((float)($payload['monthly_amount'] ?? 0) <= 0) {
            return "Ligne {$rowNumber}: montant de mensualité invalide.";
        }

        return null;
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

        if (is_numeric($value)) {
            $timestamp = ((int)$value - 25569) * 86400;
            return gmdate('Y-m-d', $timestamp);
        }

        return null;
    }

    private function normalizeAmount(string $value): float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));
        return round((float)$value, 2);
    }

    private function isEmptyRow(array $row): bool
    {
        return implode('', array_map(fn ($value) => trim((string)$value), $row)) === '';
    }
}
