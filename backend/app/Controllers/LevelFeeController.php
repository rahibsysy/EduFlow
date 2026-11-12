<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\LevelFeeService;

class LevelFeeController
{
    public function show(): void
    {
        $result = (new LevelFeeService())->show((int) Request::param('id', 0));
        if (!$result) Response::json(['message' => 'Classe introuvable'], 404);
        Response::json($result);
    }

    public function save(): void
    {
        $result = (new LevelFeeService())->save(Request::json());
        if (isset($result['error'])) Response::json(['message' => $result['error']], 422);
        Response::json($result);
    }
}
