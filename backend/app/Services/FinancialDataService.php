<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;

/**
 * Read-only financial projection shared by future documents and summaries.
 *
 * There is no reliable FK between monthly_fees and student_financial_items.
 * Therefore both sources coexist as distinct obligations; this service never
 * attempts heuristic matching by amount, label, date, or period.
 */
class FinancialDataService
{
    public function getStudentFinancialItems(int $schoolId, int $studentId, ?int $academicYearId = null, bool $allAcademicYears = false): array
    {
        $pdo = Database::connect();
        $year = $allAcademicYears && $academicYearId === null
            ? null
            : $this->year($pdo, $schoolId, $academicYearId);
        $this->student($pdo, $schoolId, $studentId);
        $items = array_merge($this->flexibleItems($pdo, $schoolId, $studentId, $year), $this->legacyItems($pdo, $schoolId, $studentId, $year));
        foreach ($items as &$item) $item['student_id'] = $studentId;
        unset($item);
        usort($items, static fn(array $a, array $b): int => strcmp((string)$a['due_date'], (string)$b['due_date']) ?: strcmp($a['label'], $b['label']));
        return $items;
    }

    public function getStudentFinancialSummary(int $schoolId, int $studentId, ?int $academicYearId = null): array
    {
        $items = $this->getStudentFinancialItems($schoolId, $studentId, $academicYearId);
        return $this->summary($items);
    }

    public function getStudentUnpaidItems(int $schoolId, int $studentId, ?int $academicYearId = null): array
    {
        $items = $this->getStudentFinancialItems($schoolId, $studentId, $academicYearId);
        $items = array_values(array_filter(
            $items,
            static fn(array $item): bool => (float)$item['remaining_amount'] > 0
        ));
        return ['items' => $items, 'total_remaining' => round(array_sum(array_column($items, 'remaining_amount')), 2)];
    }

    /**
     * Overdue is intentionally narrower than unpaid: an item is late only
     * when its due date is explicitly stored and has passed. Monthly fees do
     * not have a canonical due-date column, so this projection never invents
     * one from their creation date or period label.
     */
    public function getStudentOverdueItems(int $schoolId, int $studentId, ?int $academicYearId = null): array
    {
        $today = date('Y-m-d');
        $items = array_values(array_filter(
            $this->getStudentFinancialItems($schoolId, $studentId, $academicYearId),
            static fn(array $item): bool => (float)$item['remaining_amount'] > 0
                && $item['due_date'] !== null
                && $item['due_date'] <= $today
        ));

        return ['items' => $items, 'total_remaining' => round(array_sum(array_column($items, 'remaining_amount')), 2)];
    }

    /**
     * Consolidated school-wide projection. With no academic year supplied it
     * intentionally includes all known years: callers must opt into an
     * academic-year filter rather than silently assuming a current year.
     */
    public function getSchoolFinancialSummary(int $schoolId, ?int $academicYearId = null): array
    {
        $pdo = Database::connect();
        $school = $pdo->prepare('SELECT id FROM schools WHERE id = ?');
        $school->execute([$schoolId]);
        if (!$school->fetch()) {
            throw new DomainException('School not found');
        }

        if ($academicYearId !== null) {
            $this->year($pdo, $schoolId, $academicYearId);
        }

        $students = $pdo->prepare('SELECT id,first_name,last_name FROM students WHERE school_id = ? ORDER BY id');
        $students->execute([$schoolId]);

        $items = [];
        $studentSummaries = [];
        foreach ($students->fetchAll() as $student) {
            $studentItems = $this->getStudentFinancialItems(
                $schoolId,
                (int) $student['id'],
                $academicYearId,
                $academicYearId === null
            );
            $studentSummary = $this->summary($studentItems);
            $studentSummaries[] = [
                'student_id' => (int) $student['id'],
                'student_name' => trim($student['first_name'] . ' ' . $student['last_name']),
                'total_remaining' => $studentSummary['total_remaining'],
                'overdue_remaining' => $studentSummary['overdue']['total_remaining'],
                'is_late' => $studentSummary['overdue']['total_remaining'] > 0,
            ];
            $items = array_merge($items, $studentItems);
        }

        $summary = $this->summary($items);
        $topUnpaid = array_values(array_filter(
            $studentSummaries,
            static fn(array $student): bool => (float) $student['total_remaining'] > 0
        ));
        usort($topUnpaid, static fn(array $left, array $right): int =>
            ($right['total_remaining'] <=> $left['total_remaining'])
            ?: strcmp($left['student_name'], $right['student_name'])
        );

        return [
            'academic_year_id' => $academicYearId,
            'summary' => $summary,
            'late_students' => count(array_filter($studentSummaries, static fn(array $student): bool => $student['is_late'])),
            'top_unpaid_students' => array_slice($topUnpaid, 0, 5),
        ];
    }

    /**
     * Consolidated, read-only school view for arrears screens and documents.
     * The legacy monthly endpoint remains separate for backwards compatibility.
     */
    public function getSchoolUnpaidItems(int $schoolId, array $filters = []): array
    {
        $pdo = Database::connect();
        $school = $pdo->prepare('SELECT id, name FROM schools WHERE id = ?');
        $school->execute([$schoolId]);
        $school = $school->fetch();
        if (!$school) {
            throw new DomainException('School not found');
        }

        $academicYearId = isset($filters['academic_year_id']) && (int) $filters['academic_year_id'] > 0
            ? (int) $filters['academic_year_id']
            : null;
        if ($academicYearId !== null) {
            $this->year($pdo, $schoolId, $academicYearId);
        }

        $query = trim((string) ($filters['search'] ?? ''));
        $classLevelId = isset($filters['class_level_id']) ? (int) $filters['class_level_id'] : 0;
        $classLevel = trim((string) ($filters['class_level'] ?? ''));
        $className = trim((string) ($filters['class_name'] ?? ''));
        $where = ['s.school_id = ?'];
        $params = [$schoolId];
        if ($query !== '') {
            $where[] = '(LOWER(s.first_name) LIKE ? OR LOWER(s.last_name) LIKE ? OR LOWER(CONCAT(s.first_name, " ", s.last_name)) LIKE ?)';
            $like = '%' . strtolower($query) . '%';
            array_push($params, $like, $like, $like);
        }
        if ($classLevelId > 0) {
            $class = $pdo->prepare('SELECT id FROM class_levels WHERE id = ? AND school_id = ? AND status = "ACTIVE"');
            $class->execute([$classLevelId, $schoolId]);
            if (!$class->fetch()) {
                throw new DomainException('Class not found for this school');
            }
            $where[] = 's.class_level_id = ?';
            $params[] = $classLevelId;
        }
        if ($classLevel !== '') {
            $where[] = 'LOWER(COALESCE(cl.level_name, cl.name, s.class_level)) LIKE ?';
            $params[] = '%' . strtolower($classLevel) . '%';
        }
        if ($className !== '') {
            $where[] = 'LOWER(COALESCE(cl.group_name, s.class_name, "")) LIKE ?';
            $params[] = '%' . strtolower($className) . '%';
        }

        $students = $pdo->prepare('SELECT s.id,s.first_name,s.last_name,COALESCE(cl.level_name,cl.name,s.class_level) class_level_name,COALESCE(cl.group_name,s.class_name) class_name,COALESCE(NULLIF(CONCAT_WS(" ",parent.first_name,parent.last_name),""),s.parent_name,"") responsible_name,COALESCE(parent.phone,s.phone,"") phone FROM students s LEFT JOIN class_levels cl ON cl.id=s.class_level_id AND cl.school_id=s.school_id LEFT JOIN parents parent ON parent.id=s.parent_id AND parent.school_id=s.school_id WHERE ' . implode(' AND ', $where) . ' ORDER BY s.last_name,s.first_name,s.id');
        $students->execute($params);

        $yearValue = isset($filters['year_value']) && (int) $filters['year_value'] > 0 ? (int) $filters['year_value'] : null;
        $month = isset($filters['month_label']) && preg_match('/^(0?[1-9]|1[0-2])$/', (string) $filters['month_label'])
            ? str_pad((string) (int) $filters['month_label'], 2, '0', STR_PAD_LEFT)
            : null;
        $years = $pdo->prepare('SELECT id,name FROM academic_years WHERE school_id = ?');
        $years->execute([$schoolId]);
        $yearNames = [];
        foreach ($years->fetchAll() as $year) {
            $yearNames[(int) $year['id']] = $year['name'];
        }

        $items = [];
        foreach ($students->fetchAll() as $student) {
            $studentItems = $this->getStudentFinancialItems($schoolId, (int) $student['id'], $academicYearId, $academicYearId === null);
            foreach ($studentItems as $item) {
                if ((float) $item['remaining_amount'] <= 0 || !$this->matchesPeriod($item, $month, $yearValue)) {
                    continue;
                }
                $isOverdue = $item['due_date'] !== null && $item['due_date'] <= date('Y-m-d');
                $items[] = array_merge($item, [
                    'id' => $item['source_type'] . '-' . $item['source_id'],
                    'school_id' => $schoolId,
                    'school_name' => $school['name'],
                    'student_name' => trim($student['first_name'] . ' ' . $student['last_name']),
                    'first_name' => $student['first_name'],
                    'last_name' => $student['last_name'],
                    'class_level_name' => $student['class_level_name'],
                    'class_name' => $student['class_name'],
                    'responsible_name' => $student['responsible_name'],
                    'phone' => $student['phone'],
                    'academic_year_name' => $item['academic_year_id'] !== null ? ($yearNames[(int) $item['academic_year_id']] ?? null) : null,
                    'fee_type' => $item['source_type'] === 'LEGACY_MONTHLY_FEE' ? 'MONTHLY' : 'FLEXIBLE',
                    'is_overdue' => $isOverdue,
                    'days_late' => $isOverdue ? (int) ((new \DateTimeImmutable($item['due_date']))->diff(new \DateTimeImmutable('today'))->format('%a')) : null,
                ]);
            }
        }

        usort($items, static fn(array $a, array $b): int => ($b['is_overdue'] <=> $a['is_overdue']) ?: strcmp((string) ($a['due_date'] ?? '9999-12-31'), (string) ($b['due_date'] ?? '9999-12-31')) ?: strcmp($a['student_name'], $b['student_name']) ?: strcmp($a['label'], $b['label']));
        $summary = $this->summary($items);
        unset($summary['items']);

        return [
            'school_id' => $schoolId,
            'school_name' => $school['name'],
            'academic_year_id' => $academicYearId,
            'filters' => ['class_level_id' => $classLevelId ?: null, 'month_label' => $month, 'year_value' => $yearValue],
            'items' => $items,
            'summary' => $summary,
        ];
    }

    public function getStudentPayments(int $schoolId, int $studentId, ?int $academicYearId = null): array
    {
        $pdo = Database::connect(); $year = $this->year($pdo, $schoolId, $academicYearId); $this->student($pdo, $schoolId, $studentId);
        $legacyYearSql = $year ? ' AND mf.year_value=?' : ''; $legacyParams = [$schoolId, $studentId]; if ($year) $legacyParams[] = $year['legacy_year'];
        $legacy = $pdo->prepare('SELECT p.id,p.amount_paid,p.payment_date,p.payment_method,p.status,mf.month_label,mf.year_value FROM payments p INNER JOIN monthly_fees mf ON mf.id=p.monthly_fee_id AND mf.school_id=p.school_id WHERE p.school_id=? AND p.student_id=?' . $legacyYearSql . ' ORDER BY p.payment_date,p.id');
        $legacy->execute($legacyParams); $result = [];
        foreach ($legacy->fetchAll() as $row) $result[] = ['payment_id'=>(int)$row['id'],'date'=>$row['payment_date'],'method'=>$row['payment_method'],'amount'=>(float)$row['amount_paid'],'status'=>$row['status'] ?: 'COMPLETED','source'=>'LEGACY','period'=>str_pad((string)$row['month_label'],2,'0',STR_PAD_LEFT).'/'.$row['year_value'],'allocations'=>[]];
        $flexSql = 'SELECT DISTINCT p.id,p.amount_paid,p.payment_date,p.payment_method,p.status FROM payments p INNER JOIN payment_allocations pa ON pa.payment_id=p.id AND pa.school_id=p.school_id INNER JOIN student_financial_items fi ON fi.id=pa.student_financial_item_id AND fi.school_id=pa.school_id WHERE p.school_id=? AND p.student_id=?';
        $flexParams = [$schoolId,$studentId]; if ($year) {$flexSql .= ' AND fi.academic_year_id=?'; $flexParams[]=$year['id'];} $flexSql .= ' ORDER BY p.payment_date,p.id';
        $flex = $pdo->prepare($flexSql); $flex->execute($flexParams);
        $alloc = $pdo->prepare('SELECT pa.student_financial_item_id,pa.amount,COALESCE(cc.code,"FLEXIBLE") category_code,COALESCE(cc.label,fi.label) category_name,fi.label FROM payment_allocations pa INNER JOIN student_financial_items fi ON fi.id=pa.student_financial_item_id AND fi.school_id=pa.school_id LEFT JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id AND d.school_id=fi.school_id LEFT JOIN charge_categories cc ON cc.id=d.charge_category_id AND cc.school_id=fi.school_id WHERE pa.payment_id=? AND pa.school_id=? ORDER BY pa.id');
        foreach ($flex->fetchAll() as $row) { $alloc->execute([(int)$row['id'],$schoolId]); $result[]=['payment_id'=>(int)$row['id'],'date'=>$row['payment_date'],'method'=>$row['payment_method'],'amount'=>(float)$row['amount_paid'],'status'=>$row['status'] ?: 'COMPLETED','source'=>'FLEXIBLE','period'=>null,'allocations'=>array_map(static fn(array $a)=>['student_financial_item_id'=>(int)$a['student_financial_item_id'],'category'=>$a['category_name'],'category_code'=>$a['category_code'],'label'=>$a['label'],'amount'=>(float)$a['amount']],$alloc->fetchAll())]; }
        usort($result, static fn(array $a,array $b)=>strcmp($a['date'],$b['date']) ?: $a['payment_id']<=>$b['payment_id']); return $result;
    }

    public function getFamilyFinancialSummary(int $schoolId, int $familyId, ?int $academicYearId = null): array
    {
        $pdo=Database::connect(); $year=$this->year($pdo,$schoolId,$academicYearId); $check=$pdo->prepare('SELECT id FROM families WHERE id=? AND school_id=?');$check->execute([$familyId,$schoolId]);if(!$check->fetch())throw new DomainException('Family not found for this school');
        $students=$pdo->prepare('SELECT s.id,s.first_name,s.last_name,COALESCE(cl.level_name,cl.name,s.class_level) class_level_name,s.class_name FROM students s LEFT JOIN class_levels cl ON cl.id=s.class_level_id AND cl.school_id=s.school_id WHERE s.family_id=? AND s.school_id=? ORDER BY s.last_name,s.first_name');$students->execute([$familyId,$schoolId]);$children=[];$all=[];
        foreach($students->fetchAll() as $student){$summary=$this->getStudentFinancialSummary($schoolId,(int)$student['id'],$year['id'] ?? null);$children[]=['student_id'=>(int)$student['id'],'name'=>trim($student['first_name'].' '.$student['last_name']),'class'=>trim(($student['class_level_name']??'').' '.($student['class_name']??'')),'summary'=>$summary];$all=array_merge($all,$summary['items']);}
        return ['academic_year_id'=>$year['id'] ?? null,'children'=>$children,'summary'=>$this->summary($all)];
    }

    private function flexibleItems(PDO $pdo,int $schoolId,int $studentId,?array $year):array { $sql='SELECT fi.id,fi.student_id,fi.academic_year_id,fi.label,fi.status,d.charge_category_id,d.billing_month,d.year_value,d.due_date,d.original_amount,d.discount_type,d.discount_value,d.final_amount,cc.code,cc.label category_name,COALESCE(SUM(CASE WHEN p.status="COMPLETED" THEN pa.amount ELSE 0 END),0) paid FROM student_financial_items fi INNER JOIN student_financial_item_details d ON d.student_financial_item_id=fi.id AND d.school_id=fi.school_id LEFT JOIN charge_categories cc ON cc.id=d.charge_category_id AND cc.school_id=fi.school_id LEFT JOIN payment_allocations pa ON pa.student_financial_item_id=fi.id AND pa.school_id=fi.school_id LEFT JOIN payments p ON p.id=pa.payment_id AND p.school_id=pa.school_id WHERE fi.school_id=? AND fi.student_id=? AND fi.status != "EXEMPT"';$params=[$schoolId,$studentId];if($year){$sql.=' AND fi.academic_year_id=?';$params[]=$year['id'];}$sql.=' GROUP BY fi.id,fi.student_id,d.charge_category_id,d.billing_month,d.year_value,d.due_date,d.original_amount,d.discount_type,d.discount_value,d.final_amount,cc.code,cc.label';$q=$pdo->prepare($sql);$q->execute($params);return array_map(static function(array$r):array{$final=round((float)$r['final_amount'],2);$paid=round((float)$r['paid'],2);return ['source_type'=>'FLEXIBLE_ITEM','source_id'=>(int)$r['id'],'student_id'=>(int)$r['student_id'],'academic_year_id'=>$r['academic_year_id']?(int)$r['academic_year_id']:null,'category_code'=>$r['code']??'FLEXIBLE','category_name'=>$r['category_name']??$r['label'],'label'=>$r['label'],'period'=>null,'billing_month'=>$r['billing_month']===null?null:(int)$r['billing_month'],'year_value'=>$r['year_value']===null?null:(int)$r['year_value'],'due_date'=>$r['due_date'],'initial_amount'=>(float)$r['original_amount'],'discount_type'=>$r['discount_type'],'discount_value'=>$r['discount_value']===null?null:(float)$r['discount_value'],'discount_amount'=>round((float)$r['original_amount']-$final,2),'final_amount'=>$final,'paid_amount'=>$paid,'remaining_amount'=>max(0,round($final-$paid,2)),'overpaid_amount'=>max(0,round($paid-$final,2)),'status'=>$paid<=0?'UNPAID':($paid>=$final?'PAID':'PARTIAL')];},$q->fetchAll()); }

    private function legacyItems(PDO $pdo,int $schoolId,int $studentId,?array $year):array { $sql='SELECT mf.id,mf.month_label,mf.year_value,mf.total_amount,mf.amount_paid,COUNT(p.id) payment_count,COALESCE(SUM(CASE WHEN p.status="COMPLETED" THEN p.amount_paid ELSE 0 END),0) completed_paid FROM monthly_fees mf LEFT JOIN payments p ON p.monthly_fee_id=mf.id AND p.school_id=mf.school_id WHERE mf.school_id=? AND mf.student_id=?';$params=[$schoolId,$studentId];if($year){$sql.=' AND mf.year_value=?';$params[]=$year['legacy_year'];}$sql.=' GROUP BY mf.id,mf.month_label,mf.year_value,mf.total_amount,mf.amount_paid';$q=$pdo->prepare($sql);$q->execute($params);return array_map(static function(array$r) use ($studentId,$year):array{$final=round((float)$r['total_amount'],2);$paid=round((float)$r['payment_count']>0?(float)$r['completed_paid']:(float)$r['amount_paid'],2);$remaining=max(0,round($final-$paid,2));return ['source_type'=>'LEGACY_MONTHLY_FEE','source_id'=>(int)$r['id'],'student_id'=>$studentId,'academic_year_id'=>$year['id']??null,'category_code'=>'TUITION','category_name'=>'Mensualité','label'=>'Mensualité '.str_pad((string)$r['month_label'],2,'0',STR_PAD_LEFT).'/'.$r['year_value'],'period'=>str_pad((string)$r['month_label'],2,'0',STR_PAD_LEFT).'/'.$r['year_value'],'billing_month'=>ctype_digit((string)$r['month_label'])?(int)$r['month_label']:null,'year_value'=>(int)$r['year_value'],'due_date'=>null,'initial_amount'=>$final,'discount_type'=>null,'discount_value'=>null,'discount_amount'=>0.0,'final_amount'=>$final,'paid_amount'=>$paid,'remaining_amount'=>$remaining,'overpaid_amount'=>max(0,round($paid-$final,2)),'status'=>$paid<=0?'UNPAID':($paid>=$final?'PAID':'PARTIAL')];},$q->fetchAll()); }

    private function summary(array $items):array { $sources=['monthly'=>[],'flexible'=>[]];foreach($items as $item){$sources[$item['source_type']==='LEGACY_MONTHLY_FEE'?'monthly':'flexible'][]=$item;}$sum=$this->sumItems($items);$sum['total_due']=$sum['total_final'];$sum['monthly']=$this->sumItems($sources['monthly']);$sum['flexible']=$this->sumItems($sources['flexible']);$today=date('Y-m-d');$overdue=array_values(array_filter($items,static fn(array $item):bool=>(float)$item['remaining_amount']>0&&$item['due_date']!==null&&$item['due_date']<=$today));$sum['overdue']=['count'=>count($overdue),'total_remaining'=>round(array_sum(array_column($overdue,'remaining_amount')),2)];$sum['items']=$items;return $sum; }

    private function sumItems(array $items): array
    {
        $sum=['total_initial'=>0.0,'total_discount'=>0.0,'total_final'=>0.0,'total_paid'=>0.0,'total_remaining'=>0.0,'total_overpaid'=>0.0,'unpaid_count'=>0,'partial_count'=>0,'paid_count'=>0];
        foreach($items as $item){$sum['total_initial']+=(float)$item['initial_amount'];$sum['total_discount']+=(float)$item['discount_amount'];$sum['total_final']+=(float)$item['final_amount'];$sum['total_paid']+=(float)$item['paid_amount'];$sum['total_remaining']+=(float)$item['remaining_amount'];$sum['total_overpaid']+=(float)($item['overpaid_amount']??0);$status=$item['status'];if($status==='UNPAID')$sum['unpaid_count']++;elseif($status==='PARTIAL')$sum['partial_count']++;elseif($status==='PAID')$sum['paid_count']++;}
        foreach(['total_initial','total_discount','total_final','total_paid','total_remaining','total_overpaid'] as $key)$sum[$key]=round($sum[$key],2);
        $sum['total_due']=$sum['total_final'];
        return $sum;
    }
    private function matchesPeriod(array $item, ?string $month, ?int $yearValue): bool
    {
        if ($month !== null && str_pad((string) ($item['billing_month'] ?? ''), 2, '0', STR_PAD_LEFT) !== $month) return false;
        return $yearValue === null || (int) ($item['year_value'] ?? 0) === $yearValue;
    }
    private function student(PDO $pdo,int $schoolId,int $studentId):void{$q=$pdo->prepare('SELECT id FROM students WHERE id=? AND school_id=?');$q->execute([$studentId,$schoolId]);if(!$q->fetch())throw new DomainException('Student not found for this school');}
    private function year(PDO $pdo,int $schoolId,?int $id):?array {if(!$id){$q=$pdo->prepare('SELECT ay.id,ay.name FROM school_settings s INNER JOIN academic_years ay ON ay.id=CAST(s.setting_value AS UNSIGNED) AND ay.school_id=s.school_id WHERE s.school_id=? AND s.setting_key="active_academic_year_id"');$q->execute([$schoolId]);$r=$q->fetch();if(!$r)return null;}else{$q=$pdo->prepare('SELECT id,name FROM academic_years WHERE id=? AND school_id=?');$q->execute([$id,$schoolId]);$r=$q->fetch();if(!$r)throw new DomainException('Academic year not found for this school');}if(!preg_match('/^(\d{4})[-\/]/',(string)$r['name'],$m))throw new DomainException('Academic year cannot be mapped safely to legacy monthly fees');return ['id'=>(int)$r['id'],'name'=>$r['name'],'legacy_year'=>(int)$m[1]];}
}
