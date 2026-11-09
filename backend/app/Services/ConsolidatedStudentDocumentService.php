<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use Dompdf\Dompdf;
use Dompdf\Options;

final class ConsolidatedStudentDocumentService
{
    public function generate(int $studentId): array
    {
        $user = Request::get('auth_user', []);
        $schoolId = (int)($user['school_id'] ?? 0);
        if ($schoolId <= 0) return ['error' => 'School is required'];
        $student = Database::connect()->prepare('SELECT id,internal_number,massar_code,first_name,last_name,class_name FROM students WHERE id=? AND school_id=?');
        $student->execute([$studentId, $schoolId]);
        $student = $student->fetch();
        if (!$student) return ['error' => 'Invalid student selection'];
        try { $summary = (new FinancialDataService())->getStudentFinancialSummary($schoolId, $studentId); }
        catch (\DomainException $exception) { return ['error' => $exception->getMessage()]; }
        $currency = $this->currency($schoolId);
        $rows = '';
        foreach ($summary['items'] as $item) {
            $type = $item['source_type'] === 'LEGACY_MONTHLY_FEE' ? 'Mensualité' : 'Frais flexible';
            $rows .= '<tr><td>'.$this->e($type).'</td><td>'.$this->e($item['label']).'</td><td>'.$this->money($item['initial_amount'], $currency).'</td><td>'.$this->money($item['discount_amount'], $currency).'</td><td>'.$this->money($item['final_amount'], $currency).'</td><td>'.$this->money($item['paid_amount'], $currency).'</td><td>'.$this->money($item['remaining_amount'], $currency).'</td></tr>';
        }
        $body = '<p><strong>Élève :</strong> '.$this->e($student['first_name'].' '.$student['last_name']).'<br><strong>Classe :</strong> '.$this->e($student['class_name'] ?? '').'</p><table><thead><tr><th>Type</th><th>Libellé</th><th>Initial</th><th>Remises</th><th>Exigible</th><th>Payé</th><th>Reste</th></tr></thead><tbody>'.$rows.'</tbody><tfoot><tr><th colspan="2">Total</th><th>'.$this->money($summary['total_initial'], $currency).'</th><th>'.$this->money($summary['total_discount'], $currency).'</th><th>'.$this->money($summary['total_final'], $currency).'</th><th>'.$this->money($summary['total_paid'], $currency).'</th><th>'.$this->money($summary['total_remaining'], $currency).'</th></tr></tfoot></table>';
        return $this->pdf($schoolId, $body, 'situation-financiere-'.$studentId.'.pdf');
    }

    private function pdf(int $schoolId, string $body, string $filename): array
    {
        $css='@page{margin:9mm 10mm}body{font-family:DejaVu Sans;font-size:8.5px;color:#111}table{width:100%;border-collapse:collapse;margin:4px 0}th,td{border:.4px solid #777;padding:2.5px 3px;text-align:left}th{background:#fff}.school-document-header{width:100%;border-collapse:collapse;border-bottom:.5px solid #555;margin-bottom:5px}.school-document-header td{border:0;padding:0 0 3px}.school-document-header .school-logo{width:39px}.school-document-header strong{font-size:11px}.school-document-header span{font-size:7.5px}h2{text-align:center;font-size:12px;margin:4px 0 2px}tfoot{font-weight:bold}';
        $html='<!doctype html><html><head><meta charset="UTF-8"><style>'.$css.'</style></head><body>'.(new SchoolDocumentHeaderService())->render($schoolId).'<h2>Situation financière consolidée</h2><p>Généré le '.date('d/m/Y H:i').'</p>'.$body.'</body></html>';
        $options=new Options();$options->set('defaultFont','DejaVu Sans');$options->set('isRemoteEnabled',false);$pdf=new Dompdf($options);$pdf->loadHtml($html);$pdf->setPaper('A4','portrait');$pdf->render();
        return ['content'=>$pdf->output(),'filename'=>$filename];
    }
    private function currency(int $schoolId): string { $q=Database::connect()->prepare('SELECT currency FROM schools WHERE id=?');$q->execute([$schoolId]);return strtoupper((string)($q->fetchColumn() ?: 'MAD')); }
    private function money(float|string $value,string $currency):string{return number_format((float)$value,2,',',' ').' '.$this->e($currency);}
    private function e(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
}
