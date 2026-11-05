<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

class PermissionMiddleware
{
    public function __construct(private string $permission)
    {
    }

    public function handle(): void
    {
        if (self::allows($this->permission)) return;
        Response::json(['message' => 'Accès non autorisé'], 403);
    }
    public static function allows(string $permission): bool
    {
        $user = Request::get('auth_user', []);
        if (($user['role'] ?? '') === 'super_admin') return true;
        // Finance management is deliberately a superset of finance read access.
        // Keep this implication here (server-side), rather than relying on the UI.
        if ($permission === 'finance.view' && self::allows('finance.manage')) return true;
        $stmt = Database::connect()->prepare('SELECT 1 FROM permissions p WHERE p.code=? AND (EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.permission_id=p.id AND rp.role=?) OR EXISTS (SELECT 1 FROM user_permissions up WHERE up.permission_id=p.id AND up.user_id=?)) LIMIT 1');
        $stmt->execute([$permission,(string)($user['role']??''),(int)($user['id']??0)]);
        return (bool)$stmt->fetchColumn();
    }
}
