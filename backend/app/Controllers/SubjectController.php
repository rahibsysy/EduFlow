<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Request;
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

    public function updateClassLevelHours(): void
    {
        $subjectId = (int)Request::param('id', 0);
        $classLevelId = (int)Request::param('classLevelId', 0);
        $result = (new SubjectService())->setWeeklyHours($subjectId, $classLevelId, Request::json());

        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result);
    }
}
