<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use Throwable;

class PaymentService
{
    public function getAll(): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $sql = '
            SELECT
                p.*,
                s.first_name,
                s.last_name,
                COALESCE(cl.group_name, s.class_name) AS class_name,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                mf.month_label,
                mf.year_value,
                p.status AS payment_status,
                mf.status AS monthly_fee_status,
                p.status AS payment_record_status,
                pm.label AS payment_method_label
            FROM payments p
            INNER JOIN students s ON s.id = p.student_id
            LEFT JOIN monthly_fees mf ON mf.id = p.monthly_fee_id AND mf.school_id = p.school_id
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
        ';

        if ($role === 'super_admin') {
            $stmt = $pdo->query($sql . ' ORDER BY p.id DESC');
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare($sql . ' WHERE p.school_id = ? ORDER BY p.id DESC');
        $stmt->execute([$schoolId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        if ($role === 'super_admin') {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
        }

        if (!$schoolId) {
            return ['error' => 'School is required to create payment'];
        }

        if (!empty($data['allocations']) && is_array($data['allocations'])) {
            return $this->createFlexible($data, $schoolId);
        }

        $studentId = (int)($data['student_id'] ?? 0);
        if ($studentId <= 0) {
            return ['error' => 'student_id is required'];
        }

        $studentStmt = $pdo->prepare('
            SELECT s.id, sch.code AS school_code
            FROM students s
            INNER JOIN schools sch ON sch.id = s.school_id
            WHERE s.id = ? AND s.school_id = ?
            LIMIT 1
        ');
        $studentStmt->execute([$studentId, $schoolId]);
        $student = $studentStmt->fetch();
        if (!$student) {
            return ['error' => 'Student not found for this school'];
        }

        $amountPaid = round((float)($data['amount_paid'] ?? 0), 2);
        if ($amountPaid <= 0) {
            return ['error' => 'amount_paid must be greater than 0'];
        }

        $paymentDate = trim((string)($data['payment_date'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            return ['error' => 'Invalid payment_date format'];
        }

        $monthlyFeeId = isset($data['monthly_fee_id']) ? (int)$data['monthly_fee_id'] : null;
        $monthLabel = isset($data['month_label']) ? trim((string)$data['month_label']) : null;
        $yearValue = isset($data['year_value']) ? (int)$data['year_value'] : null;
        $monthlyFeeService = new MonthlyFeeService();
        $paymentMethod = $this->resolvePaymentMethod($pdo, $data);
        if (isset($paymentMethod['error'])) {
            return $paymentMethod;
        }

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            $resolved = $monthlyFeeService->resolveOrCreateFee($schoolId, $studentId, $monthlyFeeId, $monthLabel, $yearValue);
            if (isset($resolved['error'])) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return $resolved;
            }

            $fee = $resolved['fee'];
            $currentPaid = (float)($fee['amount_paid'] ?? 0);
            $totalAmount = (float)($fee['total_amount'] ?? 0);
            $remainingAmount = (float)($fee['remaining_amount'] ?? max(0, $totalAmount - $currentPaid));

            if ($remainingAmount <= 0) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return ['error' => 'Monthly fee already fully paid'];
            }

            if ($amountPaid > $remainingAmount) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return ['error' => 'amount_paid exceeds remaining amount'];
            }

            $insert = $pdo->prepare('
                INSERT INTO payments (
                    school_id, student_id, monthly_fee_id, amount_paid, payment_date, payment_method_id, payment_method
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $insert->execute([
                $schoolId,
                $studentId,
                (int)$fee['id'],
                $amountPaid,
                $paymentDate,
                $paymentMethod['id'],
                $paymentMethod['code'],
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            $updatedPaid = round($currentPaid + $amountPaid, 2);
            $updatedRemaining = round(max(0, $totalAmount - $updatedPaid), 2);
            $updatedStatus = 'UNPAID';
            if ($updatedRemaining <= 0) {
                $updatedStatus = 'PAID';
            } elseif ($updatedPaid > 0) {
                $updatedStatus = 'PARTIAL';
            }

            $authUser = Request::get('auth_user', []);
            $issuedByUserId = isset($authUser['id']) ? (int)$authUser['id'] : null;
            $schoolCode = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '-', (string)$student['school_code']));
            $schoolCode = trim($schoolCode, '-') ?: 'ECOLE';
            $receiptNumber = sprintf('%s-%s-%06d', $schoolCode, substr($paymentDate, 0, 4), $paymentId);

            $updatePayment = $pdo->prepare('
                UPDATE payments
                SET
                    receipt_number = ?,
                    fee_total_at_payment = ?,
                    paid_before_payment = ?,
                    remaining_after_payment = ?,
                    issued_by_user_id = ?
                WHERE id = ?
            ');
            $updatePayment->execute([
                $receiptNumber,
                $totalAmount,
                $currentPaid,
                $updatedRemaining,
                $issuedByUserId ?: null,
                $paymentId,
            ]);

            $updateFee = $pdo->prepare('
                UPDATE monthly_fees
                SET amount_paid = ?, remaining_amount = ?, status = ?
                WHERE id = ?
            ');
            $updateFee->execute([$updatedPaid, $updatedRemaining, $updatedStatus, (int)$fee['id']]);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'id' => $paymentId,
                'school_id' => $schoolId,
                'monthly_fee_id' => (int)$fee['id'],
                'receipt_number' => $receiptNumber,
                'monthly_fee_status' => $updatedStatus,
                'monthly_fee_remaining_amount' => $updatedRemaining,
                'message' => 'Payment created successfully',
            ];
        } catch (Throwable) {
            if (($startedTransaction ?? false) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => 'Payment creation failed'];
        }
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }

    private function resolvePaymentMethod(\PDO $pdo, array $data): array
    {
        $paymentMethodId = isset($data['payment_method_id']) ? (int)$data['payment_method_id'] : 0;
        if ($paymentMethodId > 0) {
            $stmt = $pdo->prepare('SELECT id, code FROM payment_methods WHERE id = ? AND status = "ACTIVE" LIMIT 1');
            $stmt->execute([$paymentMethodId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['error' => 'Invalid payment_method_id'];
            }
            return ['id' => (int)$row['id'], 'code' => (string)$row['code']];
        }

        $rawCode = strtoupper(trim((string)($data['payment_method'] ?? 'CASH')));
        $normalizedCode = match ($rawCode) {
            'ESPECES', 'LIQUIDE', 'LIQUIDES' => 'CASH',
            'CARTE', 'CB' => 'CARD',
            'TRANSFER', 'VIREMENT' => 'BANK_TRANSFER',
            'CHEQUE' => 'CHECK',
            'MOBILE_PAYMENT' => 'MOBILE',
            default => $rawCode !== '' ? $rawCode : 'CASH',
        };

        $stmt = $pdo->prepare('SELECT id, code FROM payment_methods WHERE code = ? AND status = "ACTIVE" LIMIT 1');
        $stmt->execute([$normalizedCode]);
        $method = $stmt->fetch();
        if ($method) {
            return ['id' => (int)$method['id'], 'code' => (string)$method['code']];
        }

        $fallback = $pdo->query('SELECT id, code FROM payment_methods WHERE code = "CASH" LIMIT 1')->fetch();
        if (!$fallback) {
            return ['error' => 'No active payment method configured'];
        }

        return ['id' => (int)$fallback['id'], 'code' => (string)$fallback['code']];
    }

    public function getById(int $paymentId): array|false
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $sql = '
            SELECT
                p.*,
                s.first_name,
                s.last_name,
                mf.month_label,
                mf.year_value,
                pm.label AS payment_method_label
            FROM payments p
            INNER JOIN students s ON s.id = p.student_id
            LEFT JOIN monthly_fees mf ON mf.id = p.monthly_fee_id AND mf.school_id = p.school_id
            LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
            WHERE p.id = ?
        ';

        if ($role !== 'super_admin') {
            $sql .= ' AND p.school_id = ?';
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute([$paymentId, $schoolId]);
        } else {
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute([$paymentId]);
        }

        $payment = $stmt->fetch() ?: false;
        if (!$payment) {
            return false;
        }

        $allocations = $pdo->prepare('
            SELECT pa.id, pa.student_financial_item_id, pa.amount, pa.created_at,
                   COALESCE(NULLIF(pa.item_label_snapshot, ""), fi.label) AS label,
                   fi.status AS item_status,
                   details.final_amount
            FROM payment_allocations pa
            INNER JOIN student_financial_items fi ON fi.id = pa.student_financial_item_id AND fi.school_id = pa.school_id
            INNER JOIN student_financial_item_details details ON details.student_financial_item_id = fi.id AND details.school_id = fi.school_id
            WHERE pa.payment_id = ? AND pa.school_id = ?
            ORDER BY pa.id
        ');
        $allocations->execute([$paymentId, (int)$payment['school_id']]);
        $payment['allocations'] = $allocations->fetchAll();

        return $payment;
    }

    public function update(int $paymentId, array $data): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $payment = $this->getById($paymentId);
        if (!$payment) {
            return ['error' => 'Payment not found'];
        }
        if (!empty($payment['allocations'])) {
            return ['error' => 'Flexible payments cannot be edited; cancel and create a corrected payment'];
        }

        $amountPaid = isset($data['amount_paid']) ? round((float)$data['amount_paid'], 2) : (float)$payment['amount_paid'];
        if ($amountPaid <= 0) {
            return ['error' => 'amount_paid must be greater than 0'];
        }

        $paymentDate = isset($data['payment_date']) ? trim((string)$data['payment_date']) : $payment['payment_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            return ['error' => 'Invalid payment_date format'];
        }

        $paymentMethod = $this->resolvePaymentMethod($pdo, $data);
        if (isset($paymentMethod['error'])) {
            return $paymentMethod;
        }

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            // Get the current monthly fee
            $feeStmt = $pdo->prepare('SELECT * FROM monthly_fees WHERE id = ? LIMIT 1');
            $feeStmt->execute([$payment['monthly_fee_id']]);
            $fee = $feeStmt->fetch();

            if (!$fee) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return ['error' => 'Monthly fee not found'];
            }

            $totalAmount = (float)$fee['total_amount'];
            $oldAmountPaid = (float)$payment['amount_paid'];
            $currentFeeAmountPaid = (float)$fee['amount_paid'];
            
            // Calculate new fee state
            $newFeeAmountPaid = round($currentFeeAmountPaid - $oldAmountPaid + $amountPaid, 2);
            $newRemaining = round(max(0, $totalAmount - $newFeeAmountPaid), 2);
            
            // Validate new amount doesn't exceed total
            if ($newFeeAmountPaid > $totalAmount) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
                return ['error' => 'Total amount paid would exceed monthly fee total'];
            }

            // Update payment
            $updatePayment = $pdo->prepare('
                UPDATE payments
                SET amount_paid = ?, payment_date = ?, payment_method_id = ?, payment_method = ?
                WHERE id = ?
            ');
            $updatePayment->execute([
                $amountPaid,
                $paymentDate,
                $paymentMethod['id'],
                $paymentMethod['code'],
                $paymentId,
            ]);

            // Update monthly fee status
            $newStatus = 'UNPAID';
            if ($newRemaining <= 0) {
                $newStatus = 'PAID';
            } elseif ($newFeeAmountPaid > 0) {
                $newStatus = 'PARTIAL';
            }

            $updateFee = $pdo->prepare('
                UPDATE monthly_fees
                SET amount_paid = ?, remaining_amount = ?, status = ?
                WHERE id = ?
            ');
            $updateFee->execute([$newFeeAmountPaid, $newRemaining, $newStatus, $payment['monthly_fee_id']]);

            if ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'id' => $paymentId,
                'monthly_fee_status' => $newStatus,
                'message' => 'Payment updated successfully',
            ];
        } catch (Throwable) {
            if (($startedTransaction ?? false) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => 'Payment update failed'];
        }
    }

    public function delete(int $paymentId, array $data): array
    {
        // Backward-compatible HTTP DELETE: it is now an immutable cancellation.
        return $this->cancel($paymentId, $data);
    }

    public function cancel(int $paymentId, array $data): array
    {
        $payment = $this->getById($paymentId);
        if (!$payment) {
            return ['error' => 'Payment not found'];
        }
        if (($payment['status'] ?? 'COMPLETED') === 'CANCELLED') {
            return ['error' => 'Payment is already cancelled'];
        }

        $reason = trim((string)($data['reason'] ?? ''));
        if ($reason === '') {
            return ['error' => 'A cancellation reason is required'];
        }

        $pdo = Database::connect();
        $userId = (int)(Request::get('auth_user', [])['id'] ?? 0) ?: null;
        try {
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $lockedPayment = $pdo->prepare('SELECT * FROM payments WHERE id = ? AND school_id = ? FOR UPDATE');
            $lockedPayment->execute([$paymentId, (int)$payment['school_id']]);
            $before = $lockedPayment->fetch();
            if (!$before || $before['status'] !== 'COMPLETED') {
                throw new \RuntimeException('Payment cancellation failed');
            }

            $stmt = $pdo->prepare('UPDATE payments SET status = "CANCELLED", cancelled_at = CURRENT_TIMESTAMP, cancelled_by_user_id = ?, cancellation_reason = ? WHERE id = ? AND school_id = ? AND status = "COMPLETED"');
            $stmt->execute([$userId, $reason, $paymentId, (int)$payment['school_id']]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Payment cancellation failed');
            }

            foreach ($payment['allocations'] as $allocation) {
                $this->refreshFlexibleItem($pdo, (int)$payment['school_id'], (int)$allocation['student_financial_item_id'], 'PAYMENT_CANCELLED', $reason, $userId);
            }
            if (empty($payment['allocations']) && !empty($before['monthly_fee_id'])) {
                $this->refreshMonthlyFee($pdo, (int)$payment['school_id'], (int)$before['monthly_fee_id']);
            }
            $after = $before;
            $after['status'] = 'CANCELLED';
            $after['cancellation_reason'] = $reason;
            $this->recordHistory($pdo, $paymentId, (int)$payment['school_id'], 'CANCELLED', $before, $after, $reason, $userId);
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['id' => $paymentId, 'status' => 'CANCELLED', 'message' => 'Payment cancelled; the receipt and audit trail are retained'];
        } catch (\Throwable $exception) {
            if (($ownsTransaction ?? false) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => $exception->getMessage() === 'Payment cancellation failed' ? $exception->getMessage() : 'Payment cancellation failed'];
        }
    }

    private function refreshMonthlyFee(\PDO $pdo, int $schoolId, int $feeId): void
    {
        $fee = $pdo->prepare('SELECT id, total_amount FROM monthly_fees WHERE id = ? AND school_id = ? FOR UPDATE');
        $fee->execute([$feeId, $schoolId]);
        $row = $fee->fetch();
        if (!$row) {
            throw new \RuntimeException('Monthly fee not found');
        }
        // Lock the payment rows before computing the canonical balance.
        $rows = $pdo->prepare('SELECT id FROM payments WHERE monthly_fee_id = ? AND school_id = ? FOR UPDATE');
        $rows->execute([$feeId, $schoolId]);
        $total = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE monthly_fee_id = ? AND school_id = ? AND status = "COMPLETED"');
        $total->execute([$feeId, $schoolId]);
        $paid = round((float)$total->fetchColumn(), 2);
        $remaining = round(max(0, (float)$row['total_amount'] - $paid), 2);
        $status = $remaining <= 0 ? 'PAID' : ($paid > 0 ? 'PARTIAL' : 'UNPAID');
        $pdo->prepare('UPDATE monthly_fees SET amount_paid = ?, remaining_amount = ?, status = ? WHERE id = ? AND school_id = ?')
            ->execute([$paid, $remaining, $status, $feeId, $schoolId]);
    }

    private function recordHistory(\PDO $pdo, int $paymentId, int $schoolId, string $action, array $before, array $after, string $reason, ?int $userId): void
    {
        $pdo->prepare('INSERT INTO payment_history (payment_id, school_id, action, before_data, after_data, reason, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$paymentId, $schoolId, $action, json_encode($before), json_encode($after), $reason, $userId]);
    }

    private function createFlexible(array $data, int $schoolId): array
    {
        $pdo = Database::connect();
        $studentId = (int)($data['student_id'] ?? 0);
        $amountPaid = round((float)($data['amount_paid'] ?? $data['amount'] ?? 0), 2);
        $paymentDate = trim((string)($data['payment_date'] ?? date('Y-m-d')));
        $allocations = $this->normalizeAllocations($data['allocations'] ?? []);

        if ($studentId <= 0 || $amountPaid <= 0 || !$allocations) {
            return ['error' => 'student_id, amount_paid and allocations are required'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            return ['error' => 'Invalid payment_date format'];
        }
        $allocatedTotal = round(array_sum(array_column($allocations, 'amount')), 2);
        if (abs($allocatedTotal - $amountPaid) > 0.00001) {
            return ['error' => 'The allocation total must equal amount_paid'];
        }

        $student = $pdo->prepare('SELECT s.id, sch.code AS school_code FROM students s INNER JOIN schools sch ON sch.id = s.school_id WHERE s.id = ? AND s.school_id = ? LIMIT 1');
        $student->execute([$studentId, $schoolId]);
        $studentRow = $student->fetch();
        if (!$studentRow) {
            return ['error' => 'Student not found for this school'];
        }
        $paymentMethod = $this->resolvePaymentMethod($pdo, $data);
        if (isset($paymentMethod['error'])) {
            return $paymentMethod;
        }

        try {
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            $items = [];
            foreach ($allocations as $allocation) {
                $item = $this->lockedFlexibleItem($pdo, $schoolId, $studentId, $allocation['student_financial_item_id']);
                if (!$item) {
                    throw new \DomainException('A charge does not belong to this student or school');
                }
                if ($item['status'] === 'EXEMPT') {
                    throw new \DomainException('A cancelled charge cannot be paid');
                }
                $remaining = round((float)$item['final_amount'] - (float)$item['allocated_paid'], 2);
                if ($remaining <= 0 || $allocation['amount'] > $remaining + 0.00001) {
                    throw new \DomainException('An allocation exceeds the remaining charge balance');
                }
                $items[$allocation['student_financial_item_id']] = $item;
            }

            $insert = $pdo->prepare('INSERT INTO payments (school_id, student_id, monthly_fee_id, amount_paid, payment_date, payment_method_id, payment_method, status) VALUES (?, ?, NULL, ?, ?, ?, ?, "COMPLETED")');
            $insert->execute([$schoolId, $studentId, $amountPaid, $paymentDate, $paymentMethod['id'], $paymentMethod['code']]);
            $paymentId = (int)$pdo->lastInsertId();
            $schoolCode = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '-', (string)$studentRow['school_code']));
            $schoolCode = trim($schoolCode, '-') ?: 'ECOLE';
            $receiptNumber = sprintf('%s-%s-%06d', $schoolCode, substr($paymentDate, 0, 4), $paymentId);
            $userId = (int)(Request::get('auth_user', [])['id'] ?? 0) ?: null;
            $pdo->prepare('UPDATE payments SET receipt_number = ?, issued_by_user_id = ? WHERE id = ?')->execute([$receiptNumber, $userId, $paymentId]);

            $insertAllocation = $pdo->prepare('INSERT INTO payment_allocations (school_id, payment_id, student_financial_item_id, amount, item_label_snapshot) VALUES (?, ?, ?, ?, ?)');
            foreach ($allocations as $allocation) {
                $item = $items[$allocation['student_financial_item_id']];
                $insertAllocation->execute([$schoolId, $paymentId, $allocation['student_financial_item_id'], $allocation['amount'], $item['label']]);
            }
            foreach (array_keys($items) as $itemId) {
                $this->refreshFlexibleItem($pdo, $schoolId, $itemId, 'PAYMENT_ALLOCATED', null, $userId);
            }
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return ['id' => $paymentId, 'school_id' => $schoolId, 'receipt_number' => $receiptNumber, 'allocations' => $allocations, 'message' => 'Flexible payment created successfully'];
        } catch (\DomainException $exception) {
            if (($ownsTransaction ?? false) && $pdo->inTransaction()) $pdo->rollBack();
            return ['error' => $exception->getMessage()];
        } catch (\Throwable $exception) {
            if (($ownsTransaction ?? false) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Flexible payment creation failed: ' . $exception->getMessage());
            return ['error' => 'Flexible payment creation failed'];
        }
    }

    private function normalizeAllocations(array $allocations): array
    {
        $normalized = [];
        foreach ($allocations as $allocation) {
            $itemId = (int)($allocation['student_financial_item_id'] ?? 0);
            $amount = round((float)($allocation['amount'] ?? 0), 2);
            if ($itemId <= 0 || $amount <= 0 || isset($normalized[$itemId])) {
                return [];
            }
            $normalized[$itemId] = ['student_financial_item_id' => $itemId, 'amount' => $amount];
        }
        return array_values($normalized);
    }

    private function lockedFlexibleItem(\PDO $pdo, int $schoolId, int $studentId, int $itemId): array|false
    {
        $stmt = $pdo->prepare('
            SELECT fi.id, fi.label, fi.status, details.final_amount,
                   COALESCE(SUM(CASE WHEN payment.status = "COMPLETED" THEN allocation.amount ELSE 0 END), 0) AS allocated_paid
            FROM student_financial_items fi
            INNER JOIN student_financial_item_details details ON details.student_financial_item_id = fi.id AND details.school_id = fi.school_id
            LEFT JOIN payment_allocations allocation ON allocation.student_financial_item_id = fi.id AND allocation.school_id = fi.school_id
            LEFT JOIN payments payment ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
            WHERE fi.id = ? AND fi.student_id = ? AND fi.school_id = ?
            GROUP BY fi.id, fi.label, fi.status, details.final_amount
            FOR UPDATE
        ');
        $stmt->execute([$itemId, $studentId, $schoolId]);
        return $stmt->fetch() ?: false;
    }

    private function refreshFlexibleItem(\PDO $pdo, int $schoolId, int $itemId, string $action, ?string $reason, ?int $userId): void
    {
        $item = $pdo->prepare('
            SELECT fi.id, fi.paid_amount, fi.status, details.final_amount,
                   COALESCE(SUM(CASE WHEN payment.status = "COMPLETED" THEN allocation.amount ELSE 0 END), 0) AS paid
            FROM student_financial_items fi
            INNER JOIN student_financial_item_details details ON details.student_financial_item_id = fi.id AND details.school_id = fi.school_id
            LEFT JOIN payment_allocations allocation ON allocation.student_financial_item_id = fi.id AND allocation.school_id = fi.school_id
            LEFT JOIN payments payment ON payment.id = allocation.payment_id AND payment.school_id = allocation.school_id
            WHERE fi.id = ? AND fi.school_id = ?
            GROUP BY fi.id, fi.paid_amount, fi.status, details.final_amount
            FOR UPDATE
        ');
        $item->execute([$itemId, $schoolId]);
        $row = $item->fetch();
        if (!$row) throw new \RuntimeException('Charge not found');

        $paid = round((float)$row['paid'], 2);
        $final = round((float)$row['final_amount'], 2);
        $status = $paid <= 0 ? 'UNPAID' : ($paid >= $final ? 'PAID' : 'PARTIAL');
        $before = ['paid_amount' => (float)$row['paid_amount'], 'status' => $row['status']];
        $after = ['paid_amount' => $paid, 'remaining_amount' => max(0, round($final - $paid, 2)), 'status' => $status];
        $pdo->prepare('UPDATE student_financial_items SET paid_amount = ?, status = ? WHERE id = ? AND school_id = ?')->execute([$paid, $status, $itemId, $schoolId]);
        $pdo->prepare('INSERT INTO student_financial_item_history (school_id, student_financial_item_id, action, before_data, after_data, reason, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$schoolId, $itemId, $action, json_encode($before), json_encode($after), $reason, $userId]);
    }
}
