<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\AlertService;

class AlertController
{
    public function index(): void
    {
        Response::json((new AlertService())->alerts());
    }
}
