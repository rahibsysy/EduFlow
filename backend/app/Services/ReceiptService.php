<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReceiptService
{
    private const MONTH_LABELS = [
        '01' => 'Janvier',
        '02' => 'Fevrier',
        '03' => 'Mars',
        '04' => 'Avril',
        '05' => 'Mai',
        '06' => 'Juin',
        '07' => 'Juillet',
        '08' => 'Aout',
        '09' => 'Septembre',
        '10' => 'Octobre',
        '11' => 'Novembre',
        '12' => 'Decembre',
    ];

    public function generateData(int $paymentId): array
    {
        $payment = $this->findPayment($paymentId);
        if (!$payment) {
            return ['error' => 'Payment not found'];
        }

        return $this->formatPaymentData($payment);
    }

    public function generatePdf(int $paymentId): array
    {
        $payment = $this->findPayment($paymentId);
        if (!$payment) {
            return ['error' => 'Payment not found'];
        }

        $data = $this->formatPaymentData($payment);
        $html = $this->buildHtml($data);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf('recu-paiement-%d.pdf', $paymentId);

        return [
            'content' => $dompdf->output(),
            'filename' => $filename,
        ];
    }

    private function findPayment(int $paymentId): array|false
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        $sql = '
            SELECT
                p.*,
                s.first_name,
                s.last_name,
                s.class_name,
                s.parent_name,
                s.phone AS student_phone,
                COALESCE(cl.name, s.class_level) AS class_level_name,
                mf.month_label,
                mf.year_value,
                mf.total_amount AS fee_total_amount,
                mf.amount_paid AS fee_amount_paid,
                mf.remaining_amount AS fee_remaining_amount,
                mf.status AS fee_status,
                pm.label AS payment_method_label,
                sch.name AS school_name,
                sch.address AS school_address,
                sch.city AS school_city,
                sch.phone AS school_phone,
                sch.currency AS school_currency
            FROM payments p
            INNER JOIN students s ON s.id = p.student_id
            INNER JOIN monthly_fees mf ON mf.id = p.monthly_fee_id
            INNER JOIN schools sch ON sch.id = p.school_id
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
            WHERE p.id = ?
        ';

        if ($role === 'super_admin') {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$paymentId]);
        } else {
            $stmt = $pdo->prepare($sql . ' AND p.school_id = ?');
            $stmt->execute([$paymentId, $schoolId]);
        }

        $payment = $stmt->fetch();
        return $payment ?: false;
    }

    private function formatPaymentData(array $payment): array
    {
        $monthKey = str_pad((string)($payment['month_label'] ?? ''), 2, '0', STR_PAD_LEFT);
        $monthName = self::MONTH_LABELS[$monthKey] ?? (string)($payment['month_label'] ?? '-');
        $currency = strtoupper(trim((string)($payment['school_currency'] ?? 'MAD')));

        return [
            'payment_id' => (int)$payment['id'],
            'receipt_number' => sprintf('REC-%06d', (int)$payment['id']),
            'student' => trim(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? '')),
            'parent_name' => (string)($payment['parent_name'] ?? ''),
            'student_phone' => (string)($payment['student_phone'] ?? ''),
            'class_level' => (string)($payment['class_level_name'] ?? '-'),
            'class_name' => (string)($payment['class_name'] ?? '-'),
            'period_label' => $monthName . ' ' . (string)($payment['year_value'] ?? ''),
            'amount_paid' => (float)$payment['amount_paid'],
            'amount_paid_label' => $this->formatMoney((float)$payment['amount_paid'], $currency),
            'payment_date' => (string)$payment['payment_date'],
            'payment_method' => (string)($payment['payment_method_label'] ?: $payment['payment_method']),
            'fee_total_amount' => (float)($payment['fee_total_amount'] ?? 0),
            'fee_total_label' => $this->formatMoney((float)($payment['fee_total_amount'] ?? 0), $currency),
            'fee_amount_paid' => (float)($payment['fee_amount_paid'] ?? 0),
            'fee_amount_paid_label' => $this->formatMoney((float)($payment['fee_amount_paid'] ?? 0), $currency),
            'fee_remaining_amount' => (float)($payment['fee_remaining_amount'] ?? 0),
            'fee_remaining_label' => $this->formatMoney((float)($payment['fee_remaining_amount'] ?? 0), $currency),
            'fee_status' => (string)($payment['fee_status'] ?? ''),
            'fee_status_label' => $this->feeStatusLabel((string)($payment['fee_status'] ?? '')),
            'school_name' => (string)($payment['school_name'] ?? 'EduFlow'),
            'school_address' => trim((string)($payment['school_address'] ?? '')),
            'school_city' => (string)($payment['school_city'] ?? ''),
            'school_phone' => (string)($payment['school_phone'] ?? ''),
            'currency' => $currency,
            'issued_at' => date('d/m/Y H:i'),
        ];
    }

    private function buildHtml(array $data): string
    {
        $schoolLine = trim($data['school_address'] . ($data['school_city'] ? ', ' . $data['school_city'] : ''));
        $schoolContact = $data['school_phone'] !== '' ? 'Tel : ' . $this->escape($data['school_phone']) : '';

        return '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; color: #111827; font-size: 12px; margin: 36px; }
    .header { border-bottom: 3px solid #1E3A8A; padding-bottom: 14px; margin-bottom: 24px; }
    .school-name { font-size: 22px; font-weight: bold; color: #1E3A8A; margin: 0 0 6px 0; }
    .muted { color: #6B7280; margin: 2px 0; }
    .title { text-align: center; font-size: 18px; font-weight: bold; margin: 18px 0 8px 0; letter-spacing: 1px; }
    .receipt-no { text-align: center; color: #374151; margin-bottom: 22px; }
    table.meta { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    table.meta td { padding: 8px 10px; vertical-align: top; }
    table.meta td.label { width: 34%; color: #4B5563; font-weight: bold; background: #F3F4F6; border: 1px solid #E5E7EB; }
    table.meta td.value { border: 1px solid #E5E7EB; }
    .amount-box { margin: 24px 0; padding: 16px; border: 2px solid #1E3A8A; background: #EFF6FF; text-align: center; }
    .amount-label { font-size: 13px; color: #1E3A8A; margin-bottom: 6px; }
    .amount-value { font-size: 28px; font-weight: bold; color: #1E3A8A; }
    .footer { margin-top: 36px; padding-top: 12px; border-top: 1px solid #E5E7EB; font-size: 10px; color: #6B7280; }
    .status { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: bold; }
    .status-paid { background: #DCFCE7; color: #166534; }
    .status-partial { background: #FEF3C7; color: #92400E; }
    .status-unpaid { background: #FEE2E2; color: #991B1B; }
</style>
</head>
<body>
    <div class="header">
        <p class="school-name">' . $this->escape($data['school_name']) . '</p>
        ' . ($schoolLine !== '' ? '<p class="muted">' . $this->escape($schoolLine) . '</p>' : '') . '
        ' . ($schoolContact !== '' ? '<p class="muted">' . $schoolContact . '</p>' : '') . '
    </div>

    <p class="title">RECU DE PAIEMENT</p>
    <p class="receipt-no">N° ' . $this->escape($data['receipt_number']) . ' — Emis le ' . $this->escape($data['issued_at']) . '</p>

    <table class="meta">
        <tr><td class="label">Eleve</td><td class="value">' . $this->escape($data['student']) . '</td></tr>
        <tr><td class="label">Parent / Tuteur</td><td class="value">' . $this->escape($data['parent_name'] ?: '-') . '</td></tr>
        <tr><td class="label">Niveau / Classe</td><td class="value">' . $this->escape($data['class_level'] . ' / ' . $data['class_name']) . '</td></tr>
        <tr><td class="label">Mensualite</td><td class="value">' . $this->escape($data['period_label']) . '</td></tr>
        <tr><td class="label">Date de paiement</td><td class="value">' . $this->escape($this->formatDate($data['payment_date'])) . '</td></tr>
        <tr><td class="label">Mode de paiement</td><td class="value">' . $this->escape($data['payment_method']) . '</td></tr>
        <tr><td class="label">Montant mensualite</td><td class="value">' . $this->escape($data['fee_total_label']) . '</td></tr>
        <tr><td class="label">Total deja paye</td><td class="value">' . $this->escape($data['fee_amount_paid_label']) . '</td></tr>
        <tr><td class="label">Reste a payer</td><td class="value">' . $this->escape($data['fee_remaining_label']) . '</td></tr>
        <tr><td class="label">Statut mensualite</td><td class="value">' . $this->statusBadge($data['fee_status']) . '</td></tr>
    </table>

    <div class="amount-box">
        <div class="amount-label">Montant encaisse (cette operation)</div>
        <div class="amount-value">' . $this->escape($data['amount_paid_label']) . '</div>
    </div>

    <div class="footer">
        Document genere automatiquement par EduFlow. Ce recu atteste du paiement indique ci-dessus.
        Conservez-le pour vos archives.
    </div>
</body>
</html>';
    }

    private function statusBadge(string $status): string
    {
        $label = $this->feeStatusLabel($status);
        $class = match ($status) {
            'PAID' => 'status-paid',
            'PARTIAL' => 'status-partial',
            default => 'status-unpaid',
        };

        return '<span class="status ' . $class . '">' . $this->escape($label) . '</span>';
    }

    private function feeStatusLabel(string $status): string
    {
        return match ($status) {
            'PAID' => 'Paye',
            'PARTIAL' => 'Partiel',
            'UNPAID' => 'Impaye',
            default => $status,
        };
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $formatted = number_format($amount, 2, ',', ' ');
        return $formatted . ' ' . $currency;
    }

    private function formatDate(string $date): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        [$year, $month, $day] = explode('-', $date);
        return $day . '/' . $month . '/' . $year;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
