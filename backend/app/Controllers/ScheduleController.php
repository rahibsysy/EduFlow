<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ScheduleService;

class ScheduleController
{
    public function index(): void
    {
        $filters = [
            'school_id' => $_GET['school_id'] ?? null,
            'class_level_id' => $_GET['class_level_id'] ?? null,
            'teacher_id' => $_GET['teacher_id'] ?? null,
            'teacher_name' => $_GET['teacher_name'] ?? null,
            'year_value' => $_GET['year_value'] ?? null,
            'week_number' => $_GET['week_number'] ?? null,
            'day_of_week' => $_GET['day_of_week'] ?? null,
            'status' => $_GET['status'] ?? null,
        ];

        Response::json((new ScheduleService())->getAll($filters));
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $schedule = (new ScheduleService())->getById($id);

        if (!$schedule) {
            Response::json(['message' => 'Schedule not found'], 404);
        }

        Response::json($schedule);
    }

    public function store(): void
    {
        $result = (new ScheduleService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], !empty($result['conflict']) ? 409 : 422);
        }

        Response::json($result, 201);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new ScheduleService())->update($id, Request::json());

        if (isset($result['error'])) {
            $status = $result['error'] === 'Schedule not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            if (!empty($result['conflict'])) {
                $status = 409;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new ScheduleService())->delete($id);

        if (isset($result['error'])) {
            $status = $result['error'] === 'Schedule not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }
}
