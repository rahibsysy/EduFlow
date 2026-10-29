<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\InitialSetupService;

class InitialSetupController
{
    public function status(): void
    {
        Response::json((new InitialSetupService())->status(
            Request::header('X-Setup-Secret'),
            Request::clientIp(),
        ));
    }

    public function install(): void
    {
        $result = (new InitialSetupService())->install(
            Request::json(),
            Request::header('X-Setup-Secret'),
            Request::clientIp(),
        );
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], (int)($result['status'] ?? 422));
        }
        Response::json(['message' => $result['message']], (int)($result['status'] ?? 201));
    }
}
