<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;

class UserController
{
    public function index(): void
    {
        Response::json((new UserService())->getAll());
    }

    public function store(): void
    {
        $result = (new UserService())->create(Request::json());
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new UserService())->getById($id);
        if (!$result) {
            Response::json(['message' => 'User not found'], 404);
        }

        Response::json($result);
    }

    public function update(): void
    {
        $userId = (int)Request::param('id', 0);
        $result = (new UserService())->update($userId, Request::json());
        if (isset($result['error'])) {
            $status = in_array($result['error'], ['User not found'], true) ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function resetPassword(): void
    {
        $userId = (int)Request::param('id', 0);
        $result = (new UserService())->resetPasswordToDefault($userId);

        if (isset($result['error'])) {
            $status = 422;
            if ($result['error'] === 'User not found') {
                $status = 404;
            } elseif (
                $result['error'] === 'Only super admin can reset passwords' ||
                $result['error'] === 'Super admin cannot reset own password from this action'
            ) {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function delete(): void
    {
        $userId = (int)Request::param('id', 0);
        $result = (new UserService())->delete($userId);

        if (isset($result['error'])) {
            $status = $result['error'] === 'User not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }
}