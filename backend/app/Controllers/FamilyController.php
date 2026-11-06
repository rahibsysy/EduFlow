<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\PermissionMiddleware;
use App\Services\FamilyService;
class FamilyController
{
 private const MEMBER_FINANCIAL_FIELDS=['monthly_amount','amount_paid','remaining_amount'];
 public function candidates():void{Response::json((new FamilyService())->candidates($_GET));}
 public function store():void{$r=(new FamilyService())->create(Request::json());if(isset($r['error']))Response::json(['message'=>$r['error']],422);Response::json($r,201);}
 public function show():void{$r=(new FamilyService())->show((int)Request::param('id',0));if(!$r)Response::json(['message'=>'Family not found'],404);if(!PermissionMiddleware::allows('finance.view'))$this->redactFinancialData($r);Response::json($r);}
 private function redactFinancialData(array &$family):void{unset($family['financial_projection']);if(!isset($family['members'])||!is_array($family['members']))return;foreach($family['members'] as &$member)foreach(self::MEMBER_FINANCIAL_FIELDS as $field)unset($member[$field]);unset($member);}
 public function attach():void{$r=(new FamilyService())->attach((int)Request::param('id',0),(int)(Request::json()['student_id']??0));if(isset($r['error']))Response::json(['message'=>$r['error']],422);Response::json($r);}
 public function detach():void{$r=(new FamilyService())->detach((int)Request::param('id',0));if(isset($r['error']))Response::json(['message'=>$r['error']],404);Response::json($r);}
 public function guardians():void{Response::json((new FamilyService())->guardians((int)Request::param('id',0)));}
 public function addGuardian():void{$r=(new FamilyService())->addGuardian((int)Request::param('id',0),Request::json());if(isset($r['error']))Response::json(['message'=>$r['error']],422);Response::json($r,201);}
}
