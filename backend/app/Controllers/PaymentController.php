<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PaymentService;

class PaymentController
{
    public function index(): void
    {
        Response::json((new PaymentService())->getAll());
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $payment = (new PaymentService())->getById($id);

        if (!$payment) {
            Response::json(['message' => 'Payment not found'], 404);
        }

        Response::json($payment);
    }

    public function store(): void
    {
        $result = (new PaymentService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new PaymentService())->update($id, Request::json());

        if (isset($result['error'])) {
            $status = $result['error'] === 'Payment not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new PaymentService())->delete($id);

        if (isset($result['error'])) {
            $status = $result['error'] === 'Payment not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }
}
