<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\MonthlyFeeService;

class MonthlyFeeController
{
    public function index(): void
    {
        $filters = [
            'month_label' => isset($_GET['month_label']) ? (string)$_GET['month_label'] : null,
            'year_value' => isset($_GET['year_value']) ? (int)$_GET['year_value'] : null,
            'status' => isset($_GET['status']) ? (string)$_GET['status'] : null,
            'search' => isset($_GET['search']) ? (string)$_GET['search'] : null,
            'class_level' => isset($_GET['class_level']) ? (string)$_GET['class_level'] : null,
            'class_name' => isset($_GET['class_name']) ? (string)$_GET['class_name'] : null,
        ];

        Response::json((new MonthlyFeeService())->getAll($filters));
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $fee = (new MonthlyFeeService())->getById($id);

        if (!$fee) {
            Response::json(['message' => 'Monthly fee not found'], 404);
        }

        Response::json($fee);
    }

    public function store(): void
    {
        $result = (new MonthlyFeeService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new MonthlyFeeService())->update($id, Request::json());

        if (isset($result['error'])) {
            $status = $result['error'] === 'Monthly fee not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new MonthlyFeeService())->delete($id);

        if (isset($result['error'])) {
            $status = $result['error'] === 'Monthly fee not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function unpaid(): void
    {
        Response::json((new MonthlyFeeService())->getUnpaid($_GET));
    }
}
