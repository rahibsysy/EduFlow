<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;
use PDOException;

class TeacherService
{
    private const DEFAULT_PASSWORD = 'EduFlow@123';

    public function getAll(?int $requestedSchoolId = null): array
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $actorSchoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        $sql = '
            SELECT
                u.id,
                u.school_id,
                CONCAT(TRIM(u.first_name), " ", TRIM(u.last_name)) AS name,
                u.first_name,
                u.last_name,
                u.email,
                u.gender,
                u.phone,
                u.address,
                u.primary_school,
                u.role,
                u.status
            FROM users u
            WHERE u.role IN ("professeur", "user")
              AND u.school_id IS NOT NULL
        ';
        $params = [];

        if ($role === 'super_admin') {
            if ($requestedSchoolId && $requestedSchoolId > 0) {
                $sql .= ' AND u.school_id = ?';
                $params[] = $requestedSchoolId;
            }
        } else {
            $sql .= ' AND u.school_id = ?';
            $params[] = $actorSchoolId;
        }

        $stmt = $pdo->prepare($sql . ' ORDER BY u.first_name ASC, u.last_name ASC');
        $stmt->execute($params);
        return $this->attachRelations($pdo, $stmt->fetchAll());
    }

    public function create(array $data): array
    {
        [$actorRole, $actorSchoolId] = $this->authScope();
        if (!in_array($actorRole, ['super_admin', 'admin'], true)) {
            return ['error' => 'Forbidden'];
        }

        $schoolId = $this->resolveSchoolId($data, $actorRole, $actorSchoolId);
        if (!$schoolId || !$this->findSchool($schoolId)) {
            return ['error' => 'Etablissement principal obligatoire'];
        }

        $payload = $this->validatedPayload($data, $schoolId);
        if (isset($payload['error'])) {
            return $payload;
        }

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('
                INSERT INTO users (
                    school_id, first_name, last_name, email, password, role, status,
                    gender, phone, address, primary_school
                )
                VALUES (?, ?, ?, ?, ?, "professeur", ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $schoolId,
                $payload['first_name'],
                $payload['last_name'],
                $payload['email'],
                password_hash(self::DEFAULT_PASSWORD, PASSWORD_DEFAULT),
                $payload['status'],
                $payload['gender'],
                $payload['phone'],
                $payload['address'],
                $payload['primary_school'],
            ]);
            $teacherId = (int)$pdo->lastInsertId();
            $this->syncClassLevels($pdo, $teacherId, $schoolId, $payload['class_level_ids']);
            $this->syncSubjects($pdo, $teacherId, $payload['subject_ids']);

            return array_merge($payload, [
                'id' => $teacherId,
                'school_id' => $schoolId,
                'role' => 'professeur',
                'message' => 'Teacher created successfully',
            ]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                return ['error' => 'Email already exists'];
            }
            if (($e->errorInfo[1] ?? null) === 1054) {
                return ['error' => 'Colonnes professeur manquantes. Lancez la migration 2026_08_29_teacher_profile_and_schedule_type.sql'];
            }
            return ['error' => $this->databaseErrorMessage($e)];
        }
    }

    public function update(int $teacherId, array $data): array
    {
        $existing = $this->findTeacherById($teacherId);
        if (!$existing) {
            return ['error' => 'Teacher not found'];
        }

        [$actorRole, $actorSchoolId] = $this->authScope();
        if (!in_array($actorRole, ['super_admin', 'admin'], true)) {
            return ['error' => 'Forbidden'];
        }

        if ($actorRole !== 'super_admin' && (int)$existing['school_id'] !== $actorSchoolId) {
            return ['error' => 'Forbidden'];
        }

        $schoolId = $actorRole === 'super_admin'
            ? $this->resolveSchoolId($data, $actorRole, (int)$existing['school_id'])
            : (int)$existing['school_id'];
        if (!$schoolId || !$this->findSchool($schoolId)) {
            return ['error' => 'Etablissement principal obligatoire'];
        }

        $payload = $this->validatedPayload(array_merge($existing, $data), $schoolId, $teacherId);
        if (isset($payload['error'])) {
            return $payload;
        }

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('
                UPDATE users
                SET school_id = ?, first_name = ?, last_name = ?, email = ?, status = ?,
                    gender = ?, phone = ?, address = ?, primary_school = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $schoolId,
                $payload['first_name'],
                $payload['last_name'],
                $payload['email'],
                $payload['status'],
                $payload['gender'],
                $payload['phone'],
                $payload['address'],
                $payload['primary_school'],
                $teacherId,
            ]);

            $teacherName = trim($payload['first_name'] . ' ' . $payload['last_name']);
            $pdo->prepare('UPDATE schedules SET teacher_name = ? WHERE teacher_id = ?')->execute([$teacherName, $teacherId]);
            $this->syncClassLevels($pdo, $teacherId, $schoolId, $payload['class_level_ids']);
            $this->syncSubjects($pdo, $teacherId, $payload['subject_ids']);

            return array_merge($payload, [
                'id' => $teacherId,
                'school_id' => $schoolId,
                'role' => 'professeur',
                'message' => 'Teacher updated successfully',
            ]);
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                return ['error' => 'Email already exists'];
            }
            if (($e->errorInfo[1] ?? null) === 1054) {
                return ['error' => 'Colonnes professeur manquantes. Lancez la migration 2026_08_29_teacher_profile_and_schedule_type.sql'];
            }
            return ['error' => $this->databaseErrorMessage($e)];
        }
    }

    public function delete(int $teacherId): array
    {
        [$actorRole, $actorSchoolId] = $this->authScope();
        if (!in_array($actorRole, ['super_admin', 'admin'], true)) {
            return ['error' => 'Forbidden'];
        }

        $teacher = $this->findTeacherById($teacherId);
        if (!$teacher) {
            return ['error' => 'Teacher not found'];
        }

        if ($actorRole !== 'super_admin' && (int)$teacher['school_id'] !== $actorSchoolId) {
            return ['error' => 'Forbidden'];
        }

        try {
            Database::connect()->prepare('DELETE FROM users WHERE id = ? AND role IN ("professeur", "user")')->execute([$teacherId]);
            return ['id' => $teacherId, 'message' => 'Teacher deleted successfully'];
        } catch (PDOException) {
            return ['error' => 'Teacher deletion failed'];
        }
    }

    private function validatedPayload(array $data, int $schoolId, ?int $ignoreUserId = null): array
    {
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $gender = strtoupper(trim((string)($data['gender'] ?? '')));
        $phone = trim((string)($data['phone'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $primarySchool = trim((string)($data['primary_school'] ?? ''));
        $status = strtoupper(trim((string)($data['status'] ?? 'ACTIVE')));
        $classLevelIds = $this->normalizeClassLevelIds($data['class_level_ids'] ?? []);
        $subjectIds = $this->normalizeIds($data['subject_ids'] ?? []);

        if ($firstName === '' || $lastName === '') {
            return ['error' => 'Nom et prenom obligatoires'];
        }

        if (!in_array($gender, ['MALE', 'FEMALE'], true)) {
            return ['error' => 'Sexe obligatoire'];
        }

        if ($phone === '' || $address === '') {
            return ['error' => 'Telephone et adresse obligatoires'];
        }

        if ($primarySchool === '') {
            return ['error' => 'Etablissement principal obligatoire'];
        }

        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Statut invalide'];
        }

        $email = trim(strtolower((string)($data['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Email invalide'];
        }

        if ($email === '') {
            $email = $this->generateUniqueEmail($this->sanitizeLocalPart($firstName . '.' . $lastName), $schoolId, $ignoreUserId);
        }

        $validClassLevelIds = $this->validClassLevelIds($schoolId, $classLevelIds);
        if ($classLevelIds && count($validClassLevelIds) !== count($classLevelIds)) {
            return ['error' => 'Un des niveaux choisis est invalide pour cette ecole'];
        }

        $validSubjectIds = $this->validSubjectIds($subjectIds);
        if ($subjectIds && count($validSubjectIds) !== count($subjectIds)) {
            return ['error' => 'Une des matieres choisies est invalide'];
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'gender' => $gender,
            'phone' => $phone,
            'address' => $address,
            'primary_school' => $primarySchool,
            'status' => $status,
            'class_level_ids' => $validClassLevelIds,
            'subject_ids' => $validSubjectIds,
        ];
    }

    private function normalizeClassLevelIds(mixed $value): array
    {
        return $this->normalizeIds($value);
    }

    private function normalizeIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = array_map(static fn ($item): int => (int)$item, $value);
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);
        return array_values(array_unique($ids));
    }

    private function validSubjectIds(array $subjectIds): array
    {
        if (!$subjectIds) {
            return [];
        }

        $pdo = Database::connect();
        (new SubjectService())->getAll();
        $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id
            FROM subjects
            WHERE id IN ({$placeholders})
              AND status = 'ACTIVE'
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute($subjectIds);
        return array_map(static fn ($row): int => (int)$row['id'], $stmt->fetchAll());
    }

    private function validClassLevelIds(int $schoolId, array $classLevelIds): array
    {
        if (!$classLevelIds) {
            return [];
        }

        $pdo = Database::connect();
        $placeholders = implode(',', array_fill(0, count($classLevelIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id
            FROM class_levels
            WHERE school_id = ?
              AND id IN ({$placeholders})
              AND status = 'ACTIVE'
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->execute([$schoolId, ...$classLevelIds]);
        return array_map(static fn ($row): int => (int)$row['id'], $stmt->fetchAll());
    }

    private function syncClassLevels(PDO $pdo, int $teacherId, int $schoolId, array $classLevelIds): void
    {
        $pdo->prepare('DELETE FROM teacher_class_levels WHERE teacher_id = ?')->execute([$teacherId]);
        if (!$classLevelIds) {
            return;
        }

        $validClassLevelIds = $this->validClassLevelIds($schoolId, $classLevelIds);
        $stmt = $pdo->prepare('INSERT IGNORE INTO teacher_class_levels (teacher_id, class_level_id) VALUES (?, ?)');
        foreach ($validClassLevelIds as $classLevelId) {
            $stmt->execute([$teacherId, $classLevelId]);
        }
    }

    private function syncSubjects(PDO $pdo, int $teacherId, array $subjectIds): void
    {
        $pdo->prepare('DELETE FROM teacher_subjects WHERE teacher_id = ?')->execute([$teacherId]);
        if (!$subjectIds) {
            return;
        }

        $validSubjectIds = $this->validSubjectIds($subjectIds);
        $stmt = $pdo->prepare('INSERT IGNORE INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)');
        foreach ($validSubjectIds as $subjectId) {
            $stmt->execute([$teacherId, $subjectId]);
        }
    }

    private function attachRelations(PDO $pdo, array $teachers): array
    {
        if (!$teachers) {
            return [];
        }

        $teachers = $this->attachClassLevels($pdo, $teachers);
        return $this->attachSubjects($pdo, $teachers);
    }

    private function attachClassLevels(PDO $pdo, array $teachers): array
    {
        try {
            $teacherIds = array_map(static fn ($teacher): int => (int)$teacher['id'], $teachers);
            $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
            $stmt = $pdo->prepare("
                SELECT
                    tcl.teacher_id,
                    cl.id,
                    cl.name,
                    cl.level_name,
                    cl.group_name,
                    cl.code
                FROM teacher_class_levels tcl
                INNER JOIN class_levels cl ON cl.id = tcl.class_level_id
                WHERE tcl.teacher_id IN ({$placeholders})
                ORDER BY cl.sort_order ASC, cl.name ASC
            ");
            $stmt->execute($teacherIds);
        } catch (PDOException) {
            return array_map(static function (array $teacher): array {
                $teacher['class_levels'] = [];
                $teacher['class_level_ids'] = [];
                return $teacher;
            }, $teachers);
        }

        $levelsByTeacher = [];
        foreach ($stmt->fetchAll() as $row) {
            $teacherId = (int)$row['teacher_id'];
            $levelsByTeacher[$teacherId][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'level_name' => $row['level_name'],
                'group_name' => $row['group_name'],
                'code' => $row['code'],
            ];
        }

        return array_map(static function (array $teacher) use ($levelsByTeacher): array {
            $levels = $levelsByTeacher[(int)$teacher['id']] ?? [];
            $teacher['class_levels'] = $levels;
            $teacher['class_level_ids'] = array_map(static fn ($level): int => (int)$level['id'], $levels);
            return $teacher;
        }, $teachers);
    }

    private function attachSubjects(PDO $pdo, array $teachers): array
    {
        try {
            $teacherIds = array_map(static fn ($teacher): int => (int)$teacher['id'], $teachers);
            $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
            $stmt = $pdo->prepare("
                SELECT
                    ts.teacher_id,
                    sub.id,
                    sub.name,
                    sub.code
                FROM teacher_subjects ts
                INNER JOIN subjects sub ON sub.id = ts.subject_id
                WHERE ts.teacher_id IN ({$placeholders})
                ORDER BY sub.sort_order ASC, sub.name ASC
            ");
            $stmt->execute($teacherIds);
        } catch (PDOException) {
            return array_map(static function (array $teacher): array {
                $teacher['subjects'] = [];
                $teacher['subject_ids'] = [];
                return $teacher;
            }, $teachers);
        }

        $subjectsByTeacher = [];
        foreach ($stmt->fetchAll() as $row) {
            $teacherId = (int)$row['teacher_id'];
            $subjectsByTeacher[$teacherId][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'code' => $row['code'],
            ];
        }

        return array_map(static function (array $teacher) use ($subjectsByTeacher): array {
            $subjects = $subjectsByTeacher[(int)$teacher['id']] ?? [];
            $teacher['subjects'] = $subjects;
            $teacher['subject_ids'] = array_map(static fn ($subject): int => (int)$subject['id'], $subjects);
            return $teacher;
        }, $teachers);
    }

    private function resolveSchoolId(array $data, string $actorRole, ?int $actorSchoolId): ?int
    {
        if ($actorRole === 'super_admin' && isset($data['school_id'])) {
            $schoolId = (int)$data['school_id'];
            return $schoolId > 0 ? $schoolId : null;
        }

        return $actorSchoolId;
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        return [
            (string)($authUser['role'] ?? ''),
            isset($authUser['school_id']) ? (int)$authUser['school_id'] : null,
        ];
    }

    private function findSchool(int $schoolId): array|false
    {
        $stmt = Database::connect()->prepare('SELECT id, name, email_domain, status FROM schools WHERE id = ? LIMIT 1');
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();
        return $school && $school['status'] === 'ACTIVE' ? $school : false;
    }

    private function findTeacherById(int $teacherId): array|false
    {
        $stmt = Database::connect()->prepare('SELECT * FROM users WHERE id = ? AND role IN ("professeur", "user") LIMIT 1');
        $stmt->execute([$teacherId]);
        return $stmt->fetch() ?: false;
    }

    private function generateUniqueEmail(string $baseLocal, int $schoolId, ?int $ignoreUserId = null): string
    {
        $school = $this->findSchool($schoolId);
        $domain = strtolower((string)($school['email_domain'] ?? 'eduflow.local'));
        $candidate = $baseLocal !== '' ? $baseLocal : 'professeur';
        $counter = 0;
        $pdo = Database::connect();

        while (true) {
            $email = $candidate . ($counter > 0 ? $counter : '') . '@' . $domain;
            $sql = 'SELECT id FROM users WHERE email = ?';
            $params = [$email];
            if ($ignoreUserId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $ignoreUserId;
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                return $email;
            }
            $counter++;
        }
    }

    private function sanitizeLocalPart(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(' ', '.', $value);
        return (string)preg_replace('/[^a-z0-9._-]/', '', $value);
    }

    private function databaseErrorMessage(PDOException $e): string
    {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if (in_array($driverCode, [1054, 1146], true)) {
            return 'Structure de base de données incomplète. Lancez les migrations professeur.';
        }

        return 'Impossible de créer ou modifier ce professeur. Vérifiez les informations saisies.';
    }
}
