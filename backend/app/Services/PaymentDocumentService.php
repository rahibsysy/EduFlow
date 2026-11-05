<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;

class PaymentDocumentService
{
    private const MONTHS = ['01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril', '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août', '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre'];

    public function monthly(array $filters): array
    {
        [$schoolId, $currency] = $this->scope();
        $period = $this->period($filters, true);
        if (isset($period['error'])) return $period;
        $stmt = Database::connect()->prepare('SELECT p.id,p.amount_paid,p.payment_date,p.payment_method,COALESCE(pm.label,p.payment_method) payment_method_label,s.first_name,s.last_name,COALESCE(cl.level_name,cl.name,s.class_level) class_level_name,COALESCE(cl.group_name,s.class_name) class_name,COALESCE(NULLIF(CONCAT_WS(" ",parent.first_name,parent.last_name),""),s.parent_name,"") responsible_name,mf.total_amount FROM payments p INNER JOIN students s ON s.id=p.student_id AND s.school_id=p.school_id INNER JOIN monthly_fees mf ON mf.id=p.monthly_fee_id AND mf.school_id=p.school_id LEFT JOIN class_levels cl ON cl.id=s.class_level_id AND cl.school_id=s.school_id LEFT JOIN parents parent ON parent.id=s.parent_id AND parent.school_id=s.school_id LEFT JOIN payment_methods pm ON pm.id=p.payment_method_id WHERE p.school_id=? AND p.status="COMPLETED" AND mf.month_label=? AND mf.year_value=? ORDER BY class_level_name,class_name,s.last_name,s.first_name,p.payment_date,p.id');
        $stmt->execute([$schoolId, $period['month'], $period['year']]);
        $rows = $stmt->fetchAll();
        if (!$rows) return ['error' => 'Aucun paiement ne correspond au mois sélectionné.', 'status' => 422];
        $total = array_sum(array_map(static fn(array $row): float => (float)$row['amount_paid'], $rows));
        return $this->pdf('Paiements du mois', $this->periodLabel($period) . '<br>Généré le ' . date('d/m/Y à H:i'), $this->paymentTable($rows, $currency), 'paiements-' . $period['month'] . '-' . $period['year'] . '.pdf', $schoolId);
    }

    public function student(int $studentId, array $filters): array
    {
        [$schoolId, $currency] = $this->scope();
        $student = $this->studentRow($studentId, $schoolId);
        if (!$student) return ['error' => 'Élève introuvable.', 'status' => 404];
        $period = $this->period($filters, false);
        if (isset($period['error'])) return $period;
        [$where, $params] = $this->feeFilters($studentId, $schoolId, $period);
        $stmt = Database::connect()->prepare('SELECT mf.*,COALESCE((SELECT SUM(p.amount_paid) FROM payments p WHERE p.monthly_fee_id=mf.id AND p.school_id=mf.school_id AND p.status="COMPLETED"),0) payment_sum FROM monthly_fees mf ' . $where . ' ORDER BY mf.year_value,LPAD(mf.month_label,2,"0")');
        $stmt->execute($params);
        $fees = $stmt->fetchAll();
        if (!$fees) return ['error' => 'Aucune donnée financière ne correspond à la période sélectionnée.', 'status' => 422];
        $totals = $this->totals($fees);
        $identity = '<p><strong>Élève :</strong> ' . $this->e($student['first_name'] . ' ' . $student['last_name']) . '<br><strong>Niveau / classe :</strong> ' . $this->e(trim(($student['class_level_name'] ?? '') . ' ' . ($student['class_name'] ?? ''))) . '<br><strong>Responsable :</strong> ' . $this->e((string)($student['responsible_name'] ?? 'Non renseigné')) . '</p>';
        return $this->pdf('Historique des paiements', $identity . $this->periodText($period), $this->feeTable($fees, $currency) . $this->totalBox($totals, $currency), 'paiements-eleve-' . $this->slug($student['last_name'] . '-' . $student['first_name']) . '-' . ($period['year'] ?? 'toute-periode') . '.pdf', $schoolId);
    }

    public function family(int $familyId, array $filters): array
    {
        [$schoolId, $currency] = $this->scope();
        $familyStmt = Database::connect()->prepare('SELECT f.family_name,f.family_reference,g.full_name guardian_name,g.phone_primary FROM families f LEFT JOIN guardians g ON g.id=f.primary_guardian_id AND g.school_id=f.school_id WHERE f.id=? AND f.school_id=?');
        $familyStmt->execute([$familyId, $schoolId]); $family = $familyStmt->fetch();
        if (!$family) return ['error' => 'Famille introuvable.', 'status' => 404];
        $period = $this->period($filters, false); if (isset($period['error'])) return $period;
        $members = Database::connect()->prepare('SELECT id,first_name,last_name,COALESCE(class_name, class_level, "") class_name FROM students WHERE family_id=? AND school_id=? ORDER BY last_name,first_name');
        $members->execute([$familyId, $schoolId]); $members = $members->fetchAll();
        if (!$members) return ['error' => 'Cette famille ne comporte aucun élève rattaché.', 'status' => 422];
        $content = '<p><strong>Famille :</strong> ' . $this->e($family['family_name']) . ' · ' . $this->e($family['family_reference']) . '<br><strong>Responsable :</strong> ' . $this->e((string)($family['guardian_name'] ?? 'Non renseigné')) . '</p>' . $this->periodText($period);
        $grand = ['total' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];
        foreach ($members as $member) {
            [$where, $params] = $this->feeFilters((int)$member['id'], $schoolId, $period);
            $fees = Database::connect()->prepare('SELECT mf.* FROM monthly_fees mf ' . $where . ' ORDER BY mf.year_value,LPAD(mf.month_label,2,"0")'); $fees->execute($params); $fees = $fees->fetchAll();
            $totals = $this->totals($fees); foreach ($grand as $key => $value) $grand[$key] += $totals[$key];
            $content .= '<section class="student-section"><h3>' . $this->e($member['first_name'] . ' ' . $member['last_name']) . ' — ' . $this->e($member['class_name']) . '</h3>' . ($fees ? $this->feeTable($fees, $currency) . $this->totalBox($totals, $currency) : '<p>Aucune mensualité pour cette période.</p>') . '</section>';
        }
        return $this->pdf('Récapitulatif familial', '', $content . '<h3>Total général familial</h3>' . $this->totalBox($grand, $currency), 'recapitulatif-famille-' . $this->slug($family['family_name']) . '-' . ($period['month'] ?? 'periode') . '-' . ($period['year'] ?? '') . '.pdf', $schoolId);
    }

    public function unpaid(array $filters): array
    {
        [$schoolId, $currency] = $this->scope();
        if ($schoolId <= 0) return ['error' => 'Un contexte école est requis.', 'status' => 403];
        try {
            $projection = (new FinancialDataService())->getSchoolUnpaidItems($schoolId, $filters);
        } catch (\DomainException $exception) {
            return ['error' => $exception->getMessage(), 'status' => 422];
        }
        if (!$projection['items']) return ['error' => 'Aucun impayé ne correspond aux filtres sélectionnés.', 'status' => 422];
        $summary = $projection['summary'];
        $meta = 'Obligations mensuelles et frais flexibles · Généré le ' . date('d/m/Y à H:i');
        if ($projection['filters']['month_label'] !== null || $projection['filters']['year_value'] !== null) {
            $meta .= '<br>Filtre période : ' . $this->e(($projection['filters']['month_label'] ?? 'tous mois') . ' / ' . ($projection['filters']['year_value'] ?? 'toutes années'));
        }
        $body = $this->unpaidProjectionTable($projection['items'], $currency)
            . '<p class="total">Total restant à encaisser : ' . $this->money($summary['total_remaining'], $currency)
            . ' · Dont en retard prouvé : ' . $this->money($summary['overdue']['total_remaining'], $currency) . '</p>';
        return $this->pdf('Impayés consolidés', $meta, $body, 'impayes-consolides.pdf', $schoolId);
    }

    private function scope(): array { $user = Request::get('auth_user', []); $schoolId=(int)($user['school_id'] ?? 0); if (($user['role'] ?? '') === 'super_admin') $schoolId=(int)($_GET['school_id'] ?? 0); return [$schoolId, $this->currency($schoolId)]; }
    private function currency(int $schoolId): string { $stmt = Database::connect()->prepare('SELECT currency FROM schools WHERE id=?'); $stmt->execute([$schoolId]); return strtoupper((string)($stmt->fetchColumn() ?: 'MAD')); }
    private function period(array $filters, bool $required): array { $month = str_pad(trim((string)($filters['month_label'] ?? '')), 2, '0', STR_PAD_LEFT); $year = (int)($filters['year_value'] ?? 0); if ($required && (!isset(self::MONTHS[$month]) || $year < 2000 || $year > 2100)) return ['error' => 'Sélectionnez un mois et une année valides.', 'status' => 422]; if (!$required && (($month !== '00' && !isset(self::MONTHS[$month])) || ($year && ($year < 2000 || $year > 2100)))) return ['error' => 'Période invalide.', 'status' => 422]; return ['month' => isset(self::MONTHS[$month]) ? $month : null, 'year' => $year ?: null]; }
    private function periodLabel(array $period): string { return self::MONTHS[$period['month']] . ' ' . $period['year']; }
    private function periodText(array $period): string { return '<p><strong>Période :</strong> ' . ($period['month'] ? $this->periodLabel($period) : ($period['year'] ? 'Année ' . $period['year'] : 'Toute la période disponible')) . '</p>'; }
    private function studentRow(int $studentId, int $schoolId): array|false { $stmt=Database::connect()->prepare('SELECT s.*,COALESCE(cl.level_name,cl.name,s.class_level) class_level_name,COALESCE(NULLIF(CONCAT_WS(" ",parent.first_name,parent.last_name),""),s.parent_name,"") responsible_name FROM students s LEFT JOIN class_levels cl ON cl.id=s.class_level_id AND cl.school_id=s.school_id LEFT JOIN parents parent ON parent.id=s.parent_id AND parent.school_id=s.school_id WHERE s.id=? AND s.school_id=?'); $stmt->execute([$studentId,$schoolId]); return $stmt->fetch(); }
    private function feeFilters(int $studentId, int $schoolId, array $period): array { $where='WHERE mf.student_id=? AND mf.school_id=?'; $params=[$studentId,$schoolId]; if($period['month']){$where.=' AND mf.month_label=?';$params[]=$period['month'];} if($period['year']){$where.=' AND mf.year_value=?';$params[]=$period['year'];} return [$where,$params]; }
    private function totals(array $fees): array { $totals=['total'=>0.0,'paid'=>0.0,'remaining'=>0.0]; foreach($fees as $fee){$totals['total']+=(float)$fee['total_amount'];$totals['paid']+=(float)$fee['amount_paid'];$totals['remaining']+=(float)$fee['remaining_amount'];} return $totals; }
    private function paymentTable(array $rows, string $currency): string { $html='<table><thead><tr><th>Référence</th><th>Élève</th><th>Niveau / classe</th><th>Responsable</th><th>Attendu</th><th>Payé</th><th>Date</th><th>Mode</th></tr></thead><tbody>'; foreach($rows as $row)$html.='<tr><td>PAY-'.str_pad((string)$row['id'],6,'0',STR_PAD_LEFT).'</td><td>'.$this->e($row['last_name'].' '.$row['first_name']).'</td><td>'.$this->e(trim($row['class_level_name'].' '.$row['class_name'])).'</td><td>'.$this->e($row['responsible_name']).'</td><td>'.$this->money($row['total_amount'],$currency).'</td><td>'.$this->money($row['amount_paid'],$currency).'</td><td>'.$this->e($row['payment_date']).'</td><td>'.$this->e($row['payment_method_label']).'</td></tr>'; return $html.'</tbody></table><p class="total">Total encaissé : '.$this->money(array_sum(array_map(static fn(array $row):float=>(float)$row['amount_paid'],$rows)),$currency).'</p>'; }
    private function feeTable(array $fees, string $currency): string { $html='<table><thead><tr><th>Mois</th><th>Attendu</th><th>Payé</th><th>Reste</th><th>Statut</th></tr></thead><tbody>'; foreach($fees as $fee)$html.='<tr><td>'.$this->e((self::MONTHS[str_pad((string)$fee['month_label'],2,'0',STR_PAD_LEFT)]??$fee['month_label']).' '.$fee['year_value']).'</td><td>'.$this->money($fee['total_amount'],$currency).'</td><td>'.$this->money($fee['amount_paid'],$currency).'</td><td>'.$this->money($fee['remaining_amount'],$currency).'</td><td>'.$this->e(['PAID'=>'Payée','PARTIAL'=>'Partielle','UNPAID'=>'Impayée'][$fee['status']]??$fee['status']).'</td></tr>'; return $html.'</tbody></table>'; }
    private function totalBox(array $totals,string $currency): string{return '<p class="total">Total attendu : '.$this->money($totals['total'],$currency).' · Total payé : '.$this->money($totals['paid'],$currency).' · Reste à payer : '.$this->money($totals['remaining'],$currency).'</p>';}
    private function unpaidProjectionTable(array $items, string $currency): string
    {
        $html = '<table><thead><tr><th>Élève</th><th>Type</th><th>Libellé</th><th>Initial</th><th>Remise</th><th>Dû</th><th>Payé</th><th>Reste</th><th>Échéance</th><th>Statut</th><th>Retard</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $type = $item['fee_type'] === 'MONTHLY' ? 'Mensualité' : 'Frais flexible';
            $late = $item['is_overdue'] ? ((int) $item['days_late'] . ' jour(s)') : '—';
            $html .= '<tr><td>' . $this->e($item['student_name']) . '</td><td>' . $this->e($type) . '</td><td>' . $this->e($item['label']) . '</td><td>' . $this->money($item['initial_amount'], $currency) . '</td><td>' . $this->money($item['discount_amount'], $currency) . '</td><td>' . $this->money($item['final_amount'], $currency) . '</td><td>' . $this->money($item['paid_amount'], $currency) . '</td><td>' . $this->money($item['remaining_amount'], $currency) . '</td><td>' . $this->e($item['due_date'] ?? '—') . '</td><td>' . $this->e($item['status']) . '</td><td>' . $this->e($late) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }
    private function pdf(string $title, string $meta, string $body, string $filename, int $schoolId): array
    {
        $css = '@page{margin:9mm 10mm}body{font-family:DejaVu Sans;font-size:8.5px;color:#111;background:#fff}.school-document-header{width:100%;border-collapse:collapse;border-bottom:0.5px solid #555;margin:0 0 5px}.school-document-header td{border:0;padding:0 0 3px;vertical-align:top}.school-document-header .school-logo{width:39px}.school-document-header strong{font-size:11px}.school-document-header span{font-size:7.5px;color:#333}h1{text-align:center;font-size:12px;letter-spacing:.2px;margin:4px 0 2px}h3{font-size:9.5px;margin:7px 0 3px}.meta,p{line-height:1.25;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin:4px 0 5px}thead{display:table-header-group}th,td{border:0.4px solid #777;padding:2.5px 3px;text-align:left;vertical-align:top}th{font-weight:bold;background:#fff}.total{font-weight:bold;text-align:right;margin:3px 0;padding:2px 0;border-top:0.5px solid #555}.student-section{page-break-inside:avoid}.student-section + .student-section{border-top:0.4px solid #999;margin-top:5px;padding-top:3px}';
        $html = '<!doctype html><html lang="fr"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>' . (new SchoolDocumentHeaderService())->render($schoolId) . '<h1>' . $this->e($title) . '</h1><div class="meta">' . $meta . '</div>' . $body . '<table style="margin-top:9px;border:0"><tr><td style="border:0;width:50%">Signature direction</td><td style="border:0">Cachet établissement</td></tr></table></body></html>';
        $options = new Options(); $options->set('defaultFont', 'DejaVu Sans'); $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options); $pdf->loadHtml($html); $pdf->setPaper('A4', 'portrait'); $pdf->render();
        return ['content' => $pdf->output(), 'filename' => $filename];
    }
    private function money(float|string $value,string $currency):string{return number_format((float)$value,2,',',' ').' '.$this->e($currency);}
    private function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
    private function slug(string $value):string{$value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value)?:$value;return strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/','-',$value),'-'))?:'document';}
}
