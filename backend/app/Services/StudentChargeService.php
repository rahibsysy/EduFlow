<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;

/** Financial charges stored alongside the existing monthly-fee/payment module. */
class StudentChargeService
{
    private function auth(): array { return (array) Request::get('auth_user', []); }
    private function school(): int { return (int) ($this->auth()['school_id'] ?? 0); }
    private function user(): ?int { $id = $this->auth()['id'] ?? null; return $id ? (int) $id : null; }
    private function money(mixed $value): float { return round((float) $value + 0.0000001, 2); }

    private function category(int $id): array|false
    {
        $s = Database::connect()->prepare('SELECT * FROM charge_categories WHERE id=? AND school_id=? AND status="ACTIVE"');
        $s->execute([$id, $this->school()]);
        return $s->fetch() ?: false;
    }

    private function academicYear(?int $id): bool
    {
        if (!$id) return true;
        $s = Database::connect()->prepare('SELECT id FROM academic_years WHERE id=? AND school_id=?');
        $s->execute([$id, $this->school()]);
        return (bool) $s->fetch();
    }

    private function activeAcademicYearId(): ?int
    {
        $stmt = Database::connect()->prepare('
            SELECT ay.id
            FROM school_settings settings
            INNER JOIN academic_years ay
                ON ay.id = CAST(settings.setting_value AS UNSIGNED)
               AND ay.school_id = settings.school_id
            WHERE settings.school_id = ?
              AND settings.setting_key = "active_academic_year_id"
            LIMIT 1
        ');
        $stmt->execute([$this->school()]);
        $id = (int)($stmt->fetchColumn() ?: 0);

        return $id > 0 ? $id : null;
    }

    private function validStudents(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $s = Database::connect()->prepare("SELECT id,first_name,last_name FROM students WHERE school_id=? AND id IN ($in)");
        $s->execute([$this->school(), ...$ids]);
        $rows = $s->fetchAll();
        return count($rows) === count($ids) ? $rows : [];
    }

    private function discount(float $amount, ?string $type, mixed $value): array
    {
        if ($type === null || $type === '') return ['type'=>null, 'value'=>null, 'amount'=>0.0, 'final'=>$amount];
        $value = $this->money($value);
        if (!in_array($type, ['PERCENTAGE','FIXED'], true) || $value < 0 || ($type === 'PERCENTAGE' && $value > 100)) {
            return ['error'=>'Réduction invalide'];
        }
        $removed = $type === 'PERCENTAGE' ? $this->money($amount * $value / 100) : $value;
        if ($removed > $amount) return ['error'=>'La réduction ne peut pas dépasser le montant initial'];
        return ['type'=>$type, 'value'=>$value, 'amount'=>$removed, 'final'=>$this->money($amount-$removed)];
    }

    public function all(array $filters = []): array
    {
        $where = ['fi.school_id=?']; $params = [$this->school()];
        foreach (['student_id'=>'fi.student_id','category_id'=>'d.charge_category_id','status'=>'fi.status','year_value'=>'d.year_value','billing_month'=>'d.billing_month'] as $key=>$column) {
            if (($filters[$key] ?? '') !== '') { $where[] = "$column=?"; $params[] = $filters[$key]; }
        }
        $sql = 'SELECT fi.*,d.charge_category_id,d.billing_month,d.year_value,d.due_date,d.original_amount,d.discount_type,d.discount_value,d.final_amount,d.notes,c.code AS category_code,c.label AS category_label,s.first_name,s.last_name,GREATEST(0,d.final_amount-fi.paid_amount) AS remaining_amount FROM student_financial_items fi JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id LEFT JOIN charge_categories c ON c.id=d.charge_category_id JOIN students s ON s.id=fi.student_id WHERE '.implode(' AND ', $where).' ORDER BY d.due_date IS NULL,d.due_date,fi.id DESC';
        $q=Database::connect()->prepare($sql); $q->execute($params); return $q->fetchAll();
    }

    public function one(int $id): array|false
    {
        $rows=$this->all(['id'=>null]);
        $q=Database::connect()->prepare('SELECT fi.*,d.charge_category_id,d.billing_month,d.year_value,d.due_date,d.original_amount,d.discount_type,d.discount_value,d.final_amount,d.notes,c.code AS category_code,c.label AS category_label,s.first_name,s.last_name,GREATEST(0,d.final_amount-fi.paid_amount) AS remaining_amount FROM student_financial_items fi JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id LEFT JOIN charge_categories c ON c.id=d.charge_category_id JOIN students s ON s.id=fi.student_id WHERE fi.id=? AND fi.school_id=?');
        $q->execute([$id,$this->school()]); return $q->fetch()?:false;
    }

    public function preview(array $data): array
    {
        $studentIds = $data['student_ids'] ?? (isset($data['student_id']) ? [$data['student_id']] : []);
        $students = $this->validStudents((array)$studentIds);
        $category = $this->category((int)($data['charge_category_id'] ?? 0));
        $yearId = isset($data['academic_year_id']) && $data['academic_year_id'] !== ''
            ? (int)$data['academic_year_id']
            : $this->activeAcademicYearId();
        if (!$students || !$category || !$this->academicYear($yearId)) return ['error'=>'Élève, catégorie ou année scolaire invalide'];
        $months = $data['months'] ?? [($data['billing_month'] ?? null)];
        $months = array_values(array_unique(array_map(static fn($m) => $m === null || $m === '' ? null : (int)$m, (array)$months)));
        if (!$months) $months=[null]; foreach ($months as $m) if ($m !== null && ($m < 1 || $m > 12)) return ['error'=>'Mois invalide'];
        if (($category['periodicity'] ?? '') === 'MONTHLY' && in_array(null, $months, true)) return ['error'=>'Une période est obligatoire pour ce frais récurrent'];
        $base = array_key_exists('original_amount',$data) ? $this->money($data['original_amount']) : $this->money($category['default_amount'] ?? 0);
        if ($base < 0) return ['error'=>'Montant invalide'];
        $discount=$this->discount($base, isset($data['discount_type']) ? (string)$data['discount_type'] : null, $data['discount_value'] ?? null); if(isset($discount['error']))return $discount;
        $lines=[]; $duplicates=[]; $pdo=Database::connect();
        foreach($students as $student) foreach($months as $month) {
            $amount=array_key_exists((string)$student['id'],(array)($data['amounts']??[]))?$this->money($data['amounts'][(string)$student['id']]):$base;
            $lineDiscount=$this->discount($amount,$discount['type'],$discount['value']); if(isset($lineDiscount['error']))return $lineDiscount;
            $exists=$pdo->prepare('SELECT id FROM student_financial_items WHERE school_id=? AND student_id=? AND academic_year_id <=> ? AND item_code=? AND billing_month <=> ? LIMIT 1');
            $exists->execute([$this->school(),$student['id'],$yearId,$category['code'],$month]); $existing=$exists->fetchColumn();
            $line=['student_id'=>(int)$student['id'],'student_name'=>trim($student['first_name'].' '.$student['last_name']),'billing_month'=>$month,'original_amount'=>$amount,'discount_type'=>$lineDiscount['type'],'discount_value'=>$lineDiscount['value'],'discount_amount'=>$lineDiscount['amount'],'final_amount'=>$lineDiscount['final'],'duplicate_charge_id'=>$existing ? (int)$existing : null];
            $lines[]=$line; if($existing)$duplicates[]=$line;
        }
        return ['category'=>['id'=>(int)$category['id'],'code'=>$category['code'],'label'=>$category['label']],'academic_year_id'=>$yearId,'lines'=>$lines,'duplicates'=>$duplicates,'total'=>$this->money(array_sum(array_column($lines,'final_amount')))];
    }

    private function history(PDO $pdo,int $item,string $action,?array $before,?array $after,?string $reason=null):void
    { $pdo->prepare('INSERT INTO student_financial_item_history(school_id,student_financial_item_id,action,before_data,after_data,reason,changed_by) VALUES(?,?,?,?,?,?,?)')->execute([$this->school(),$item,$action,$before?json_encode($before):null,$after?json_encode($after):null,$reason,$this->user()]); }

    public function bulk(array $data): array
    {
        $preview=$this->preview($data); if(isset($preview['error'])) return $preview;
        if($preview['duplicates']) return ['error'=>'Des charges identiques existent déjà','status'=>409,'duplicates'=>$preview['duplicates']];
        $pdo=Database::connect(); $ownsTransaction = !$pdo->inTransaction(); if ($ownsTransaction) $pdo->beginTransaction();
        try { $created=[]; $due=$data['due_date']??null; if($due && !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$due)) throw new \InvalidArgumentException('Date d’échéance invalide');
            foreach($preview['lines'] as $line){
                $period=$line['billing_month']===null?'INITIAL':sprintf('%04d-%02d',(int)($data['year_value']??0),(int)$line['billing_month']);
                $operation=trim((string)($data['operation_key']??''));
                $key=$operation!==''?$operation.':student:'.$line['student_id']:'manual:student:'.$line['student_id'].':year:'.(int)$preview['academic_year_id'].':category:'.(int)$preview['category']['id'].':period:'.$period;
                try {$pdo->prepare('INSERT INTO student_financial_items(school_id,student_id,academic_year_id,item_code,idempotency_key,label,billing_month,quantity,unit_amount,total_amount,discount_amount,paid_amount,status,justification,created_by) VALUES(?,?,?,?,?,?,?,1,?,?,?,0,"UNPAID",?,?)')->execute([$this->school(),$line['student_id'],$preview['academic_year_id'],$preview['category']['code'],$key,$preview['category']['label'],$line['billing_month'],$line['original_amount'],$line['final_amount'],$line['discount_amount'],$data['notes']??null,$this->user()]);}
                catch(\PDOException $e){if($e->getCode()==='23000'){ $existing=$pdo->prepare('SELECT id FROM student_financial_items WHERE school_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$this->school(),$key]);$id=(int)$existing->fetchColumn();if($id){$created[]=$id;continue;}}throw $e;}
                $id=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO student_financial_item_details(student_financial_item_id,school_id,charge_category_id,billing_month,year_value,due_date,original_amount,discount_type,discount_value,final_amount,notes,modified_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$this->school(),$preview['category']['id'],$line['billing_month'],$data['year_value']??null,$due,$line['original_amount'],$line['discount_type'],$line['discount_value'],$line['final_amount'],$data['notes']??null,$this->user()]);
                $this->history($pdo,$id,'CREATED',null,['final_amount'=>$line['final_amount'],'category_id'=>$preview['category']['id']],$data['notes']??null); $created[]=$id;
            } if ($ownsTransaction) $pdo->commit(); return ['ids'=>array_values(array_unique($created)),'count'=>count(array_unique($created)),'total'=>$preview['total']];
        } catch(\Throwable $e){if($ownsTransaction && $pdo->inTransaction())$pdo->rollBack();return ['error'=>'Création de charge impossible'];}
    }
    public function create(array $data):array{return $this->bulk($data);}
    public function cancel(int $id, array $data = []): array
    {
        $old=$this->one($id); if(!$old)return['error'=>'Charge introuvable','status'=>404];
        if((float)$old['paid_amount']>0)return['error'=>'Une charge payée ou partiellement payée ne peut pas être annulée','status'=>409];
        $reason=trim((string)($data['reason']??''));
        $pdo=Database::connect();
        $pdo->prepare('UPDATE student_financial_items SET status="EXEMPT",justification=? WHERE id=? AND school_id=?')->execute([$reason?:$old['notes'],$id,$this->school()]);
        $new=$this->one($id); $this->history($pdo,$id,'CANCELLED',$old,$new,$reason?:null); return $new;
    }
    public function update(int $id,array $data):array
    { $old=$this->one($id);if(!$old)return['error'=>'Charge introuvable','status'=>404];if((float)$old['paid_amount']>0)return['error'=>'Une charge payée ou partiellement payée ne peut pas être modifiée','status'=>409];$amount=array_key_exists('original_amount',$data)?$this->money($data['original_amount']):$this->money($old['original_amount']);$d=$this->discount($amount,$data['discount_type']??$old['discount_type'],$data['discount_value']??$old['discount_value']);if(isset($d['error']))return$d;$pdo=Database::connect();$ownsTransaction=!$pdo->inTransaction();if($ownsTransaction)$pdo->beginTransaction();try{$pdo->prepare('UPDATE student_financial_items SET unit_amount=?,total_amount=?,discount_amount=?,justification=? WHERE id=? AND school_id=?')->execute([$amount,$d['final'],$d['amount'],$data['notes']??$old['notes'],$id,$this->school()]);$pdo->prepare('UPDATE student_financial_item_details SET due_date=?,original_amount=?,discount_type=?,discount_value=?,final_amount=?,notes=?,modified_by=? WHERE student_financial_item_id=? AND school_id=?')->execute([$data['due_date']??$old['due_date'],$amount,$d['type'],$d['value'],$d['final'],$data['notes']??$old['notes'],$this->user(),$id,$this->school()]);$new=$this->one($id);$this->history($pdo,$id,'UPDATED',$old,$new,$data['reason']??null);if($ownsTransaction)$pdo->commit();return$new;}catch(\Throwable $e){if($ownsTransaction&&$pdo->inTransaction())$pdo->rollBack();return['error'=>$e->getMessage()];}}
    public function historyFor(int $id):array{$q=Database::connect()->prepare('SELECT h.* FROM student_financial_item_history h WHERE h.student_financial_item_id=? AND h.school_id=? ORDER BY h.id DESC');$q->execute([$id,$this->school()]);return$q->fetchAll();}

    public function unpaid(array $filters = []): array
    {
        $where = ['fi.school_id = ?', 'fi.status IN ("UNPAID", "PARTIAL")', 'details.due_date IS NOT NULL', 'details.due_date <= CURDATE()'];
        $params = [$this->school()];
        if (!empty($filters['student_id'])) { $where[] = 'fi.student_id = ?'; $params[] = (int)$filters['student_id']; }
        if (!empty($filters['academic_year_id'])) { $where[] = 'fi.academic_year_id = ?'; $params[] = (int)$filters['academic_year_id']; }
        $stmt = Database::connect()->prepare('
            SELECT fi.id, fi.school_id, fi.student_id, fi.label, fi.status, fi.paid_amount,
                   details.due_date, details.final_amount,
                   GREATEST(0, details.final_amount - fi.paid_amount) AS remaining_amount,
                   s.first_name, s.last_name
            FROM student_financial_items fi
            INNER JOIN student_financial_item_details details ON details.student_financial_item_id = fi.id AND details.school_id = fi.school_id
            INNER JOIN students s ON s.id = fi.student_id AND s.school_id = fi.school_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY details.due_date, s.last_name, s.first_name, fi.id
        ');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
