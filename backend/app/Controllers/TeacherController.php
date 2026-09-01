<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\Request;
use App\Services\TeacherService;

class TeacherController
{
    public function index(): void
    {
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : null;
        Response::json((new TeacherService())->getAll($schoolId));
    }

    public function store(): void
    {
        $result = (new TeacherService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new TeacherService())->update($id, Request::json());

        if (isset($result['error'])) {
            $status = $result['error'] === 'Teacher not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new TeacherService())->delete($id);

        if (isset($result['error'])) {
            $status = $result['error'] === 'Teacher not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }
}
