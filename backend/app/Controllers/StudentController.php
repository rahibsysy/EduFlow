<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\StudentImportService;
use App\Services\StudentService;

class StudentController
{
    public function index(): void
    {
        Response::json((new StudentService())->getAll($_GET));
    }

    public function store(): void
    {
        $result = (new StudentService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new StudentService())->getById($id);
        if (!$result) {
            Response::json(['message' => 'Student not found'], 404);
        }

        Response::json($result);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new StudentService())->update($id, Request::json());
        if (isset($result['error'])) {
            $status = $result['error'] === 'Student not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function parentSummary(): void
    {
        $search = isset($_GET['search']) ? (string)$_GET['search'] : null;
        Response::json((new StudentService())->parentSummary($search));
    }

    public function importClass(): void
    {
        if (!isset($_FILES['students_file'])) {
            Response::json(['message' => 'Le fichier des eleves est obligatoire.'], 422);
        }

        $result = (new StudentImportService())->import($_FILES['students_file'], $_POST);
        if (isset($result['error'])) {
            Response::json([
                'message' => $result['error'],
                'details' => $result['details'] ?? [],
            ], 422);
        }

        Response::json($result, 201);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new StudentService())->delete($id);
        if (isset($result['error'])) {
            $status = $result['error'] === 'Student not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }
}
