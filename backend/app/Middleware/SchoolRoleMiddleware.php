<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

class SchoolRoleMiddleware
{
    public function __construct(private string|array $allowedRoles)
    {
    }

    public function handle(): void
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = (int)($authUser['school_id'] ?? 0);
        $allowedRoles = is_array($this->allowedRoles) ? $this->allowedRoles : [$this->allowedRoles];

        // A super administrator has no mandatory school context.  Services remain
        // responsible for applying a requested/explicit school scope where needed.
        if ($role === 'super_admin') {
            return;
        }

        if ($schoolId <= 0) {
            Response::json([
                'code' => 'SCHOOL_CONTEXT_REQUIRED',
                'message' => 'This action requires a user attached to a school.',
            ], 403);
        }

        if (!in_array($role, $allowedRoles, true)) {
            Response::json([
                'code' => 'ROLE_NOT_ALLOWED',
                'message' => 'Your role does not allow this action.',
            ], 403);
        }
    }
}
