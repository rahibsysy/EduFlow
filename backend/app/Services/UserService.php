<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDOException;

class UserService
{
    private const DEFAULT_RESET_PASSWORD = 'EduFlow@123';

    public function getAll(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        $pdo = Database::connect();

        if ($role === 'super_admin') {
            $stmt = $pdo->query('SELECT u.id, u.school_id, u.first_name, u.last_name, u.email, u.role, u.status, u.created_at, s.name AS school_name, s.email_domain FROM users u LEFT JOIN schools s ON s.id = u.school_id ORDER BY u.id DESC');
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare('SELECT u.id, u.school_id, u.first_name, u.last_name, u.email, u.role, u.status, u.created_at, s.name AS school_name, s.email_domain FROM users u LEFT JOIN schools s ON s.id = u.school_id WHERE u.school_id = ? ORDER BY u.id DESC');
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $authUser = Request::get('auth_user', []);
        $actorRole = (string)($authUser['role'] ?? '');
        $actorSchoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        if (!in_array($actorRole, ['super_admin', 'admin'], true)) {
            return ['error' => 'Only admins can create users'];
        }

        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $status = strtoupper(trim((string)($data['status'] ?? 'ACTIVE')));

        if ($firstName === '' || $lastName === '' || $password === '') {
            return ['error' => 'first_name, last_name and password are required'];
        }

        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid user status'];
        }

        $targetRole = 'user';
        $targetSchoolId = $actorSchoolId;

        if ($actorRole === 'super_admin') {
            $targetRole = (string)($data['role'] ?? 'user');
            if (!in_array($targetRole, ['super_admin', 'admin', 'user', 'professeur'], true)) {
                return ['error' => 'Invalid role'];
            }

            $targetSchoolId = null;
            if ($targetRole !== 'super_admin') {
                $targetSchoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
                if (!$targetSchoolId || !$this->findSchool($targetSchoolId)) {
                    return ['error' => 'Valid school is required'];
                }
            }
        } else {
            if (!$actorSchoolId || !$this->findSchool($actorSchoolId)) {
                return ['error' => 'Admin school not found'];
            }

            $targetRole = (string)($data['role'] ?? 'user');
            if (!in_array($targetRole, ['user', 'professeur'], true)) {
                return ['error' => 'Admin can only create user/professeur'];
            }
            $targetSchoolId = $actorSchoolId;
        }

        $email = $this->buildEmail(
            (string)($data['email_local_part'] ?? ''),
            (string)($data['email'] ?? ''),
            $firstName,
            $lastName,
            $targetSchoolId
        );

        if ($email === null) {
            return ['error' => 'Unable to build email'];
        }

        return $this->insertUser(
            $targetSchoolId,
            $firstName,
            $lastName,
            $email,
            $password,
            $targetRole,
            $status
        );
    }

    public function update(int $userId, array $data): array
    {
        $authUser = Request::get('auth_user', []);
        $actorRole = (string)($authUser['role'] ?? '');
        $actorSchoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        if (!in_array($actorRole, ['super_admin', 'admin'], true)) {
            return ['error' => 'Only admins can update users'];
        }

        $existing = $this->findUserById($userId);
        if (!$existing) {
            return ['error' => 'User not found'];
        }

        if ($actorRole !== 'super_admin' && (int)$existing['school_id'] !== $actorSchoolId) {
            return ['error' => 'Forbidden'];
        }

        if ($actorRole !== 'super_admin' && in_array($existing['role'], ['super_admin', 'admin'], true)) {
            return ['error' => 'Admin cannot modify admin/super_admin'];
        }

        $firstName = trim((string)($data['first_name'] ?? $existing['first_name']));
        $lastName = trim((string)($data['last_name'] ?? $existing['last_name']));
        $status = strtoupper(trim((string)($data['status'] ?? $existing['status'])));

        if ($firstName === '' || $lastName === '') {
            return ['error' => 'first_name and last_name are required'];
        }

        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid user status'];
        }

        $role = (string)($existing['role']);
        if (isset($data['role'])) {
            $requestedRole = (string)$data['role'];
            if ($actorRole === 'super_admin') {
                if (!in_array($requestedRole, ['super_admin', 'admin', 'user', 'professeur'], true)) {
                    return ['error' => 'Invalid role'];
                }
                $role = $requestedRole;
            } else {
                if (!in_array($requestedRole, ['user', 'professeur'], true)) {
                    return ['error' => 'Admin can set only user/professeur role'];
                }
                $role = $requestedRole;
            }
        }

        $passwordHash = $existing['password'];
        if (!empty($data['password'])) {
            $passwordHash = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, role = ?, status = ?, password = ? WHERE id = ?');
        $stmt->execute([$firstName, $lastName, $role, $status, $passwordHash, $userId]);

        $updated = $this->findUserById($userId);
        return [
            'id' => (int)$updated['id'],
            'school_id' => $updated['school_id'] !== null ? (int)$updated['school_id'] : null,
            'first_name' => $updated['first_name'],
            'last_name' => $updated['last_name'],
            'email' => $updated['email'],
            'role' => $updated['role'],
            'status' => $updated['status'],
            'message' => 'User updated successfully',
        ];
    }

    public function resetPasswordToDefault(int $userId): array
    {
        $authUser = Request::get('auth_user', []);
        $actorRole = (string)($authUser['role'] ?? '');
        $actorId = isset($authUser['id']) ? (int)$authUser['id'] : 0;

        if ($actorRole !== 'super_admin') {
            return ['error' => 'Only super admin can reset passwords'];
        }

        $existing = $this->findUserById($userId);
        if (!$existing) {
            return ['error' => 'User not found'];
        }

        if ((int)$existing['id'] === $actorId) {
            return ['error' => 'Super admin cannot reset own password from this action'];
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash(self::DEFAULT_RESET_PASSWORD, PASSWORD_DEFAULT), $userId]);

        return [
            'id' => (int)$existing['id'],
            'email' => $existing['email'],
            'default_password' => self::DEFAULT_RESET_PASSWORD,
            'message' => 'Password reset successfully',
        ];
    }

    private function insertUser(?int $schoolId, string $firstName, string $lastName, string $email, string $password, string $role, string $status): array
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('INSERT INTO users (school_id, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$schoolId, $firstName, $lastName, $email, password_hash($password, PASSWORD_DEFAULT), $role, $status]);

            return [
                'id' => (int)$pdo->lastInsertId(),
                'school_id' => $schoolId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'role' => $role,
                'status' => $status,
                'message' => 'User created successfully',
            ];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                return ['error' => 'Email already exists'];
            }
            return ['error' => 'User creation failed'];
        }
    }

    private function buildEmail(string $localPart, string $fullEmail, string $firstName, string $lastName, ?int $schoolId): ?string
    {
        $fullEmail = trim(strtolower($fullEmail));
        if ($fullEmail !== '') {
            return $fullEmail;
        }

        if ($schoolId === null) {
            $fallbackLocal = $this->sanitizeLocalPart($localPart !== '' ? $localPart : ($firstName . '.' . $lastName));
            if ($fallbackLocal === '') {
                return null;
            }
            return $fallbackLocal . '@eduflow-owner.com';
        }

        $school = $this->findSchool($schoolId);
        if (!$school) {
            return null;
        }

        $baseLocal = $this->sanitizeLocalPart($localPart !== '' ? $localPart : ($firstName . '.' . $lastName));
        if ($baseLocal === '') {
            return null;
        }

        return $this->generateUniqueEmail($baseLocal, (string)$school['email_domain']);
    }

    private function generateUniqueEmail(string $baseLocal, string $domain): string
    {
        $pdo = Database::connect();
        $candidate = $baseLocal;
        $counter = 0;

        while (true) {
            $email = $candidate . '@' . strtolower($domain);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if (!$stmt->fetch()) {
                return $email;
            }

            $counter++;
            $candidate = $baseLocal . $counter;
        }
    }

    private function sanitizeLocalPart(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(' ', '.', $value);
        return (string)preg_replace('/[^a-z0-9._-]/', '', $value);
    }

    private function findSchool(int $schoolId): array|false
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, email_domain, status FROM schools WHERE id = ? LIMIT 1');
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();

        if (!$school || $school['status'] !== 'ACTIVE') {
            return false;
        }

        return $school;
    }

    private function findUserById(int $userId): array|false
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    public function getById(int $userId): array|false
    {
        $user = $this->findUserById($userId);
        if (!$user) {
            return false;
        }

        return [
            'id' => (int)$user['id'],
            'school_id' => $user['school_id'] ? (int)$user['school_id'] : null,
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'status' => $user['status'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
        ];
    }

    public function delete(int $userId): array
    {
        $authUser = Request::get('auth_user', []);
        $actorRole = (string)($authUser['role'] ?? '');
        $actorId = isset($authUser['id']) ? (int)$authUser['id'] : 0;

        if ($actorRole !== 'super_admin') {
            return ['error' => 'Forbidden'];
        }

        if ((int)$userId === $actorId) {
            return ['error' => 'Cannot delete your own account'];
        }

        $user = $this->findUserById($userId);
        if (!$user) {
            return ['error' => 'User not found'];
        }

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);

            return ['id' => $userId, 'message' => 'User deleted successfully'];
        } catch (PDOException) {
            return ['error' => 'User deletion failed'];
        }
    }
}
