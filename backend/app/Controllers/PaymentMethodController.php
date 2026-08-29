<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\PaymentMethodService;

class PaymentMethodController
{
    public function index(): void
    {
        Response::json((new PaymentMethodService())->getAll());
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $method = (new PaymentMethodService())->getById($id);

        if (!$method) {
            Response::json(['message' => 'Payment method not found'], 404);
        }

        Response::json($method);
    }

    public function store(): void
    {
        $result = (new PaymentMethodService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new PaymentMethodService())->update($id, Request::json());

        if (isset($result['error'])) {
            $status = $result['error'] === 'Payment method not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new PaymentMethodService())->delete($id);

        if (isset($result['error'])) {
            $status = $result['error'] === 'Payment method not found' ? 404 : 422;
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }
}
