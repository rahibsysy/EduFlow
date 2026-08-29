<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDOException;

class ClassLevelService
{
    public function getAll(?int $requestedSchoolId = null): array
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $actorSchoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        if ($role === 'super_admin') {
            if ($requestedSchoolId && $requestedSchoolId > 0) {
                $stmt = $pdo->prepare('SELECT * FROM class_levels WHERE school_id = ? ORDER BY sort_order ASC, name ASC');
                $stmt->execute([$requestedSchoolId]);
                return $stmt->fetchAll();
            }

            $stmt = $pdo->query('SELECT * FROM class_levels ORDER BY school_id ASC, sort_order ASC, name ASC');
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare('SELECT * FROM class_levels WHERE school_id = ? ORDER BY sort_order ASC, name ASC');
        $stmt->execute([$actorSchoolId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $actorSchoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        $schoolId = $actorSchoolId;
        if ($role === 'super_admin') {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
        }

        if (!$schoolId || $schoolId <= 0) {
            return ['error' => 'school_id is required'];
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'Class level name is required'];
        }

        $levelName = $this->nullable($data['level_name'] ?? $name);
        $groupName = $this->nullable($data['group_name'] ?? null);
        $schoolYear = $this->nullable($data['school_year'] ?? null);
        $status = 'ACTIVE';
        $nextSortOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_value FROM class_levels WHERE school_id = ?');
        $nextSortOrderStmt->execute([$schoolId]);
        $nextSortOrder = (int)($nextSortOrderStmt->fetch()['next_value'] ?? 1);
        $sortOrder = $nextSortOrder;
        $code = (string)$nextSortOrder;

        try {
            $columns = ['school_id', 'name', 'code', 'sort_order', 'status'];
            $values = [$schoolId, $name, $code, $sortOrder, $status];

            if ($this->columnExists('level_name')) {
                $columns[] = 'level_name';
                $values[] = $levelName;
            }

            if ($this->columnExists('group_name')) {
                $columns[] = 'group_name';
                $values[] = $groupName;
            }

            if ($this->columnExists('school_year')) {
                $columns[] = 'school_year';
                $values[] = $schoolYear;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = $pdo->prepare(sprintf(
                'INSERT INTO class_levels (%s) VALUES (%s)',
                implode(', ', $columns),
                $placeholders
            ));
            $stmt->execute($values);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1146) {
                return ['error' => 'Table class_levels absente. Lancez la migration 2026_05_11_user_student_payment_upgrade.sql'];
            }
            if ((int)$e->getCode() === 23000) {
                return ['error' => 'Class level already exists for this school'];
            }
            return ['error' => 'Class level creation failed'];
        }

        $createdId = (int)$pdo->lastInsertId();
        (new SubjectService())->ensureSubjectsForClassLevel($createdId);

        return [
            'id' => $createdId,
            'school_id' => $schoolId,
            'name' => $name,
            'code' => $code,
            'sort_order' => $sortOrder,
            'status' => $status,
            'level_name' => $levelName,
            'group_name' => $groupName,
            'school_year' => $schoolYear,
            'message' => 'Class level created successfully',
        ];
    }

    public function getById(int $classLevelId): array|false
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $actorSchoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        if ($role === 'super_admin') {
            $stmt = $pdo->prepare('SELECT * FROM class_levels WHERE id = ? LIMIT 1');
            $stmt->execute([$classLevelId]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM class_levels WHERE id = ? AND school_id = ? LIMIT 1');
            $stmt->execute([$classLevelId, $actorSchoolId]);
        }

        return $stmt->fetch() ?: false;
    }

    public function update(int $classLevelId, array $data): array
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');

        $classLevel = $this->getById($classLevelId);
        if (!$classLevel) {
            return ['error' => 'Class level not found'];
        }

        $name = isset($data['name']) ? trim((string)$data['name']) : (string)$classLevel['name'];
        if ($name === '') {
            return ['error' => 'Class level name is required'];
        }

        $status = isset($data['status']) ? strtoupper(trim((string)$data['status'])) : (string)$classLevel['status'];
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid status'];
        }

        $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : (int)$classLevel['sort_order'];
        $levelName = array_key_exists('level_name', $data)
            ? $this->nullable($data['level_name'])
            : $this->nullable($classLevel['level_name'] ?? null);
        $groupName = array_key_exists('group_name', $data)
            ? $this->nullable($data['group_name'])
            : $this->nullable($classLevel['group_name'] ?? null);
        $schoolYear = array_key_exists('school_year', $data)
            ? $this->nullable($data['school_year'])
            : $this->nullable($classLevel['school_year'] ?? null);

        try {
            $sets = ['name = ?', 'sort_order = ?', 'status = ?'];
            $values = [$name, $sortOrder, $status];

            if ($this->columnExists('level_name')) {
                $sets[] = 'level_name = ?';
                $values[] = $levelName;
            }

            if ($this->columnExists('group_name')) {
                $sets[] = 'group_name = ?';
                $values[] = $groupName;
            }

            if ($this->columnExists('school_year')) {
                $sets[] = 'school_year = ?';
                $values[] = $schoolYear;
            }

            $values[] = $classLevelId;
            $stmt = $pdo->prepare('
                UPDATE class_levels
                SET ' . implode(', ', $sets) . '
                WHERE id = ?
            ');
            $stmt->execute($values);

            return [
                'id' => $classLevelId,
                'school_id' => $classLevel['school_id'],
                'name' => $name,
                'code' => $classLevel['code'],
                'sort_order' => $sortOrder,
                'status' => $status,
                'level_name' => $levelName,
                'group_name' => $groupName,
                'school_year' => $schoolYear,
                'message' => 'Class level updated successfully',
            ];
        } catch (PDOException) {
            return ['error' => 'Class level update failed'];
        }
    }

    public function delete(int $classLevelId): array
    {
        $pdo = Database::connect();

        $classLevel = $this->getById($classLevelId);
        if (!$classLevel) {
            return ['error' => 'Class level not found'];
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM class_levels WHERE id = ?');
            $stmt->execute([$classLevelId]);

            return ['id' => $classLevelId, 'message' => 'Class level deleted successfully'];
        } catch (PDOException) {
            return ['error' => 'Class level deletion failed'];
        }
    }

    private function nullable(mixed $value): ?string
    {
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }

    private function columnExists(string $column): bool
    {
        static $columns = null;

        if ($columns === null) {
            $pdo = Database::connect();
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
}
