<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReceiptService;

class ReceiptController
{
    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new ReceiptService())->generateData($id);

        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 404);
        }

        Response::json($result);
    }

    public function downloadPdf(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new ReceiptService())->generatePdf($id);

        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 404);
        }

        Response::pdf($result['content'], $result['filename']);
    }
}
