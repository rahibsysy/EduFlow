<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class PermissionController
{
    public function mine(): void
    {
        $role = (string) (Request::get('auth_user', [])['role'] ?? '');
        $userId = (int)(Request::get('auth_user', [])['id'] ?? 0);
        if ($role === 'super_admin') {
            $permissions = Database::connect()->query('SELECT code FROM permissions ORDER BY code')->fetchAll(\PDO::FETCH_COLUMN);
        } else {
            $stmt = Database::connect()->prepare('
                SELECT DISTINCT p.code
                FROM permissions p
                LEFT JOIN role_permissions rp ON rp.permission_id = p.id AND rp.role = ?
                LEFT JOIN user_permissions up ON up.permission_id = p.id AND up.user_id = ?
                WHERE rp.permission_id IS NOT NULL OR up.permission_id IS NOT NULL
                ORDER BY p.code
            ');
            $stmt->execute([$role, $userId]);
            $permissions = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        Response::json(['permissions' => $permissions]);
    }
}
