<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ClassLevelService;

class ClassLevelController
{
    public function index(): void
    {
        $schoolId = isset($_GET['school_id']) ? (int)$_GET['school_id'] : null;
        Response::json((new ClassLevelService())->getAll($schoolId));
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $classLevel = (new ClassLevelService())->getById($id);

        if (!$classLevel) {
            Response::json(['message' => 'Class level not found'], 404);
        }

        Response::json($classLevel);
    }

    public function store(): void
    {
        $result = (new ClassLevelService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new ClassLevelService())->update($id, Request::json());

        if (isset($result['error'])) {
            $status = $result['error'] === 'Class level not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new ClassLevelService())->delete($id);

        if (isset($result['error'])) {
            $status = $result['error'] === 'Class level not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }
}
