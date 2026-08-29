<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDOException;

class SchoolService
{
    public function listAll(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('SELECT id, name, code, slug, email_domain, logo_path, phone, address, city, country, primary_color, secondary_color, currency, status, created_at FROM schools ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    public function getById(int $schoolId): array|false
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, name, code, slug, email_domain, logo_path, phone, address, city, country, primary_color, secondary_color, currency, status, created_at FROM schools WHERE id = ? LIMIT 1');
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch();
        return $school ? $this->withLogoDataUrl($school) : false;
    }

    public function create(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $logoPath = trim((string)($data['logo_path'] ?? ''));

        if ($name === '' || $code === '') {
            return ['error' => 'School name and code are required'];
        }

        $slug = $this->slugify((string)($data['slug'] ?? $name));
        if ($slug === '') {
            return ['error' => 'Invalid school slug'];
        }

        $emailDomain = strtolower(trim((string)($data['email_domain'] ?? ($slug . '.com'))));
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $emailDomain)) {
            return ['error' => 'Invalid email domain'];
        }

        $status = strtoupper(trim((string)($data['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid school status'];
        }

        $primaryColor = trim((string)($data['primary_color'] ?? '#1E3A8A'));
        $secondaryColor = trim((string)($data['secondary_color'] ?? '#22C55E'));
        $currency = strtoupper(trim((string)($data['currency'] ?? 'MAD')));

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('INSERT INTO schools (name, code, slug, email_domain, logo_path, phone, address, city, country, primary_color, secondary_color, currency, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $name,
                $code,
                $slug,
                $emailDomain,
                $logoPath !== '' ? $logoPath : null,
                $this->nullable($data['phone'] ?? null),
                $this->nullable($data['address'] ?? null),
                $this->nullable($data['city'] ?? null),
                $this->nullable($data['country'] ?? null),
                $primaryColor,
                $secondaryColor,
                $currency,
                $status,
            ]);

            $schoolId = (int)$pdo->lastInsertId();
            $school = $this->getById($schoolId);
            return $school ?: ['id' => $schoolId, 'message' => 'School created successfully'];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                return ['error' => 'School code, slug, or email domain already exists'];
            }
            return ['error' => 'School creation failed'];
        }
    }

    public function update(int $schoolId, array $data): array
    {
        $school = $this->getById($schoolId);
        if (!$school) {
            return ['error' => 'School not found'];
        }

        $name = trim((string)($data['name'] ?? $school['name']));
        $code = strtoupper(trim((string)($data['code'] ?? $school['code'])));
        $slug = $this->slugify((string)($data['slug'] ?? $school['slug']));
        $emailDomain = strtolower(trim((string)($data['email_domain'] ?? $school['email_domain'])));
        $status = strtoupper(trim((string)($data['status'] ?? $school['status'])));

        if ($name === '' || $code === '' || $slug === '') {
            return ['error' => 'School name, code, and slug are required'];
        }
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $emailDomain)) {
            return ['error' => 'Invalid email domain'];
        }
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid school status'];
        }

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('UPDATE schools SET name = ?, code = ?, slug = ?, email_domain = ?, logo_path = ?, phone = ?, address = ?, city = ?, country = ?, primary_color = ?, secondary_color = ?, currency = ?, status = ? WHERE id = ?');
            $stmt->execute([
                $name,
                $code,
                $slug,
                $emailDomain,
                $data['logo_path'] ?? $school['logo_path'],
                $this->nullable($data['phone'] ?? $school['phone']),
                $this->nullable($data['address'] ?? $school['address']),
                $this->nullable($data['city'] ?? $school['city']),
                $this->nullable($data['country'] ?? $school['country']),
                $data['primary_color'] ?? $school['primary_color'],
                $data['secondary_color'] ?? $school['secondary_color'],
                strtoupper(trim((string)($data['currency'] ?? $school['currency']))),
                $status,
                $schoolId,
            ]);

            $updated = $this->getById($schoolId);
            return $updated ?: ['id' => $schoolId, 'message' => 'School updated'];
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                return ['error' => 'School code, slug, or email domain already exists'];
            }
            return ['error' => 'School update failed'];
        }
    }

    public function createSchoolAdmin(int $schoolId, array $data): array
    {
        $school = $this->getById($schoolId);
        if (!$school) {
            return ['error' => 'School not found'];
        }

        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $status = strtoupper(trim((string)($data['status'] ?? 'ACTIVE')));

        if ($firstName === '' || $lastName === '' || $password === '') {
            return ['error' => 'first_name, last_name, and password are required'];
        }

        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid user status'];
        }

        $localPart = strtolower(trim((string)($data['email_local_part'] ?? '')));
        if ($localPart === '') {
            $localPart = $this->slugify($firstName . '.' . $lastName);
        }

        $email = $this->generateUniqueEmail($localPart, $school['email_domain']);

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('INSERT INTO users (school_id, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $schoolId,
                $firstName,
                $lastName,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                'admin',
                $status,
            ]);

            return [
                'id' => (int)$pdo->lastInsertId(),
                'school_id' => $schoolId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'role' => 'admin',
                'status' => $status,
                'message' => 'School admin created successfully',
            ];
        } catch (PDOException $e) {
            return ['error' => 'Failed to create school admin'];
        }
    }

    public function getCurrent(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        if ($role === 'super_admin') {
            $schools = $this->listAll();
            $school = $schools[0] ?? [
                'id' => null,
                'name' => null,
                'code' => null,
                'slug' => null,
                'email_domain' => null,
                'logo_path' => null,
                'status' => null,
            ];
            return $this->withLogoDataUrl($school);
        }

        $school = $schoolId ? $this->getById($schoolId) : false;
        return $this->withLogoDataUrl($school ?: [
            'id' => null,
            'name' => null,
            'code' => null,
            'slug' => null,
            'email_domain' => null,
            'logo_path' => null,
            'status' => null,
        ]);
    }

    private function withLogoDataUrl(array $school): array
    {
        $school['logo_data_url'] = null;
        $logoPath = trim((string)($school['logo_path'] ?? ''));
        if ($logoPath === '' || preg_match('/^https?:\/\//i', $logoPath)) {
            return $school;
        }

        $normalized = ltrim(str_replace('\\', '/', $logoPath), '/');
        $absolutePath = realpath(__DIR__ . '/../../public/' . $normalized);
        $publicRoot = realpath(__DIR__ . '/../../public');
        if (!$absolutePath || !$publicRoot || !str_starts_with($absolutePath, $publicRoot) || !is_file($absolutePath)) {
            return $school;
        }

        $mime = mime_content_type($absolutePath) ?: 'image/png';
        if (!str_starts_with($mime, 'image/')) {
            return $school;
        }

        $content = file_get_contents($absolutePath);
        if ($content === false) {
            return $school;
        }

        $school['logo_data_url'] = 'data:' . $mime . ';base64,' . base64_encode($content);
        return $school;
    }

    private function generateUniqueEmail(string $localPart, string $domain): string
    {
        $pdo = Database::connect();

        $base = preg_replace('/[^a-z0-9._-]/', '', strtolower($localPart));
        if ($base === '') {
            $base = 'user';
        }

        $candidate = $base;
        $counter = 0;

        while (true) {
            $email = $candidate . '@' . strtolower($domain);
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            if (!$stmt->fetch()) {
                return $email;
            }
            $counter++;
            $candidate = $base . $counter;
        }
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        return trim((string)$value, '-');
    }

    private function nullable(mixed $value): ?string
    {
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }

    public function delete(int $schoolId): array
    {
        $school = $this->getById($schoolId);
        if (!$school) {
            return ['error' => 'School not found'];
        }

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('DELETE FROM schools WHERE id = ?');
            $stmt->execute([$schoolId]);

            return ['id' => $schoolId, 'message' => 'School deleted successfully'];
        } catch (PDOException) {
            return ['error' => 'School deletion failed'];
        }
    }
}
