<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\TeacherService;

class TeacherController
{
    public function index(): void
    {
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : null;
        Response::json((new TeacherService())->getAll($schoolId));
    }
}
