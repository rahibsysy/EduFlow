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
        'fr' => [
            '01' => 'Janvier', '02' => 'Février', '03' => 'Mars', '04' => 'Avril',
            '05' => 'Mai', '06' => 'Juin', '07' => 'Juillet', '08' => 'Août',
            '09' => 'Septembre', '10' => 'Octobre', '11' => 'Novembre', '12' => 'Décembre',
        ],
        'ar' => [
            '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل',
            '05' => 'ماي', '06' => 'يونيو', '07' => 'يوليوز', '08' => 'غشت',
            '09' => 'شتنبر', '10' => 'أكتوبر', '11' => 'نونبر', '12' => 'دجنبر',
        ],
    ];

    public function generateData(int $paymentId, string $language = 'fr'): array
    {
        $payment = $this->findPayment($paymentId);
        return $payment ? $this->formatPaymentData($payment, $language) : ['error' => 'Payment not found'];
    }

    public function generatePdf(int $paymentId, string $language = 'fr'): array
    {
        $payment = $this->findPayment($paymentId);
        if (!$payment) {
            return ['error' => 'Payment not found'];
        }

        $data = $this->formatPaymentData($payment, $language);
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->buildHtml($data), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $safeNumber = preg_replace('/[^A-Za-z0-9-]+/', '-', (string)$data['receipt_number']) ?: (string)$paymentId;

        return ['content' => $dompdf->output(), 'filename' => 'recu-' . strtolower($safeNumber) . '.pdf'];
    }

    private function findPayment(int $paymentId): array|false
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        $sql = '
            SELECT p.*, s.first_name, s.last_name,
                COALESCE(cl.group_name, s.class_name) AS class_name,
                s.parent_name, s.phone AS student_phone,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                mf.month_label, mf.year_value, mf.total_amount AS fee_total_amount,
                mf.amount_paid AS fee_amount_paid, mf.remaining_amount AS fee_remaining_amount,
                pm.label AS payment_method_label,
                sch.name AS school_name, sch.address AS school_address, sch.city AS school_city,
                sch.phone AS school_phone, sch.currency AS school_currency,
                sch.logo_path AS school_logo_path, sch.primary_color AS school_primary_color,
                sch.secondary_color AS school_secondary_color,
                TRIM(CONCAT(COALESCE(issuer.first_name, ""), " ", COALESCE(issuer.last_name, ""))) AS issued_by_name,
                TRIM(CONCAT(COALESCE(canceller.first_name, ""), " ", COALESCE(canceller.last_name, ""))) AS cancelled_by_name
            FROM payments p
            INNER JOIN students s ON s.id = p.student_id AND s.school_id = p.school_id
            LEFT JOIN monthly_fees mf ON mf.id = p.monthly_fee_id AND mf.school_id = p.school_id
            INNER JOIN schools sch ON sch.id = p.school_id
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id AND cl.school_id = s.school_id
            LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
            LEFT JOIN users issuer ON issuer.id = p.issued_by_user_id
            LEFT JOIN users canceller ON canceller.id = p.cancelled_by_user_id
            WHERE p.id = ?';

        if ($role === 'super_admin') {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$paymentId]);
        } else {
            $stmt = $pdo->prepare($sql . ' AND p.school_id = ?');
            $stmt->execute([$paymentId, $schoolId]);
        }

        $payment = $stmt->fetch() ?: false;
        if (!$payment) {
            return false;
        }

        $allocations = $pdo->prepare('
            SELECT pa.student_financial_item_id, pa.amount,
                   COALESCE(NULLIF(pa.item_label_snapshot, ""), fi.label) AS label,
                   pa.item_label_snapshot,
                   details.final_amount
            FROM payment_allocations pa
            INNER JOIN student_financial_items fi
                ON fi.id = pa.student_financial_item_id AND fi.school_id = pa.school_id
            INNER JOIN student_financial_item_details details
                ON details.student_financial_item_id = fi.id AND details.school_id = fi.school_id
            WHERE pa.payment_id = ? AND pa.school_id = ?
            ORDER BY pa.id
        ');
        $allocations->execute([$paymentId, (int)$payment['school_id']]);
        $payment['allocations'] = $allocations->fetchAll();

        return $payment;
    }

    private function formatPaymentData(array $payment, string $language): array
    {
        $language = $language === 'ar' ? 'ar' : 'fr';
        $monthKey = str_pad((string)($payment['month_label'] ?? ''), 2, '0', STR_PAD_LEFT);
        $currency = strtoupper(trim((string)($payment['school_currency'] ?? 'MAD')));
        $amountPaid = (float)($payment['amount_paid'] ?? 0);
        $isFlexible = empty($payment['monthly_fee_id']);
        $feeTotal = (float)($payment['fee_total_at_payment'] ?? $payment['fee_total_amount'] ?? 0);
        $paidBefore = (float)($payment['paid_before_payment'] ?? max(0, (float)($payment['fee_amount_paid'] ?? 0) - $amountPaid));
        $paidAfter = round($paidBefore + $amountPaid, 2);
        $remainingAfter = (float)($payment['remaining_after_payment'] ?? max(0, $feeTotal - $paidAfter));
        $status = $remainingAfter <= 0 ? 'PAID' : ($paidAfter > 0 ? 'PARTIAL' : 'UNPAID');
        $allocations = array_map(function (array $allocation) use ($currency): array {
            $amount = (float)$allocation['amount'];
            return [
                'label' => (string)($allocation['label'] ?? 'Frais'),
                'amount' => $amount,
                'amount_label' => $this->formatMoney($amount, $currency),
            ];
        }, (array)($payment['allocations'] ?? []));

        return [
            'language' => $language,
            'payment_id' => (int)$payment['id'],
            'receipt_number' => (string)($payment['receipt_number'] ?: sprintf('REC-%06d', (int)$payment['id'])),
            'student' => trim(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? '')),
            'parent_name' => (string)($payment['parent_name'] ?? ''),
            'class_level' => (string)($payment['class_level_name'] ?? '-'),
            'class_name' => (string)($payment['class_name'] ?? '-'),
            'payment_type' => $isFlexible ? 'FLEXIBLE' : 'MONTHLY',
            'period_label' => $isFlexible ? $this->flexibleLabel($language) : (self::MONTH_LABELS[$language][$monthKey] ?? $monthKey) . ' ' . (string)($payment['year_value'] ?? ''),
            'amount_paid' => $amountPaid,
            'amount_paid_label' => $this->formatMoney($amountPaid, $currency),
            'payment_date' => (string)($payment['payment_date'] ?? ''),
            'payment_method' => (string)(($payment['payment_method_label'] ?? '') ?: ($payment['payment_method'] ?? '')),
            'fee_total_amount' => $feeTotal,
            'fee_total_label' => $this->formatMoney($feeTotal, $currency),
            'fee_amount_paid' => $paidAfter,
            'fee_amount_paid_label' => $this->formatMoney($paidAfter, $currency),
            'paid_before_label' => $this->formatMoney($paidBefore, $currency),
            'paid_after_label' => $this->formatMoney($paidAfter, $currency),
            'fee_remaining_amount' => $remainingAfter,
            'fee_remaining_label' => $this->formatMoney($remainingAfter, $currency),
            'fee_status' => $status,
            'fee_status_label' => $this->statusText($status, $language),
            'allocations' => $allocations,
            'payment_status' => (string)($payment['status'] ?? 'COMPLETED'),
            'is_cancelled' => ($payment['status'] ?? '') === 'CANCELLED',
            'cancellation_reason' => trim((string)($payment['cancellation_reason'] ?? '')),
            'cancelled_at' => (string)($payment['cancelled_at'] ?? ''),
            'cancelled_by_name' => trim((string)($payment['cancelled_by_name'] ?? '')),
            'school_name' => (string)($payment['school_name'] ?? 'Établissement'),
            'school_address' => trim((string)($payment['school_address'] ?? '')),
            'school_city' => (string)($payment['school_city'] ?? ''),
            'school_phone' => (string)($payment['school_phone'] ?? ''),
            'school_logo_data_url' => $this->logoDataUrl((string)($payment['school_logo_path'] ?? '')),
            'primary' => $this->safeColor((string)($payment['school_primary_color'] ?? ''), '#0F172A'),
            'secondary' => $this->safeColor((string)($payment['school_secondary_color'] ?? ''), '#2563EB'),
            'issued_by_name' => trim((string)($payment['issued_by_name'] ?? '')),
            'currency' => $currency,
            'issued_at' => $this->formatDateTime((string)($payment['created_at'] ?? '')),
        ];
    }

    private function buildHtml(array $data): string
    {
        $language = $data['language'];
        $labels = $this->labels($language);
        $dir = $language === 'ar' ? 'rtl' : 'ltr';
        $align = $language === 'ar' ? 'right' : 'left';
        $schoolLine = trim($data['school_address'] . ($data['school_city'] ? ', ' . $data['school_city'] : ''));
        $schoolContact = $data['school_phone'] === '' ? '' : (($language === 'ar' ? 'الهاتف: ' : 'Tél. : ') . $data['school_phone']);
        $firstLetter = function_exists('mb_substr') ? mb_substr($data['school_name'], 0, 1, 'UTF-8') : substr($data['school_name'], 0, 1);
        $logo = $data['school_logo_data_url']
            ? '<img class="school-logo" src="' . $this->escape($data['school_logo_data_url']) . '" alt="" />'
            : '<div class="monogram">' . $this->escape($firstLetter) . '</div>';
        $primary = $this->escape($data['primary']);
        $secondary = $this->escape($data['secondary']);
        $issuedBy = $data['issued_by_name'] ?: '-';
        $isFlexible = $data['payment_type'] === 'FLEXIBLE';
        $cancelled = (bool)$data['is_cancelled'];
        $title = $cancelled ? $labels['cancelled_title'] : $labels['title'];
        $paymentRows = $this->row($labels['type'], $isFlexible ? $labels['flexible_type'] : $labels['monthly_type'])
            . $this->row($labels['payment_date'], $this->formatDate($data['payment_date']))
            . $this->row($labels['method'], $data['payment_method']);
        if ($isFlexible) {
            $paymentRows .= $this->row($labels['period'], $data['period_label']);
        } else {
            $paymentRows .= $this->row($labels['period'], $data['period_label'])
                . $this->row($labels['fee_total'], $data['fee_total_label'])
                . $this->row($labels['paid_before'], $data['paid_before_label'])
                . $this->row($labels['paid_after'], $data['paid_after_label'])
                . $this->row($labels['remaining'], $data['fee_remaining_label'], 'remaining')
                . '<tr><td class="label">' . $this->escape($labels['fee_status']) . '</td><td>' . $this->statusBadge($data['fee_status'], $language) . '</td></tr>';
        }
        if ($cancelled) {
            $paymentRows .= '<tr><td class="label">' . $this->escape($labels['payment_status']) . '</td><td><span class="status cancelled">' . $this->escape($labels['cancelled']) . '</span></td></tr>'
                . $this->row($labels['cancellation_reason'], $data['cancellation_reason'] ?: '-')
                . $this->row($labels['cancelled_by'], $data['cancelled_by_name'] ?: '-');
        }
        $allocationTable = $isFlexible ? $this->allocationTable($data['allocations'], $labels) : '';

        return '<!doctype html><html lang="' . $language . '" dir="' . $dir . '"><head><meta charset="UTF-8"><style>
@page{margin:28px}body{font-family:DejaVu Sans,sans-serif;color:#0f172a;font-size:11px;margin:0;direction:' . $dir . ';text-align:' . $align . '}.document{border:1px solid #cbd5e1;padding:24px}.header{width:100%;border-bottom:3px solid ' . $primary . ';padding-bottom:16px;margin-bottom:22px}.header td{vertical-align:middle}.logo-cell{width:84px}.school-logo{width:68px;height:68px;object-fit:contain}.monogram{width:62px;height:62px;line-height:62px;text-align:center;border-radius:12px;background:' . $primary . ';color:#fff;font-size:28px;font-weight:bold}.school-name{font-size:22px;font-weight:bold;color:' . $primary . ';margin:0 0 5px}.muted{color:#64748b;margin:2px 0}.title{text-align:center;font-size:20px;font-weight:bold;margin:18px 0 5px}.receipt-no{text-align:center;color:#475569;margin-bottom:20px}.meta{width:100%;border-collapse:collapse;margin-bottom:18px}.meta td{padding:8px 10px;border:1px solid #cbd5e1}.meta .label{width:38%;color:#334155;font-weight:bold;background:#f8fafc}.allocations{width:100%;border-collapse:collapse;margin:14px 0}.allocations th,.allocations td{padding:7px 9px;border:1px solid #cbd5e1}.allocations th{background:#f8fafc}.amount-box{margin:20px 0;padding:16px;border:2px solid ' . $primary . ';border-top-color:' . $secondary . ';background:#f8fafc;text-align:center}.amount-label{font-size:12px;color:#475569;margin-bottom:5px}.amount-value{font-size:27px;font-weight:bold;color:' . $primary . '}.remaining{color:#b42318;font-weight:bold}.status{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:bold}.paid{background:#dcfce7;color:#166534}.partial{background:#fef3c7;color:#92400e}.unpaid{background:#fee2e2;color:#991b1b}.cancelled{background:#fee2e2;color:#991b1b}.cancellation-note{padding:10px;border:1px solid #dc2626;background:#fef2f2;color:#991b1b;font-weight:bold;text-align:center;margin-bottom:14px}.signatures{width:100%;margin-top:42px}.signatures td{width:50%;height:82px;padding:8px;text-align:center;vertical-align:top;color:#475569;font-weight:bold}.line{margin:54px 24px 0;border-top:1px solid #94a3b8}.footer{margin-top:24px;padding-top:10px;border-top:1px solid #cbd5e1;font-size:9px;color:#64748b;text-align:center}
</style></head><body><div class="document">
<table class="header"><tr><td class="logo-cell">' . $logo . '</td><td><p class="school-name">' . $this->escape($data['school_name']) . '</p>' . ($schoolLine ? '<p class="muted">' . $this->escape($schoolLine) . '</p>' : '') . ($schoolContact ? '<p class="muted">' . $this->escape($schoolContact) . '</p>' : '') . '</td></tr></table>
<p class="title">' . $this->escape($title) . '</p><p class="receipt-no">' . $this->escape($labels['number']) . ': <strong>' . $this->escape($data['receipt_number']) . '</strong> · ' . $this->escape($labels['issued']) . ': ' . $this->escape($data['issued_at']) . '</p>' . ($cancelled ? '<p class="cancellation-note">' . $this->escape($labels['cancelled_notice']) . '</p>' : '') . '
<table class="meta">' .
    $this->row($labels['student'], $data['student']) .
    $this->row($labels['parent'], $data['parent_name'] ?: '-') .
    $this->row($labels['class'], $data['class_level'] . ' / ' . $data['class_name']) .
    $paymentRows .
    $this->row($labels['issued_by'], $issuedBy) .
'</table>' . $allocationTable . '<div class="amount-box"><div class="amount-label">' . $this->escape($labels['operation']) . '</div><div class="amount-value">' . $this->escape($data['amount_paid_label']) . '</div></div>
<table class="signatures"><tr><td>' . $this->escape($labels['signature']) . '<div class="line"></div></td><td>' . $this->escape($labels['stamp']) . '<div class="line"></div></td></tr></table>
<div class="footer">' . $this->escape($labels['footer']) . '</div></div></body></html>';
    }

    private function labels(string $language): array
    {
        if ($language === 'ar') {
            return ['title' => 'وصل الأداء', 'cancelled_title' => 'وصل أداء ملغى', 'number' => 'رقم الوصل', 'issued' => 'تاريخ الإصدار', 'student' => 'التلميذ(ة)', 'parent' => 'ولي الأمر', 'class' => 'المستوى / القسم', 'type' => 'نوع الأداء', 'monthly_type' => 'واجب شهري', 'flexible_type' => 'رسوم متنوعة', 'period' => 'الفترة / الوصف', 'payment_date' => 'تاريخ الأداء', 'method' => 'طريقة الأداء', 'fee_total' => 'مبلغ الواجب', 'paid_before' => 'المبلغ المؤدى قبل هذه العملية', 'operation' => 'المبلغ المستخلص في هذه العملية', 'paid_after' => 'مجموع المبلغ المؤدى بعد العملية', 'remaining' => 'الباقي الواجب أداؤه', 'fee_status' => 'حالة الواجب', 'payment_status' => 'حالة الأداء', 'cancelled' => 'ملغى', 'cancellation_reason' => 'سبب الإلغاء', 'cancelled_by' => 'أُلغي بواسطة', 'cancelled_notice' => 'هذا الأداء ملغى ولا يُحتسب كتحصيل.', 'allocations' => 'تفاصيل الرسوم المخصصة', 'allocation_label' => 'الرسم', 'allocation_amount' => 'المبلغ المخصص', 'issued_by' => 'تم الاستخلاص من طرف', 'signature' => 'توقيع المسؤول', 'stamp' => 'خاتم المؤسسة', 'footer' => 'تم إنشاء هذا الوصل بواسطة OneCore. يرجى الاحتفاظ به ضمن وثائقكم.'];
        }

        return ['title' => 'REÇU DE PAIEMENT', 'cancelled_title' => 'REÇU DE PAIEMENT ANNULÉ', 'number' => 'Numéro du reçu', 'issued' => 'Date d’émission', 'student' => 'Élève', 'parent' => 'Parent / Tuteur', 'class' => 'Niveau / Classe', 'type' => 'Type de paiement', 'monthly_type' => 'Mensualité', 'flexible_type' => 'Frais flexibles', 'period' => 'Période / description', 'payment_date' => 'Date de paiement', 'method' => 'Mode de paiement', 'fee_total' => 'Montant de la mensualité', 'paid_before' => 'Payé avant cette opération', 'operation' => 'Montant encaissé — cette opération', 'paid_after' => 'Total payé après cette opération', 'remaining' => 'Reste à payer après cette opération', 'fee_status' => 'Statut de la mensualité', 'payment_status' => 'Statut du paiement', 'cancelled' => 'Annulé', 'cancellation_reason' => 'Motif d’annulation', 'cancelled_by' => 'Annulé par', 'cancelled_notice' => 'Ce paiement est annulé et ne constitue pas un encaissement actif.', 'allocations' => 'Frais réglés par cette opération', 'allocation_label' => 'Frais', 'allocation_amount' => 'Montant alloué', 'issued_by' => 'Encaissement enregistré par', 'signature' => 'Signature du responsable', 'stamp' => 'Cachet établissement', 'footer' => 'Document généré par OneCore. Ce reçu atteste du paiement indiqué ci-dessus. Conservez-le pour vos archives.'];
    }

    private function flexibleLabel(string $language): string
    {
        return $language === 'ar' ? 'رسوم متنوعة' : 'Frais flexibles';
    }

    private function allocationTable(array $allocations, array $labels): string
    {
        if (!$allocations) {
            return '';
        }
        $html = '<h3>' . $this->escape($labels['allocations']) . '</h3><table class="allocations"><thead><tr><th>'
            . $this->escape($labels['allocation_label']) . '</th><th>' . $this->escape($labels['allocation_amount'])
            . '</th></tr></thead><tbody>';
        foreach ($allocations as $allocation) {
            $html .= '<tr><td>' . $this->escape($allocation['label']) . '</td><td>' . $this->escape($allocation['amount_label']) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private function row(string $label, string $value, string $class = ''): string
    {
        return '<tr><td class="label">' . $this->escape($label) . '</td><td class="' . $class . '">' . $this->escape($value) . '</td></tr>';
    }

    private function statusBadge(string $status, string $language): string
    {
        $class = match ($status) {'PAID' => 'paid', 'PARTIAL' => 'partial', default => 'unpaid'};
        $label = $this->statusText($status, $language);
        return '<span class="status ' . $class . '">' . $this->escape($label) . '</span>';
    }

    private function statusText(string $status, string $language): string
    {
        return $language === 'ar'
            ? match ($status) {'PAID' => 'مؤدى', 'PARTIAL' => 'مؤدى جزئيا', default => 'غير مؤدى'}
            : match ($status) {'PAID' => 'Payé', 'PARTIAL' => 'Partiel', default => 'Impayé'};
    }

    private function logoDataUrl(string $logoPath): ?string
    {
        if (trim($logoPath) === '' || preg_match('/^https?:\/\//i', $logoPath)) return null;
        $publicRoot = realpath(__DIR__ . '/../../public');
        $absolutePath = realpath(__DIR__ . '/../../public/' . ltrim(str_replace('\\', '/', $logoPath), '/'));
        if (!$publicRoot || !$absolutePath || !str_starts_with($absolutePath, $publicRoot) || !is_file($absolutePath)) return null;
        $mime = mime_content_type($absolutePath) ?: 'image/png';
        if (!str_starts_with($mime, 'image/')) return null;
        $content = file_get_contents($absolutePath);
        return $content === false ? null : 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    private function safeColor(string $color, string $fallback): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? strtoupper($color) : $fallback;
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return number_format($amount, 2, ',', ' ') . ' ' . $currency;
    }

    private function formatDate(string $date): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $date;
        [$year, $month, $day] = explode('-', $date);
        return $day . '/' . $month . '/' . $year;
    }

    private function formatDateTime(string $dateTime): string
    {
        $timestamp = strtotime($dateTime);
        return $timestamp ? date('d/m/Y H:i', $timestamp) : date('d/m/Y H:i');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
