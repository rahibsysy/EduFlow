<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FinancialDataService;
use DomainException;

class FinancialDataController
{
    public function unpaid(): void
    {
        $user = Request::get('auth_user', []);
        $schoolId = (int) ($user['school_id'] ?? 0);
        // Only the global role may select another school's projection. School-bound
        // users always use their authenticated school context.
        if (($user['role'] ?? '') === 'super_admin') {
            $schoolId = (int) ($_GET['school_id'] ?? 0);
        }
        if ($schoolId <= 0) {
            Response::json(['message' => 'A school context is required.'], 403);
        }

        try {
            Response::json((new FinancialDataService())->getSchoolUnpaidItems($schoolId, $_GET));
        } catch (DomainException $exception) {
            Response::json(['message' => $exception->getMessage()], 422);
        }
    }
}
