<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;

/** Applies one auditable discount to eligible flexible charges of all children in a family. */
class FamilyDiscountService
{
    private function school(): int { return (int)(Request::get('auth_user', [])['school_id'] ?? 0); }
    private function user(): ?int { $id=Request::get('auth_user', [])['id'] ?? null; return $id ? (int)$id : null; }
    private function money(mixed $value): float { return round((float)$value + 0.0000001, 2); }
    private function ids(mixed $values): array { return array_values(array_unique(array_filter(array_map('intval', (array)$values)))); }

    private function family(int $id): array|false
    {
        $q=Database::connect()->prepare('SELECT id,family_name,family_reference FROM families WHERE id=? AND school_id=?');
        $q->execute([$id,$this->school()]); return $q->fetch() ?: false;
    }

    private function validate(array $data, ?array $current=null): array
    {
        $familyId=(int)($data['family_id'] ?? $current['family_id'] ?? 0);
        $type=(string)($data['discount_type'] ?? $current['discount_type'] ?? '');
        $value=$this->money($data['discount_value'] ?? $current['discount_value'] ?? -1);
        $reason=trim((string)($data['reason'] ?? $current['reason'] ?? ''));
        $starts=$data['starts_on'] ?? $current['starts_on'] ?? null;
        $ends=$data['ends_on'] ?? $current['ends_on'] ?? null;
        if (!$this->family($familyId) || !in_array($type,['PERCENTAGE','FIXED'],true) || $value < 0 || ($type==='PERCENTAGE' && $value>100) || $reason==='') return ['error'=>'Famille, motif ou remise invalide'];
        foreach (['starts'=>$starts,'ends'=>$ends] as $key=>$date) if ($date!==null && $date!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$date)) return ['error'=>'Date de remise invalide'];
        if ($starts && $ends && $ends < $starts) return ['error'=>'La date de fin doit être postérieure à la date de début'];
        return ['family_id'=>$familyId,'type'=>$type,'value'=>$value,'reason'=>$reason,'starts_on'=>$starts ?: null,'ends_on'=>$ends ?: null,'category_ids'=>$this->ids($data['category_ids'] ?? []),'charge_ids'=>$this->ids($data['charge_ids'] ?? [])];
    }

    private function candidates(array $valid, ?int $excludingDiscountId=null): array
    {
        $where=['fi.school_id=?','s.family_id=?','fi.status IN ("UNPAID","PARTIAL")']; $params=[$this->school(),$valid['family_id']];
        if ($valid['category_ids']) { $where[]='d.charge_category_id IN ('.implode(',',array_fill(0,count($valid['category_ids']),'?')).')'; array_push($params,...$valid['category_ids']); }
        if ($valid['charge_ids']) { $where[]='fi.id IN ('.implode(',',array_fill(0,count($valid['charge_ids']),'?')).')'; array_push($params,...$valid['charge_ids']); }
        if ($excludingDiscountId) { $where[]='NOT EXISTS (SELECT 1 FROM family_discount_impacts fdi INNER JOIN family_discounts fd ON fd.id=fdi.family_discount_id WHERE fdi.student_financial_item_id=fi.id AND fdi.school_id=fi.school_id AND fdi.status="APPLIED" AND fd.status="ACTIVE" AND fd.id<>?)'; $params[]=$excludingDiscountId; }
        else { $where[]='NOT EXISTS (SELECT 1 FROM family_discount_impacts fdi INNER JOIN family_discounts fd ON fd.id=fdi.family_discount_id WHERE fdi.student_financial_item_id=fi.id AND fdi.school_id=fi.school_id AND fdi.status="APPLIED" AND fd.status="ACTIVE")'; }
        $sql='SELECT fi.id,fi.student_id,fi.paid_amount,fi.total_amount,d.charge_category_id,d.original_amount,d.final_amount,d.discount_type,d.discount_value,s.first_name,s.last_name,c.label category_label FROM student_financial_items fi INNER JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id AND d.school_id=fi.school_id INNER JOIN students s ON s.id=fi.student_id AND s.school_id=fi.school_id LEFT JOIN charge_categories c ON c.id=d.charge_category_id AND c.school_id=fi.school_id WHERE '.implode(' AND ',$where).' ORDER BY s.last_name,s.first_name,fi.id';
        $q=Database::connect()->prepare($sql); $q->execute($params); return $q->fetchAll();
    }

    private function lines(array $valid, array $charges): array
    {
        $lines=[];
        foreach ($charges as $charge) {
            $base=$this->money($charge['final_amount']);
            $removed=$valid['type']==='PERCENTAGE' ? $this->money($base*$valid['value']/100) : $valid['value'];
            $paid=$this->money($charge['paid_amount']);
            if ($removed > $base || $this->money($base-$removed) < $paid) return ['error'=>'La remise ne peut pas rendre le montant dû inférieur aux paiements déjà enregistrés'];
            $lines[]=['charge_id'=>(int)$charge['id'],'student_id'=>(int)$charge['student_id'],'student_name'=>trim($charge['first_name'].' '.$charge['last_name']),'category_label'=>$charge['category_label'] ?? 'Sans catégorie','before_amount'=>$base,'paid_amount'=>$paid,'discount_amount'=>$removed,'final_amount'=>$this->money($base-$removed)];
        }
        return $lines;
    }

    public function preview(array $data): array
    {
        $valid=$this->validate($data); if(isset($valid['error']))return $valid;
        $charges=$this->candidates($valid); $lines=$this->lines($valid,$charges); if(isset($lines['error']))return $lines;
        return ['family'=>$this->family($valid['family_id']),'charges'=>$lines,'total_before'=>$this->money(array_sum(array_column($lines,'before_amount'))),'total_discount'=>$this->money(array_sum(array_column($lines,'discount_amount'))),'total_after'=>$this->money(array_sum(array_column($lines,'final_amount')))];
    }

    private function history(PDO $pdo,int $id,string $action,?array $before,?array $after,?string $reason=null): void
    { $pdo->prepare('INSERT INTO family_discount_history(family_discount_id,school_id,action,before_data,after_data,reason,changed_by)VALUES(?,?,?,?,?,?,?)')->execute([$id,$this->school(),$action,$before?json_encode($before):null,$after?json_encode($after):null,$reason,$this->user()]); }

    private function apply(PDO $pdo, int $discountId, array $lines): void
    {
        foreach($lines as $line) {
            $q=$pdo->prepare('SELECT fi.total_amount,fi.discount_amount,fi.paid_amount,d.final_amount FROM student_financial_items fi INNER JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id AND d.school_id=fi.school_id WHERE fi.id=? AND fi.school_id=? FOR UPDATE'); $q->execute([$line['charge_id'],$this->school()]); $before=$q->fetch();
            if(!$before) throw new \RuntimeException('Charge introuvable');
            if ($line['final_amount'] < $this->money($before['paid_amount'])) throw new \RuntimeException('Un paiement a été enregistré : la remise ne peut plus être appliquée à cette charge');
            $pdo->prepare('UPDATE student_financial_item_details SET final_amount=?,modified_by=? WHERE student_financial_item_id=? AND school_id=?')->execute([$line['final_amount'],$this->user(),$line['charge_id'],$this->school()]);
            $totalDiscount=$this->money((float)$before['discount_amount']+$line['discount_amount']);
            $pdo->prepare('UPDATE student_financial_items SET total_amount=?,discount_amount=?,status=CASE WHEN paid_amount>=? THEN "PAID" WHEN paid_amount>0 THEN "PARTIAL" ELSE "UNPAID" END WHERE id=? AND school_id=?')->execute([$line['final_amount'],$totalDiscount,$line['final_amount'],$line['charge_id'],$this->school()]);
            $pdo->prepare('INSERT INTO family_discount_impacts(family_discount_id,school_id,student_financial_item_id,student_id,previous_total_amount,previous_discount_amount,previous_final_amount,applied_discount_amount,final_amount,status,applied_at,reversed_at) VALUES(?,?,?,?,?,?,?,?,?,"APPLIED",NOW(),NULL) ON DUPLICATE KEY UPDATE school_id=VALUES(school_id),student_id=VALUES(student_id),previous_total_amount=VALUES(previous_total_amount),previous_discount_amount=VALUES(previous_discount_amount),previous_final_amount=VALUES(previous_final_amount),applied_discount_amount=VALUES(applied_discount_amount),final_amount=VALUES(final_amount),status="APPLIED",applied_at=NOW(),reversed_at=NULL')->execute([$discountId,$this->school(),$line['charge_id'],$line['student_id'],$before['total_amount'],$before['discount_amount'],$before['final_amount'],$line['discount_amount'],$line['final_amount']]);
            $this->history($pdo,$discountId,'IMPACT_APPLIED',['charge_id'=>$line['charge_id'],'final_amount'=>(float)$before['final_amount']],['charge_id'=>$line['charge_id'],'final_amount'=>$line['final_amount'],'discount_amount'=>$line['discount_amount']]);
        }
    }

    public function create(array $data): array
    {
        $valid=$this->validate($data); if(isset($valid['error']))return $valid; $charges=$this->candidates($valid); $lines=$this->lines($valid,$charges); if(isset($lines['error']))return $lines; if(!$lines)return['error'=>'Aucune charge éligible pour les enfants de cette famille'];
        $pdo=Database::connect(); $pdo->beginTransaction(); try {
            $pdo->prepare('INSERT INTO family_discounts(school_id,family_id,discount_type,discount_value,reason,starts_on,ends_on,created_by)VALUES(?,?,?,?,?,?,?,?)')->execute([$this->school(),$valid['family_id'],$valid['type'],$valid['value'],$valid['reason'],$valid['starts_on'],$valid['ends_on'],$this->user()]);
            $id=(int)$pdo->lastInsertId(); $this->apply($pdo,$id,$lines); $this->history($pdo,$id,'CREATED',null,['discount_type'=>$valid['type'],'discount_value'=>$valid['value'],'impact_count'=>count($lines)],$valid['reason']); $pdo->commit(); return ['id'=>$id,'impact_count'=>count($lines),'total_discount'=>$this->money(array_sum(array_column($lines,'discount_amount')))];
        } catch(\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); return ['error'=>$e->getMessage()]; }
    }

    private function restore(PDO $pdo, int $id, string $action): void
    {
        $q=$pdo->prepare('SELECT * FROM family_discount_impacts WHERE family_discount_id=? AND school_id=? AND status="APPLIED" FOR UPDATE'); $q->execute([$id,$this->school()]);
        foreach($q->fetchAll() as $impact) {
            $pdo->prepare('UPDATE student_financial_item_details SET final_amount=?,modified_by=? WHERE student_financial_item_id=? AND school_id=?')->execute([$impact['previous_final_amount'],$this->user(),$impact['student_financial_item_id'],$this->school()]);
            $pdo->prepare('UPDATE student_financial_items SET total_amount=?,discount_amount=?,status=CASE WHEN paid_amount>=? THEN "PAID" WHEN paid_amount>0 THEN "PARTIAL" ELSE "UNPAID" END WHERE id=? AND school_id=?')->execute([$impact['previous_total_amount'],$impact['previous_discount_amount'],$impact['previous_final_amount'],$impact['student_financial_item_id'],$this->school()]);
            $pdo->prepare('UPDATE family_discount_impacts SET status="REVERSED",reversed_at=NOW() WHERE id=?')->execute([$impact['id']]);
            $this->history($pdo,$id,$action,['charge_id'=>(int)$impact['student_financial_item_id'],'final_amount'=>(float)$impact['final_amount']],['charge_id'=>(int)$impact['student_financial_item_id'],'final_amount'=>(float)$impact['previous_final_amount']]);
        }
    }

    public function update(int $id,array $data): array
    {
        $old=$this->one($id); if(!$old)return['error'=>'Remise familiale introuvable','status'=>404]; if($old['status']!=='ACTIVE')return['error'=>'Cette remise n’est plus active','status'=>409];
        $valid=$this->validate($data,$old); if(isset($valid['error']))return$valid; if($valid['family_id']!==(int)$old['family_id'])return['error'=>'La famille d’une remise ne peut pas être modifiée'];
        $pdo=Database::connect(); $pdo->beginTransaction(); try {
            $previousImpactIds=$pdo->prepare('SELECT student_financial_item_id FROM family_discount_impacts WHERE family_discount_id=? AND school_id=? AND status="APPLIED"');
            $previousImpactIds->execute([$id,$this->school()]);
            $previousImpactIds=array_map('intval',$previousImpactIds->fetchAll(PDO::FETCH_COLUMN));
            // A parameter-only edit must keep precisely the same children/charges.
            if (!$valid['category_ids'] && !$valid['charge_ids']) $valid['charge_ids']=$previousImpactIds;
            $this->restore($pdo,$id,'IMPACT_REVERSED_FOR_UPDATE'); $charges=$this->candidates($valid,$id); $lines=$this->lines($valid,$charges); if(isset($lines['error']))throw new \RuntimeException($lines['error']); if(!$lines)throw new \RuntimeException('Aucune charge éligible pour cette mise à jour');
            $pdo->prepare('UPDATE family_discounts SET discount_type=?,discount_value=?,reason=?,starts_on=?,ends_on=? WHERE id=? AND school_id=?')->execute([$valid['type'],$valid['value'],$valid['reason'],$valid['starts_on'],$valid['ends_on'],$id,$this->school()]);
            $this->apply($pdo,$id,$lines); $this->history($pdo,$id,'UPDATED',['discount_type'=>$old['discount_type'],'discount_value'=>(float)$old['discount_value']],['discount_type'=>$valid['type'],'discount_value'=>$valid['value'],'impact_count'=>count($lines)],$valid['reason']); $pdo->commit(); return ['id'=>$id,'impact_count'=>count($lines)];
        } catch(\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); return ['error'=>$e->getMessage()]; }
    }

    public function cancel(int $id,array $data=[]): array
    {
        $old=$this->one($id); if(!$old)return['error'=>'Remise familiale introuvable','status'=>404]; if($old['status']!=='ACTIVE')return['error'=>'Cette remise n’est plus active','status'=>409];
        $reason=trim((string)($data['reason']??'')); $pdo=Database::connect(); $pdo->beginTransaction(); try { $this->restore($pdo,$id,'IMPACT_REVERSED'); $pdo->prepare('UPDATE family_discounts SET status="CANCELLED",cancellation_reason=?,cancelled_by=?,cancelled_at=NOW() WHERE id=? AND school_id=?')->execute([$reason?:null,$this->user(),$id,$this->school()]); $this->history($pdo,$id,'CANCELLED',['status'=>'ACTIVE'],['status'=>'CANCELLED'],$reason?:null); $pdo->commit(); return ['id'=>$id,'status'=>'CANCELLED']; } catch(\Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); return ['error'=>$e->getMessage()]; }
    }

    public function all(array $filters=[]): array
    { $sql='SELECT fd.*,f.family_name,f.family_reference,COUNT(fdi.id) impact_count,COALESCE(SUM(CASE WHEN fdi.status="APPLIED" THEN fdi.applied_discount_amount ELSE 0 END),0) total_discount FROM family_discounts fd INNER JOIN families f ON f.id=fd.family_id LEFT JOIN family_discount_impacts fdi ON fdi.family_discount_id=fd.id WHERE fd.school_id=?'; $params=[$this->school()]; if(($filters['family_id']??'')!==''){$sql.=' AND fd.family_id=?';$params[]=(int)$filters['family_id'];} $sql.=' GROUP BY fd.id ORDER BY fd.id DESC'; $q=Database::connect()->prepare($sql);$q->execute($params);return$q->fetchAll(); }
    public function one(int $id): array|false
    { $q=Database::connect()->prepare('SELECT fd.*,f.family_name,f.family_reference FROM family_discounts fd INNER JOIN families f ON f.id=fd.family_id WHERE fd.id=? AND fd.school_id=?');$q->execute([$id,$this->school()]);$row=$q->fetch();if(!$row)return false;$i=Database::connect()->prepare('SELECT fdi.*,s.first_name,s.last_name,fi.label,c.label category_label FROM family_discount_impacts fdi INNER JOIN students s ON s.id=fdi.student_id INNER JOIN student_financial_items fi ON fi.id=fdi.student_financial_item_id LEFT JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id LEFT JOIN charge_categories c ON c.id=d.charge_category_id WHERE fdi.family_discount_id=? ORDER BY fdi.id');$i->execute([$id]);$row['impacts']=$i->fetchAll();return$row; }
    public function historyFor(int $id): array { if(!$this->one($id))return[];$q=Database::connect()->prepare('SELECT h.*,u.first_name,u.last_name,u.email FROM family_discount_history h LEFT JOIN users u ON u.id=h.changed_by WHERE h.family_discount_id=? AND h.school_id=? ORDER BY h.id DESC');$q->execute([$id,$this->school()]);return$q->fetchAll(); }
}
