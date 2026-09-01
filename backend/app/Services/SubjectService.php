<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;
use PDOException;

class SubjectService
{
    public function getAll(array $filters = []): array
    {
        try {
            $pdo = Database::connect();
            $this->seedBaseSubjects($pdo);
            [$role, $schoolId] = $this->authScope();

            $classLevelId = isset($filters['class_level_id']) ? (int)$filters['class_level_id'] : 0;
            if ($classLevelId > 0) {
                $classLevel = $this->findClassLevel($pdo, $classLevelId);
                if (!$classLevel) {
                    return [];
                }

                if ($role !== 'super_admin' && (int)$classLevel['school_id'] !== $schoolId) {
                    return [];
                }

                $this->ensureSubjectsForClassLevel($classLevelId);

                $stmt = $pdo->prepare('
                    SELECT sub.id, sub.name, sub.code, sub.status, COALESCE(scl.weekly_hours, 0) AS weekly_hours
                    FROM subjects sub
                    INNER JOIN subject_class_levels scl ON scl.subject_id = sub.id
                    WHERE scl.class_level_id = ?
                      AND sub.status = "ACTIVE"
                    ORDER BY sub.sort_order ASC, sub.name ASC
                ');
                $stmt->execute([$classLevelId]);
                return $stmt->fetchAll();
            }

            $stmt = $pdo->query('
                SELECT id, name, code, status, 0 AS weekly_hours
                FROM subjects
                WHERE status = "ACTIVE"
                ORDER BY sort_order ASC, name ASC
            ');
            return $stmt->fetchAll();
        } catch (PDOException) {
            return [];
        }
    }

    public function validateSubjectForClassLevel(int $subjectId, int $classLevelId): array|false
    {
        if ($subjectId <= 0 || $classLevelId <= 0) {
            return false;
        }

        $this->ensureSubjectsForClassLevel($classLevelId);

        $pdo = Database::connect();
        $stmt = $pdo->prepare('
            SELECT sub.id, sub.name, sub.code
            FROM subjects sub
            INNER JOIN subject_class_levels scl ON scl.subject_id = sub.id
            WHERE sub.id = ?
              AND scl.class_level_id = ?
              AND sub.status = "ACTIVE"
            LIMIT 1
        ');
        $stmt->execute([$subjectId, $classLevelId]);
        return $stmt->fetch() ?: false;
    }

    public function updateWeeklyHours(int $subjectId, int $classLevelId, int $weeklyHours): void
    {
        if ($subjectId <= 0 || $classLevelId <= 0 || $weeklyHours <= 0) {
            return;
        }

        $pdo = Database::connect();
        $this->ensureSubjectsForClassLevel($classLevelId);
        $stmt = $pdo->prepare('
            UPDATE subject_class_levels
            SET weekly_hours = ?
            WHERE subject_id = ?
              AND class_level_id = ?
        ');
        $stmt->execute([min(40, $weeklyHours), $subjectId, $classLevelId]);
    }

    public function setWeeklyHours(int $subjectId, int $classLevelId, array $data): array
    {
        if ($subjectId <= 0 || $classLevelId <= 0) {
            return ['error' => 'Matiere ou niveau invalide'];
        }

        $weeklyHours = isset($data['weekly_hours']) ? (int)$data['weekly_hours'] : 0;
        if ($weeklyHours < 0 || $weeklyHours > 40) {
            return ['error' => 'Le nombre d heures doit etre entre 0 et 40'];
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();
        $classLevel = $this->findClassLevel($pdo, $classLevelId);
        if (!$classLevel) {
            return ['error' => 'Classe introuvable'];
        }

        if ($role !== 'super_admin' && (int)$classLevel['school_id'] !== (int)$schoolId) {
            return ['error' => 'Forbidden'];
        }

        $this->ensureSubjectsForClassLevel($classLevelId);
        $subject = $this->validateSubjectForClassLevel($subjectId, $classLevelId);
        if (!$subject) {
            return ['error' => 'Cette matiere n est pas autorisee pour ce niveau scolaire'];
        }

        $stmt = $pdo->prepare('
            UPDATE subject_class_levels
            SET weekly_hours = ?
            WHERE subject_id = ?
              AND class_level_id = ?
        ');
        $stmt->execute([$weeklyHours, $subjectId, $classLevelId]);

        return [
            'subject_id' => $subjectId,
            'class_level_id' => $classLevelId,
            'weekly_hours' => $weeklyHours,
            'message' => 'Volume horaire enregistre avec succes',
        ];
    }

    public function ensureSubjectsForClassLevel(int $classLevelId): void
    {
        try {
            $pdo = Database::connect();
            $this->seedBaseSubjects($pdo);
            $classLevel = $this->findClassLevel($pdo, $classLevelId);
            if (!$classLevel) {
                return;
            }

            $allowedCodes = $this->allowedCodesForLevel((string)$classLevel['name'], (string)($classLevel['code'] ?? ''));
            if (!$allowedCodes) {
                return;
            }

            $stmt = $pdo->prepare('SELECT id, code FROM subjects WHERE code IN (' . implode(',', array_fill(0, count($allowedCodes), '?')) . ')');
            $stmt->execute($allowedCodes);

            $insert = $pdo->prepare('INSERT IGNORE INTO subject_class_levels (subject_id, class_level_id) VALUES (?, ?)');
            foreach ($stmt->fetchAll() as $subject) {
                $insert->execute([(int)$subject['id'], $classLevelId]);
            }
        } catch (PDOException) {
            return;
        }
    }

    private function allowedCodesForLevel(string $name, string $code): array
    {
        $normalized = $this->normalize($name . ' ' . $code);
        $college = ['FR', 'AR', 'ANG', 'MATH', 'H.G', 'II', 'EPS', 'SVT', 'PC', 'INFO'];
        $lycee = [...$college, 'PHILO'];

        if (str_contains($normalized, '2 bac') || str_contains($normalized, '2bac') || str_contains($normalized, 'deuxieme bac')) {
            return array_values(array_diff($lycee, ['INFO', 'H.G']));
        }

        if (str_contains($normalized, '1 bac') || str_contains($normalized, '1bac') || str_contains($normalized, 'premiere bac')) {
            return array_values(array_diff($lycee, ['INFO']));
        }

        if (str_contains($normalized, 'tronc commun') || str_contains($normalized, 'tc')) {
            return $lycee;
        }

        return $college;
    }

    private function findClassLevel(PDO $pdo, int $classLevelId): array|false
    {
        $stmt = $pdo->prepare('SELECT id, school_id, name, code FROM class_levels WHERE id = ? LIMIT 1');
        $stmt->execute([$classLevelId]);
        return $stmt->fetch() ?: false;
    }

    private function seedBaseSubjects(PDO $pdo): void
    {
        $subjects = [
            ['Français', 'FR', 1],
            ['Arabe', 'AR', 2],
            ['Anglais', 'ANG', 3],
            ['Mathématiques', 'MATH', 4],
            ['Histoire-Géographie', 'H.G', 5],
            ['Éducation Islamique', 'II', 6],
            ['Sport / EPS', 'EPS', 7],
            ['SVT', 'SVT', 8],
            ['Physique-Chimie', 'PC', 9],
            ['Informatique', 'INFO', 10],
            ['Philosophie', 'PHILO', 11],
        ];

        $stmt = $pdo->prepare('
            INSERT INTO subjects (name, code, sort_order, status)
            VALUES (?, ?, ?, "ACTIVE")
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                sort_order = VALUES(sort_order),
                status = VALUES(status)
        ');

        foreach ($subjects as $subject) {
            $stmt->execute($subject);
        }
    }

    private function normalize(string $value): string
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
        return (string)preg_replace('/[^a-z0-9]+/', ' ', $value);
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }
}
