<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

class InitialSetupService
{
    private const LOCK_NAME = 'miranda_initial_setup';

    public function status(?string $providedSecret, ?string $clientIp): array
    {
        if (!InitialSetupAccessPolicy::allows($providedSecret, $clientIp)) {
            return ['allowed' => false];
        }

        $pdo = Database::connect();
        return ['allowed' => $this->isAllowed($pdo)];
    }

    public function install(array $data, ?string $providedSecret, ?string $clientIp): array
    {
        if (!InitialSetupAccessPolicy::allows($providedSecret, $clientIp)) {
            return InitialSetupAccessPolicy::unavailableResponse();
        }

        $validated = $this->validate($data);
        if (isset($validated['error'])) {
            return $validated;
        }

        $pdo = Database::connect();
        if ((int)$pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 15)")->fetchColumn() !== 1) {
            return ['error' => 'Initial setup is busy. Please try again.', 'status' => 503];
        }

        try {
            $pdo->beginTransaction();
            if (!$this->isAllowed($pdo)) {
                $pdo->rollBack();
                return ['error' => 'Initial setup has already been completed.', 'status' => 409];
            }

            $school = $pdo->prepare(
                'INSERT INTO schools (name, code, slug, email_domain, primary_color, secondary_color, currency, status) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $school->execute([
                $validated['school_name'],
                $validated['school_code'],
                $validated['school_slug'],
                $validated['email_domain'],
                '#1E3A8A',
                '#22C55E',
                'MAD',
                'ACTIVE',
            ]);
            $schoolId = (int)$pdo->lastInsertId();

            $user = $pdo->prepare(
                'INSERT INTO users (school_id, first_name, last_name, email, password, role, status, must_change_password) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $user->execute([
                $schoolId,
                $validated['first_name'],
                $validated['last_name'],
                $validated['email'],
                password_hash($validated['password'], PASSWORD_DEFAULT),
                'super_admin',
                'ACTIVE',
                0,
            ]);

            $pdo->commit();
            return ['message' => 'Initial setup completed. You can now sign in.', 'status' => 201];
        } catch (\Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => 'Initial setup could not be completed.', 'status' => 500];
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::LOCK_NAME]);
        }
    }

    private function isAllowed(PDO $pdo): bool
    {
        $statement = $pdo->query('SELECT (SELECT COUNT(*) FROM schools) AS schools_count, (SELECT COUNT(*) FROM users) AS users_count');
        $counts = $statement->fetch();
        return self::canInstallWithCounts((int)($counts['schools_count'] ?? 0), (int)($counts['users_count'] ?? 0));
    }

    public static function canInstallWithCounts(int $schoolsCount, int $usersCount): bool
    {
        return $schoolsCount === 0 && $usersCount === 0;
    }

    private function validate(array $data): array
    {
        $schoolName = trim((string)($data['school_name'] ?? ''));
        $schoolCode = strtoupper(trim((string)($data['school_code'] ?? '')));
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $passwordConfirmation = (string)($data['password_confirmation'] ?? '');

        if ($schoolName === '' || mb_strlen($schoolName) > 150) {
            return ['error' => 'School name is required and must not exceed 150 characters.', 'status' => 422];
        }
        if (!preg_match('/^[A-Z0-9_-]{2,50}$/', $schoolCode)) {
            return ['error' => 'School code must contain 2 to 50 uppercase letters, numbers, hyphens or underscores.', 'status' => 422];
        }
        if ($firstName === '' || mb_strlen($firstName) > 100 || $lastName === '' || mb_strlen($lastName) > 100) {
            return ['error' => 'First name and last name are required.', 'status' => 422];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            return ['error' => 'A valid email address is required.', 'status' => 422];
        }
        if (!$this->isStrongPassword($password)) {
            return ['error' => 'Password must contain at least 10 characters, uppercase, lowercase, number and symbol.', 'status' => 422];
        }
        if (!hash_equals($password, $passwordConfirmation)) {
            return ['error' => 'Password confirmation does not match.', 'status' => 422];
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        $slug = $this->slugify($schoolName);
        if ($slug === '' || $domain === '') {
            return ['error' => 'School name or email domain is invalid.', 'status' => 422];
        }

        return [
            'school_name' => $schoolName,
            'school_code' => $schoolCode,
            'school_slug' => $slug,
            'email_domain' => $domain,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
        ];
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: '';
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
        return trim($value, '-');
    }

    private function isStrongPassword(string $password): bool
    {
        return strlen($password) >= 10
            && preg_match('/[a-z]/', $password)
            && preg_match('/[A-Z]/', $password)
            && preg_match('/\d/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }
}
