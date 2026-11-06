<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FamilyDiscountService;

class FamilyDiscountController
{
    private function out(array $result, int $status = 200): void
    {
        if (isset($result['error'])) Response::json(['message'=>$result['error'], 'conflicts'=>$result['conflicts'] ?? null], $result['status'] ?? 422);
        Response::json($result, $status);
    }
    public function index(): void { Response::json((new FamilyDiscountService())->all($_GET)); }
    public function show(): void { $row=(new FamilyDiscountService())->one((int)Request::param('id')); if(!$row) Response::json(['message'=>'Remise familiale introuvable'],404); Response::json($row); }
    public function preview(): void { $this->out((new FamilyDiscountService())->preview(Request::json())); }
    public function store(): void { $this->out((new FamilyDiscountService())->create(Request::json()), 201); }
    public function update(): void { $this->out((new FamilyDiscountService())->update((int)Request::param('id'), Request::json())); }
    public function cancel(): void { $this->out((new FamilyDiscountService())->cancel((int)Request::param('id'), Request::json())); }
    public function history(): void { $this->out(['history'=>(new FamilyDiscountService())->historyFor((int)Request::param('id'))]); }
}
