<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;
use PDOException;

class StudentService
{
    public function getAll(array $filters = []): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $sql = '
            SELECT
                s.*,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                cl.group_name AS class_group_name,
                p.phone AS parent_phone
            FROM students s
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            LEFT JOIN parents p ON p.id = s.parent_id
        ';

        $conditions = [];
        $params = [];

        if ($role === 'super_admin') {
            $requestedSchoolId = isset($filters['school_id']) ? (int)$filters['school_id'] : 0;
            if ($requestedSchoolId > 0) {
                $conditions[] = 's.school_id = ?';
                $params[] = $requestedSchoolId;
            }
        } else {
            $conditions[] = 's.school_id = ?';
            $params[] = $schoolId;
        }

        $requestedClassLevelId = isset($filters['class_level_id']) ? (int)$filters['class_level_id'] : 0;
        if ($requestedClassLevelId > 0) {
            $conditions[] = 's.class_level_id = ?';
            $params[] = $requestedClassLevelId;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(
                LOWER(s.first_name) LIKE ?
                OR LOWER(s.last_name) LIKE ?
                OR LOWER(CONCAT(s.first_name, " ", s.last_name)) LIKE ?
                OR LOWER(CONCAT(s.last_name, " ", s.first_name)) LIKE ?
            )';
            $term = '%' . strtolower($search) . '%';
            array_push($params, $term, $term, $term, $term);
        }

        $lastName = trim((string)($filters['last_name'] ?? ''));
        if ($lastName !== '') {
            $conditions[] = 'LOWER(s.last_name) LIKE ?';
            $params[] = '%' . strtolower($lastName) . '%';
        }

        $firstName = trim((string)($filters['first_name'] ?? ''));
        if ($firstName !== '') {
            $conditions[] = 'LOWER(s.first_name) LIKE ?';
            $params[] = '%' . strtolower($firstName) . '%';
        }

        $classLevel = trim((string)($filters['class_level'] ?? ''));
        if ($classLevel !== '') {
            $conditions[] = 'LOWER(COALESCE(cl.level_name, cl.name, s.class_level)) LIKE ?';
            $params[] = '%' . strtolower($classLevel) . '%';
        }

        $className = trim((string)($filters['class_name'] ?? ''));
        if ($className !== '') {
            $conditions[] = 'LOWER(COALESCE(cl.group_name, s.class_name, "")) LIKE ?';
            $params[] = '%' . strtolower($className) . '%';
        }

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $pdo->prepare($sql . ' ORDER BY s.last_name ASC, s.first_name ASC, s.id DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        if ($role === 'super_admin') {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
        }

        if (!$schoolId || $schoolId <= 0) {
            return ['error' => 'School is required to create student'];
        }

        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            return ['error' => 'first_name and last_name are required'];
        }

        $parentPhone = trim((string)($data['parent_phone'] ?? ($data['phone'] ?? '')));
        if ($parentPhone === '') {
            return ['error' => 'parent_phone is required'];
        }

        $monthlyAmount = (float)($data['monthly_amount'] ?? 0);
        if ($monthlyAmount < 0) {
            return ['error' => 'monthly_amount must be positive'];
        }

        $discountPercent = (float)($data['discount_percent'] ?? 0);
        if ($discountPercent < 0 || $discountPercent > 100) {
            return ['error' => 'discount_percent must be between 0 and 100'];
        }

        $classLevel = $this->resolveClassLevel($pdo, $schoolId, $data);
        if (isset($classLevel['error'])) {
            return $classLevel;
        }

        $parentName = trim((string)($data['parent_name'] ?? ''));
        if ($parentName === '') {
            $parentName = 'Parent ' . $lastName;
        }

        $parentFirstName = trim((string)($data['parent_first_name'] ?? ''));
        $parentLastName = trim((string)($data['parent_last_name'] ?? ''));
        if ($parentFirstName === '' || $parentLastName === '') {
            $parts = preg_split('/\s+/', $parentName);
            $parentFirstName = $parentFirstName !== '' ? $parentFirstName : (string)($parts[0] ?? 'Parent');
            $parentLastName = $parentLastName !== '' ? $parentLastName : (string)($parts[1] ?? $lastName);
        }

        $schoolYear = trim((string)($data['school_year'] ?? ''));
        $dateOfBirth = $this->normalizeDate($data['date_of_birth'] ?? null);
        $gender = $this->nullable($data['gender'] ?? null);
        $className = $this->nullable($data['class_name'] ?? null);
        $address = $this->nullable($data['address'] ?? null);
        $status = strtoupper(trim((string)($data['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid student status'];
        }

        $parentId = $this->resolveOrCreateParent(
            $pdo,
            $schoolId,
            $parentFirstName,
            $parentLastName,
            $parentPhone,
            $data['parent_email'] ?? null
        );

        try {
            $stmt = $pdo->prepare('
                INSERT INTO students (
                    school_id, parent_id, first_name, last_name, date_of_birth, gender, class_level, class_name, class_level_id,
                    parent_name, phone, address, monthly_amount, discount_percent, school_year, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $schoolId,
                $parentId,
                $firstName,
                $lastName,
                $dateOfBirth,
                $gender,
                $classLevel['name'],
                $className,
                $classLevel['id'],
                $parentName,
                $parentPhone,
                $address,
                $monthlyAmount,
                $discountPercent,
                $schoolYear !== '' ? $schoolYear : null,
                $status,
            ]);
        } catch (PDOException) {
            return ['error' => 'Student creation failed'];
        }

        return [
            'id' => (int)$pdo->lastInsertId(),
            'school_id' => $schoolId,
            'message' => 'Student created successfully',
        ];
    }

    public function update(int $studentId, array $data): array
    {
        if ($studentId <= 0) {
            return ['error' => 'Student not found'];
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $student = $this->findStudent($pdo, $studentId);
        if (!$student) {
            return ['error' => 'Student not found'];
        }

        if ($role !== 'super_admin' && (int)$student['school_id'] !== $schoolId) {
            return ['error' => 'Forbidden'];
        }

        $schoolId = (int)$student['school_id'];
        $firstName = trim((string)($data['first_name'] ?? $student['first_name']));
        $lastName = trim((string)($data['last_name'] ?? $student['last_name']));
        if ($firstName === '' || $lastName === '') {
            return ['error' => 'first_name and last_name are required'];
        }

        $parentPhone = trim((string)($data['parent_phone'] ?? ($data['phone'] ?? $student['phone'] ?? '')));
        if ($parentPhone === '') {
            return ['error' => 'parent_phone is required'];
        }

        $monthlyAmount = isset($data['monthly_amount']) ? (float)$data['monthly_amount'] : (float)$student['monthly_amount'];
        if ($monthlyAmount < 0) {
            return ['error' => 'monthly_amount must be positive'];
        }

        $discountPercent = isset($data['discount_percent']) ? (float)$data['discount_percent'] : (float)($student['discount_percent'] ?? 0);
        if ($discountPercent < 0 || $discountPercent > 100) {
            return ['error' => 'discount_percent must be between 0 and 100'];
        }

        $classLevelData = [
            'class_level_id' => $data['class_level_id'] ?? $student['class_level_id'],
            'class_level' => $data['class_level'] ?? $student['class_level'],
        ];
        $classLevel = $this->resolveClassLevel($pdo, $schoolId, $classLevelData);
        if (isset($classLevel['error'])) {
            return $classLevel;
        }

        $parentName = trim((string)($data['parent_name'] ?? $student['parent_name']));
        if ($parentName === '') {
            $parentName = 'Parent ' . $lastName;
        }

        $parentFirstName = trim((string)($data['parent_first_name'] ?? ''));
        $parentLastName = trim((string)($data['parent_last_name'] ?? ''));
        if ($parentFirstName === '' || $parentLastName === '') {
            $parts = preg_split('/\s+/', $parentName);
            $parentFirstName = $parentFirstName !== '' ? $parentFirstName : (string)($parts[0] ?? 'Parent');
            $parentLastName = $parentLastName !== '' ? $parentLastName : (string)($parts[1] ?? $lastName);
        }

        $parentId = $this->resolveOrCreateParent(
            $pdo,
            $schoolId,
            $parentFirstName,
            $parentLastName,
            $parentPhone,
            $data['parent_email'] ?? null
        );

        $dateOfBirth = array_key_exists('date_of_birth', $data)
            ? $this->normalizeDate($data['date_of_birth'])
            : ($student['date_of_birth'] ?: null);
        $gender = array_key_exists('gender', $data)
            ? $this->nullable($data['gender'])
            : ($student['gender'] ?? null);
        $className = array_key_exists('class_name', $data)
            ? $this->nullable($data['class_name'])
            : ($student['class_name'] ?? null);
        $address = array_key_exists('address', $data)
            ? $this->nullable($data['address'])
            : ($student['address'] ?? null);

        $schoolYear = array_key_exists('school_year', $data)
            ? trim((string)$data['school_year'])
            : (string)($student['school_year'] ?? '');

        $status = strtoupper(trim((string)($data['status'] ?? $student['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid student status'];
        }

        $stmt = $pdo->prepare('
            UPDATE students
            SET
                parent_id = ?,
                first_name = ?,
                last_name = ?,
                date_of_birth = ?,
                gender = ?,
                class_level = ?,
                class_name = ?,
                class_level_id = ?,
                parent_name = ?,
                phone = ?,
                address = ?,
                monthly_amount = ?,
                discount_percent = ?,
                school_year = ?,
                status = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $parentId,
            $firstName,
            $lastName,
            $dateOfBirth,
            $gender,
            $classLevel['name'],
            $className,
            $classLevel['id'],
            $parentName,
            $parentPhone,
            $address,
            $monthlyAmount,
            $discountPercent,
            $schoolYear !== '' ? $schoolYear : null,
            $status,
            $studentId,
        ]);

        return [
            'id' => $studentId,
            'message' => 'Student updated successfully',
        ];
    }

    public function delete(int $studentId): array
    {
        if ($studentId <= 0) {
            return ['error' => 'Student not found'];
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $student = $this->findStudent($pdo, $studentId);
        if (!$student) {
            return ['error' => 'Student not found'];
        }

        if ($role !== 'super_admin' && (int)$student['school_id'] !== $schoolId) {
            return ['error' => 'Forbidden'];
        }

        $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
        $stmt->execute([$studentId]);

        return [
            'id' => $studentId,
            'message' => 'Student deleted successfully',
        ];
    }

    public function parentSummary(?string $search = null): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $conditions = [];
        $params = [];

        if ($role !== 'super_admin') {
            $conditions[] = 's.school_id = ?';
            $params[] = $schoolId;
        }

        if ($search !== null && trim($search) !== '') {
            $conditions[] = '(LOWER(s.parent_name) LIKE ? OR LOWER(COALESCE(s.phone, "")) LIKE ?)';
            $term = '%' . strtolower(trim($search)) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT
                s.parent_name,
                s.phone,
                COUNT(DISTINCT s.id) AS children_count,
                GROUP_CONCAT(DISTINCT CONCAT(s.first_name, ' ', s.last_name) ORDER BY s.last_name SEPARATOR ', ') AS children_names,
                COALESCE(SUM(CASE WHEN mf.status != 'PAID' THEN mf.remaining_amount ELSE 0 END), 0) AS total_remaining,
                SUM(CASE WHEN mf.status = 'UNPAID' THEN 1 ELSE 0 END) AS unpaid_months,
                SUM(CASE WHEN mf.status = 'PARTIAL' THEN 1 ELSE 0 END) AS partial_months
            FROM students s
            LEFT JOIN monthly_fees mf ON mf.student_id = s.id AND mf.school_id = s.school_id
            {$whereSql}
            GROUP BY s.parent_name, s.phone
            ORDER BY total_remaining DESC, children_count DESC, s.parent_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }

    private function normalizeDate(mixed $date): ?string
    {
        $value = trim((string)$date);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function resolveClassLevel(PDO $pdo, int $schoolId, array $data): array
    {
        $classLevelId = isset($data['class_level_id']) ? (int)$data['class_level_id'] : 0;
        if ($classLevelId > 0) {
            $stmt = $pdo->prepare('
                SELECT id, name
                FROM class_levels
                WHERE id = ? AND school_id = ?
                LIMIT 1
            ');
            $stmt->execute([$classLevelId, $schoolId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['error' => 'Invalid class_level_id'];
            }
            return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }

        $classLevelName = trim((string)($data['class_level'] ?? ''));
        if ($classLevelName === '') {
            return ['error' => 'class_level_id or class_level is required'];
        }

        $find = $pdo->prepare('
            SELECT id, name
            FROM class_levels
            WHERE school_id = ? AND name = ?
            LIMIT 1
        ');
        $find->execute([$schoolId, $classLevelName]);
        $existing = $find->fetch();
        if ($existing) {
            return ['id' => (int)$existing['id'], 'name' => (string)$existing['name']];
        }

        $insert = $pdo->prepare('
            INSERT INTO class_levels (school_id, name, status)
            VALUES (?, ?, "ACTIVE")
        ');
        $insert->execute([$schoolId, $classLevelName]);

        return ['id' => (int)$pdo->lastInsertId(), 'name' => $classLevelName];
    }

    private function resolveOrCreateParent(
        PDO $pdo,
        int $schoolId,
        string $firstName,
        string $lastName,
        string $phone,
        mixed $email
    ): int {
        $emailValue = trim((string)$email);
        $stmt = $pdo->prepare('
            SELECT id
            FROM parents
            WHERE school_id = ? AND phone = ?
            LIMIT 1
        ');
        $stmt->execute([$schoolId, $phone]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int)$existing['id'];
        }

        $insert = $pdo->prepare('
            INSERT INTO parents (school_id, first_name, last_name, phone, email)
            VALUES (?, ?, ?, ?, ?)
        ');
        $insert->execute([
            $schoolId,
            $firstName,
            $lastName,
            $phone,
            $emailValue !== '' ? $emailValue : null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    private function findStudent(PDO $pdo, int $studentId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        return $stmt->fetch();
    }

    private function nullable(mixed $value): ?string
    {
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }
}
