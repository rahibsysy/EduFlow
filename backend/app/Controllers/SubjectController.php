<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\SubjectService;

class SubjectController
{
    public function index(): void
    {
        $filters = [
            'class_level_id' => $_GET['class_level_id'] ?? null,
            'school_id' => $_GET['school_id'] ?? null,
        ];

        Response::json((new SubjectService())->getAll($filters));
    }
}
