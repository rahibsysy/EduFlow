<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

class TeacherService
{
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
                u.role,
                u.status
            FROM users u
            WHERE u.status = "ACTIVE"
              AND u.role = "user"
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
        return $stmt->fetchAll();
    }
}
