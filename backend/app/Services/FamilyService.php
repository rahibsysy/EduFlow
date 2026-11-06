<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use App\Core\Request;

class FamilyService
{
    public function candidates(array $filters): array
    {
        $school=$this->school(); if(!$school)return [];$pdo=Database::connect();$q=trim((string)($filters['query']??''));if($q==='')return [];$term='%'.strtolower($q).'%';
        $families=$pdo->prepare('SELECT f.*, g.full_name primary_guardian_name, g.phone_primary FROM families f LEFT JOIN guardians g ON g.id=f.primary_guardian_id WHERE f.school_id=? AND (LOWER(f.family_name) LIKE ? OR LOWER(f.family_reference) LIKE ? OR LOWER(g.full_name) LIKE ? OR g.phone_primary LIKE ?) ORDER BY f.family_name LIMIT 12');$families->execute([$school,$term,$term,$term,$term]);
        $guardians=$pdo->prepare('SELECT id,full_name,phone_primary FROM guardians WHERE school_id=? AND (LOWER(full_name) LIKE ? OR phone_primary LIKE ?) ORDER BY full_name LIMIT 12');$guardians->execute([$school,$term,$term]);
        $students=$pdo->prepare('SELECT id,internal_number,first_name,last_name,class_name,status,family_id FROM students WHERE school_id=? AND (LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR LOWER(CONCAT(first_name," ",last_name)) LIKE ?) ORDER BY last_name LIMIT 12');$students->execute([$school,$term,$term,$term]);
        return ['families'=>$families->fetchAll(),'guardians'=>$guardians->fetchAll(),'students'=>$students->fetchAll()];
    }
    public function create(array $d): array
    {
        $school=$this->school();$name=trim((string)($d['family_name']??''));if(!$school||$name==='')return ['error'=>'family_name is required'];$pdo=Database::connect();$ref=trim((string)($d['family_reference']??''));if($ref===''){$s=$pdo->prepare('SELECT COUNT(*) FROM families WHERE school_id=?');$s->execute([$school]);$ref='FAM-'.$school.'-'.str_pad((string)((int)$s->fetchColumn()+1),5,'0',STR_PAD_LEFT);}$guardian=$this->guardianId($pdo,$school,$d['primary_guardian_id']??null);try{$s=$pdo->prepare('INSERT INTO families (school_id,family_reference,family_name,primary_guardian_id,address,notes) VALUES (?,?,?,?,?,?)');$s->execute([$school,$ref,$name,$guardian,$this->nullable($d['address']??null),$this->nullable($d['notes']??null)]);return ['id'=>(int)$pdo->lastInsertId(),'family_reference'=>$ref,'message'=>'Family created'];}catch(\PDOException $e){return ['error'=>'Family reference already exists for this school'];}
    }
    public function show(int $id): array|false
    {
        $school=$this->school();$pdo=Database::connect();$s=$pdo->prepare('SELECT f.*,g.full_name primary_guardian_name FROM families f LEFT JOIN guardians g ON g.id=f.primary_guardian_id WHERE f.id=? AND f.school_id=?');$s->execute([$id,$school]);$family=$s->fetch();if(!$family)return false;$m=$pdo->prepare('SELECT s.id,s.photo_path,s.internal_number,s.first_name,s.last_name,s.class_name,s.status,s.monthly_amount,COALESCE(SUM(mf.amount_paid),0) amount_paid,COALESCE(SUM(mf.remaining_amount),0) remaining_amount FROM students s LEFT JOIN monthly_fees mf ON mf.student_id=s.id AND mf.school_id=s.school_id WHERE s.family_id=? AND s.school_id=? GROUP BY s.id ORDER BY s.last_name,s.first_name');$m->execute([$id,$school]);$family['members']=$m->fetchAll();$family['financial_projection']=(new FinancialDataService())->getFamilyFinancialSummary((int)$school,$id);return $family;
    }
    public function attach(int $familyId,int $studentId):array{$school=$this->school();$pdo=Database::connect();$f=$pdo->prepare('SELECT id FROM families WHERE id=? AND school_id=?');$f->execute([$familyId,$school]);$s=$pdo->prepare('SELECT id FROM students WHERE id=? AND school_id=?');$s->execute([$studentId,$school]);if(!$f->fetch()||!$s->fetch())return ['error'=>'Family or student not found'];$pdo->prepare('UPDATE students SET family_id=? WHERE id=? AND school_id=?')->execute([$familyId,$studentId,$school]);return ['message'=>'Student attached to family'];}
    public function detach(int $studentId):array{$school=$this->school();$s=Database::connect()->prepare('UPDATE students SET family_id=NULL WHERE id=? AND school_id=?');$s->execute([$studentId,$school]);return $s->rowCount()?['message'=>'Student removed from family']:['error'=>'Student not found'];}
    public function addGuardian(int $studentId,array $d):array{$school=$this->school();$name=trim((string)($d['full_name']??''));$relation=trim((string)($d['relationship_type']??''));if(!$school||$name===''||$relation==='')return ['error'=>'full_name and relationship_type are required'];$pdo=Database::connect();$s=$pdo->prepare('SELECT id FROM students WHERE id=? AND school_id=?');$s->execute([$studentId,$school]);if(!$s->fetch())return ['error'=>'Student not found'];$g=$pdo->prepare('INSERT INTO guardians (school_id,full_name,phone_primary,phone_secondary,email,cin,address,profession) VALUES (?,?,?,?,?,?,?,?)');$g->execute([$school,$name,$this->nullable($d['phone_primary']??null),$this->nullable($d['phone_secondary']??null),$this->nullable($d['email']??null),$this->nullable($d['cin']??null),$this->nullable($d['address']??null),$this->nullable($d['profession']??null)]);$id=(int)$pdo->lastInsertId();$a=$pdo->prepare('INSERT INTO student_guardians (student_id,guardian_id,school_id,relationship_type,is_financial_responsible,is_emergency_contact) VALUES (?,?,?,?,?,?)');$a->execute([$studentId,$id,$school,$relation,(int)!empty($d['is_financial_responsible']),(int)!empty($d['is_emergency_contact'])]);return ['id'=>$id,'message'=>'Guardian added'];}
    public function guardians(int $studentId):array{$school=$this->school();$s=Database::connect()->prepare('SELECT g.*,sg.relationship_type,sg.is_financial_responsible,sg.is_emergency_contact FROM student_guardians sg INNER JOIN guardians g ON g.id=sg.guardian_id WHERE sg.student_id=? AND sg.school_id=?');$s->execute([$studentId,$school]);return $s->fetchAll();}
    private function school():?int{$u=Request::get('auth_user',[]);return isset($u['school_id'])?(int)$u['school_id']:null;}
    private function guardianId(\PDO $pdo,int $school,mixed $id):?int{$id=(int)$id;if(!$id)return null;$s=$pdo->prepare('SELECT id FROM guardians WHERE id=? AND school_id=?');$s->execute([$id,$school]);return $s->fetch()?$id:null;}
    private function nullable(mixed $v):?string{$v=trim((string)$v);return $v===''?null:$v;}
}
