<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;
use PDOException;

class ScheduleService
{
    private const DAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    public function getAll(array $filters = []): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $conditions = [];
        $params = [];

        if ($role === 'super_admin') {
            $requestedSchoolId = isset($filters['school_id']) ? (int)$filters['school_id'] : 0;
            if ($requestedSchoolId > 0) {
                $conditions[] = 'sc.school_id = ?';
                $params[] = $requestedSchoolId;
            }
        } else {
            $conditions[] = 'sc.school_id = ?';
            $params[] = $schoolId;
        }

        $classLevelId = isset($filters['class_level_id']) ? (int)$filters['class_level_id'] : 0;
        if ($classLevelId > 0) {
            $conditions[] = 'sc.class_level_id = ?';
            $params[] = $classLevelId;
        }

        $day = strtoupper(trim((string)($filters['day_of_week'] ?? '')));
        if ($day !== '' && in_array($day, self::DAYS, true)) {
            $conditions[] = 'sc.day_of_week = ?';
            $params[] = $day;
        }

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '' && in_array($status, ['ACTIVE', 'CANCELLED'], true)) {
            $conditions[] = 'sc.status = ?';
            $params[] = $status;
        }

        $teacherId = isset($filters['teacher_id']) ? (int)$filters['teacher_id'] : 0;
        $teacherName = trim((string)($filters['teacher_name'] ?? ''));

        if ($teacherId > 0 && $teacherName !== '') {
            $conditions[] = '(sc.teacher_id = ? OR LOWER(TRIM(sc.teacher_name)) = ?)';
            $params[] = $teacherId;
            $params[] = strtolower($teacherName);
        } elseif ($teacherId > 0) {
            $conditions[] = 'sc.teacher_id = ?';
            $params[] = $teacherId;
        } elseif ($teacherName !== '') {
            $conditions[] = 'LOWER(TRIM(sc.teacher_name)) = ?';
            $params[] = strtolower($teacherName);
        }

        $yearValue = isset($filters['year_value']) ? (int)$filters['year_value'] : 0;
        if ($yearValue > 0) {
            $conditions[] = 'sc.year_value = ?';
            $params[] = $yearValue;
        }

        $weekNumber = isset($filters['week_number']) ? (int)$filters['week_number'] : 0;
        if ($weekNumber > 0) {
            $conditions[] = 'sc.week_number = ?';
            $params[] = $weekNumber;
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "
            SELECT
                sc.*,
                sub.code AS subject_code,
                COALESCE(cl.group_name, cl.name, 'Ailleurs') AS class_level_name,
                COALESCE(cl.level_name, cl.name) AS level_name,
                s.name AS school_name
            FROM schedules sc
            LEFT JOIN subjects sub ON sub.id = sc.subject_id
            LEFT JOIN class_levels cl ON cl.id = sc.class_level_id
            INNER JOIN schools s ON s.id = sc.school_id
            {$whereSql}
            ORDER BY sc.day_order ASC, sc.start_time ASC, COALESCE(cl.sort_order, 999) ASC, COALESCE(cl.name, sc.subject) ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById(int $scheduleId): array|false
    {
        if ($scheduleId <= 0) {
            return false;
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $sql = '
            SELECT sc.*, sub.code AS subject_code, COALESCE(cl.group_name, cl.name, "Ailleurs") AS class_level_name, COALESCE(cl.level_name, cl.name) AS level_name, s.name AS school_name
            FROM schedules sc
            LEFT JOIN subjects sub ON sub.id = sc.subject_id
            LEFT JOIN class_levels cl ON cl.id = sc.class_level_id
            INNER JOIN schools s ON s.id = sc.school_id
            WHERE sc.id = ?
        ';
        $params = [$scheduleId];

        if ($role !== 'super_admin') {
            $sql .= ' AND sc.school_id = ?';
            $params[] = $schoolId;
        }

        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: false;
    }

    public function create(array $data): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $isExternal = $this->isExternal($data);
        $classLevel = null;
        if (!$isExternal) {
            $classLevel = $this->resolveClassLevel($pdo, $data, $role, $schoolId);
            if (isset($classLevel['error'])) {
                return $classLevel;
            }
        }

        $payload = $this->validatedPayload($pdo, $data, $classLevel, $role, $schoolId);
        if (isset($payload['error'])) {
            return $payload;
        }

        $conflict = $this->findConflict($pdo, $payload);
        if ($conflict) {
            return ['error' => 'Un autre cours existe deja pour cette classe sur ce creneau'];
        }

        $teacherConflict = $this->findTeacherConflict($pdo, $payload);
        if ($teacherConflict) {
            return ['error' => $this->teacherConflictMessage($teacherConflict), 'conflict' => true];
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO schedules (
                    school_id, class_level_id, subject_id, subject, teacher_id, teacher_name, room, is_external, year_value, week_number, day_of_week, day_order,
                    start_time, end_time, notes, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $payload['school_id'],
                $payload['class_level_id'],
                $payload['subject_id'],
                $payload['subject'],
                $payload['teacher_id'],
                $payload['teacher_name'],
                $payload['room'],
                $payload['is_external'],
                $payload['year_value'],
                $payload['week_number'],
                $payload['day_of_week'],
                $payload['day_order'],
                $payload['start_time'],
                $payload['end_time'],
                $payload['notes'],
                $payload['status'],
            ]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1146) {
                return ['error' => 'Table schedules absente. Lancez la migration 2026_08_27_create_schedules_table.sql'];
            }
            return ['error' => 'Schedule creation failed'];
        }

        return [
            'id' => (int)$pdo->lastInsertId(),
            'message' => 'Schedule created successfully',
        ];
    }

    public function update(int $scheduleId, array $data): array
    {
        $existing = $this->getById($scheduleId);
        if (!$existing) {
            return ['error' => 'Schedule not found'];
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        if ($role !== 'super_admin' && (int)$existing['school_id'] !== $schoolId) {
            return ['error' => 'Forbidden'];
        }

        $data = array_merge($existing, $data);
        $isExternal = $this->isExternal($data);
        $classLevel = null;
        if (!$isExternal) {
            $classLevel = $this->resolveClassLevel($pdo, $data, $role, $schoolId);
            if (isset($classLevel['error'])) {
                return $classLevel;
            }
        }

        $payload = $this->validatedPayload($pdo, $data, $classLevel, $role, $schoolId);
        if (isset($payload['error'])) {
            return $payload;
        }
        $payload['id'] = $scheduleId;

        $conflict = $this->findConflict($pdo, $payload, $scheduleId);
        if ($conflict) {
            return ['error' => 'Un autre cours existe deja pour cette classe sur ce creneau'];
        }

        $teacherConflict = $this->findTeacherConflict($pdo, $payload, $scheduleId);
        if ($teacherConflict) {
            return ['error' => $this->teacherConflictMessage($teacherConflict), 'conflict' => true];
        }

        try {
            $stmt = $pdo->prepare('
                UPDATE schedules
                SET
                    class_level_id = ?,
                    subject_id = ?,
                    subject = ?,
                    teacher_id = ?,
                    teacher_name = ?,
                    room = ?,
                    is_external = ?,
                    year_value = ?,
                    week_number = ?,
                    day_of_week = ?,
                    day_order = ?,
                    start_time = ?,
                    end_time = ?,
                    notes = ?,
                    status = ?
                WHERE id = ?
            ');
            $stmt->execute([
                $payload['class_level_id'],
                $payload['subject_id'],
                $payload['subject'],
                $payload['teacher_id'],
                $payload['teacher_name'],
                $payload['room'],
                $payload['is_external'],
                $payload['year_value'],
                $payload['week_number'],
                $payload['day_of_week'],
                $payload['day_order'],
                $payload['start_time'],
                $payload['end_time'],
                $payload['notes'],
                $payload['status'],
                $scheduleId,
            ]);
        } catch (PDOException) {
            return ['error' => 'Schedule update failed'];
        }

        return [
            'id' => $scheduleId,
            'message' => 'Schedule updated successfully',
        ];
    }

    public function delete(int $scheduleId): array
    {
        $existing = $this->getById($scheduleId);
        if (!$existing) {
            return ['error' => 'Schedule not found'];
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('DELETE FROM schedules WHERE id = ?');
        $stmt->execute([$scheduleId]);

        return [
            'id' => $scheduleId,
            'message' => 'Schedule deleted successfully',
        ];
    }

    private function validatedPayload(PDO $pdo, array $data, ?array $classLevel, string $role, ?int $actorSchoolId): array
    {
        $isExternal = $this->isExternal($data);
        $schoolId = $classLevel !== null ? (int)$classLevel['school_id'] : $actorSchoolId;
        if ($role === 'super_admin' && $isExternal) {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : $schoolId;
        }

        if (!$schoolId || $schoolId <= 0) {
            return ['error' => 'Ecole obligatoire pour ce creneau'];
        }

        $subjectId = isset($data['subject_id']) ? (int)$data['subject_id'] : 0;
        $subject = false;
        if (!$isExternal) {
            if ($classLevel === null) {
                return ['error' => 'La classe est obligatoire'];
            }

            $subject = (new SubjectService())->validateSubjectForClassLevel($subjectId, (int)$classLevel['id']);
            if (!$subject) {
                return ['error' => 'Cette matiere n est pas autorisee pour ce niveau scolaire'];
            }
        }

        $teacherId = isset($data['teacher_id']) ? (int)$data['teacher_id'] : 0;
        $teacherName = trim((string)($data['teacher_name'] ?? ''));
        if ($teacherId > 0) {
            $teacher = $this->resolveTeacher($pdo, $teacherId, $schoolId);
            if (!$teacher) {
                return ['error' => 'Professeur introuvable pour cette ecole'];
            }
            $teacherName = trim((string)$teacher['first_name'] . ' ' . (string)$teacher['last_name']);
        }

        if ($teacherName === '') {
            return ['error' => 'Le nom du professeur est obligatoire'];
        }

        $day = strtoupper(trim((string)($data['day_of_week'] ?? '')));
        if (!in_array($day, self::DAYS, true)) {
            return ['error' => 'Jour invalide'];
        }

        $startTime = $this->normalizeTime($data['start_time'] ?? null);
        $endTime = $this->normalizeTime($data['end_time'] ?? null);
        if (!$startTime || !$endTime) {
            return ['error' => 'Heure de debut et heure de fin sont obligatoires'];
        }

        if ($startTime >= $endTime) {
            return ['error' => 'Heure de fin doit etre apres heure de debut'];
        }

        $status = strtoupper(trim((string)($data['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'CANCELLED'], true)) {
            return ['error' => 'Statut invalide'];
        }

        $yearValue = isset($data['year_value']) ? (int)$data['year_value'] : 0;
        if ($yearValue < 2000 || $yearValue > 2100) {
            return ['error' => 'Annee invalide pour la semaine'];
        }

        $weekNumber = isset($data['week_number']) ? (int)$data['week_number'] : 0;
        if ($weekNumber < 1 || $weekNumber > 53) {
            return ['error' => 'Numero de semaine invalide'];
        }

        return [
            'school_id' => $schoolId,
            'class_level_id' => $isExternal ? null : (int)$classLevel['id'],
            'subject_id' => $isExternal ? null : (int)$subject['id'],
            'subject' => $isExternal ? 'Ailleurs' : (string)$subject['name'],
            'teacher_id' => $teacherId > 0 ? $teacherId : null,
            'teacher_name' => $teacherName,
            'room' => $this->nullable($data['room'] ?? null),
            'is_external' => $isExternal ? 1 : 0,
            'year_value' => $yearValue,
            'week_number' => $weekNumber,
            'day_of_week' => $day,
            'day_order' => array_search($day, self::DAYS, true) + 1,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'notes' => $this->nullable($data['notes'] ?? null),
            'status' => $status,
        ];
    }

    private function resolveClassLevel(PDO $pdo, array $data, string $role, ?int $actorSchoolId): array
    {
        $classLevelId = isset($data['class_level_id']) ? (int)$data['class_level_id'] : 0;
        if ($classLevelId <= 0) {
            return ['error' => 'La classe est obligatoire'];
        }

        $sql = 'SELECT id, school_id, name FROM class_levels WHERE id = ?';
        $params = [$classLevelId];

        if ($role !== 'super_admin') {
            $sql .= ' AND school_id = ?';
            $params[] = $actorSchoolId;
        }

        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $classLevel = $stmt->fetch();

        if (!$classLevel) {
            return ['error' => 'Classe introuvable'];
        }

        return $classLevel;
    }

    private function findConflict(PDO $pdo, array $payload, ?int $ignoreId = null): array|false
    {
        if ($payload['status'] !== 'ACTIVE' || empty($payload['class_level_id'])) {
            return false;
        }

        $sql = '
            SELECT id
            FROM schedules
            WHERE school_id = ?
              AND class_level_id = ?
              AND year_value = ?
              AND week_number = ?
              AND day_of_week = ?
              AND status = "ACTIVE"
              AND start_time < ?
              AND end_time > ?
        ';
        $params = [
            $payload['school_id'],
            $payload['class_level_id'],
            $payload['year_value'],
            $payload['week_number'],
            $payload['day_of_week'],
            $payload['end_time'],
            $payload['start_time'],
        ];

        if ($ignoreId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $ignoreId;
        }

        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: false;
    }

    private function findTeacherConflict(PDO $pdo, array $payload, ?int $ignoreId = null): array|false
    {
        if ($payload['status'] !== 'ACTIVE' || trim((string)$payload['teacher_name']) === '') {
            return false;
        }

        $sql = '
            SELECT sc.id, sc.subject, sc.teacher_id, sc.teacher_name, sc.is_external, sc.notes, sc.year_value, sc.week_number, sc.day_of_week, sc.start_time, sc.end_time, COALESCE(cl.group_name, cl.name, "Ailleurs") AS class_level_name
            FROM schedules sc
            LEFT JOIN class_levels cl ON cl.id = sc.class_level_id
            WHERE sc.school_id = ?
              AND sc.year_value = ?
              AND sc.week_number = ?
              AND sc.day_of_week = ?
              AND sc.status = "ACTIVE"
              AND sc.start_time < ?
              AND sc.end_time > ?
        ';
        $params = [
            $payload['school_id'],
            $payload['year_value'],
            $payload['week_number'],
            $payload['day_of_week'],
            $payload['end_time'],
            $payload['start_time']
        ];

        if (!empty($payload['teacher_id'])) {
            $sql .= ' AND (sc.teacher_id = ? OR LOWER(TRIM(sc.teacher_name)) = ?)';
            $params[] = $payload['teacher_id'];
            $params[] = strtolower(trim((string)$payload['teacher_name']));
        } else {
            $sql .= ' AND LOWER(TRIM(sc.teacher_name)) = ?';
            $params[] = strtolower(trim((string)$payload['teacher_name']));
        }

        if ($ignoreId !== null) {
            $sql .= ' AND sc.id != ?';
            $params[] = $ignoreId;
        }

        $stmt = $pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: false;
    }

    private function teacherConflictMessage(array $conflict): string
    {
        $teacher = (string)($conflict['teacher_name'] ?? 'ce professeur');
        $start = substr((string)($conflict['start_time'] ?? ''), 0, 5);
        $end = substr((string)($conflict['end_time'] ?? ''), 0, 5);

        if ((int)($conflict['is_external'] ?? 0) === 1) {
            $note = trim((string)($conflict['notes'] ?? ''));
            $details = $note !== '' ? " ({$note})" : " (Ailleurs)";
            return "Le professeur {$teacher} n'est pas disponible de {$start} a {$end}{$details}.";
        }

        return "Le professeur {$teacher} n'est pas disponible de {$start} a {$end}.";
    }

    private function dayLabel(string $day): string
    {
        return [
            'MONDAY' => 'lundi',
            'TUESDAY' => 'mardi',
            'WEDNESDAY' => 'mercredi',
            'THURSDAY' => 'jeudi',
            'FRIDAY' => 'vendredi',
            'SATURDAY' => 'samedi',
            'SUNDAY' => 'dimanche',
        ][$day] ?? 'ce jour';
    }

    private function normalizeTime(mixed $time): ?string
    {
        $value = trim((string)$time);
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
            return null;
        }

        $parts = explode(':', $value);
        $hour = (int)$parts[0];
        $minute = (int)$parts[1];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d:00', $hour, $minute);
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }

    private function nullable(mixed $value): ?string
    {
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }

    private function isExternal(array $data): bool
    {
        $value = $data['is_external'] ?? false;
        return $value === true || $value === 1 || $value === '1' || strtolower((string)$value) === 'true';
    }

    private function resolveTeacher(PDO $pdo, int $teacherId, int $schoolId): array|false
    {
        if ($teacherId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare('
            SELECT id, first_name, last_name
            FROM users
            WHERE id = ?
              AND school_id = ?
              AND status = "ACTIVE"
              AND role = "user"
            LIMIT 1
        ');
        $stmt->execute([$teacherId, $schoolId]);
        return $stmt->fetch() ?: false;
    }
}
