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
                mf.status AS payment_status,
                pm.label AS payment_method_label
            FROM payments p
            INNER JOIN students s ON s.id = p.student_id
            INNER JOIN monthly_fees mf ON mf.id = p.monthly_fee_id
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

        $studentId = (int)($data['student_id'] ?? 0);
        if ($studentId <= 0) {
            return ['error' => 'student_id is required'];
        }

        $studentStmt = $pdo->prepare('SELECT id FROM students WHERE id = ? AND school_id = ? LIMIT 1');
        $studentStmt->execute([$studentId, $schoolId]);
        if (!$studentStmt->fetch()) {
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
            $pdo->beginTransaction();

            $resolved = $monthlyFeeService->resolveOrCreateFee($schoolId, $studentId, $monthlyFeeId, $monthLabel, $yearValue);
            if (isset($resolved['error'])) {
                $pdo->rollBack();
                return $resolved;
            }

            $fee = $resolved['fee'];
            $currentPaid = (float)($fee['amount_paid'] ?? 0);
            $totalAmount = (float)($fee['total_amount'] ?? 0);
            $remainingAmount = (float)($fee['remaining_amount'] ?? max(0, $totalAmount - $currentPaid));

            if ($remainingAmount <= 0) {
                $pdo->rollBack();
                return ['error' => 'Monthly fee already fully paid'];
            }

            if ($amountPaid > $remainingAmount) {
                $pdo->rollBack();
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

            $updateFee = $pdo->prepare('
                UPDATE monthly_fees
                SET amount_paid = ?, remaining_amount = ?, status = ?
                WHERE id = ?
            ');
            $updateFee->execute([$updatedPaid, $updatedRemaining, $updatedStatus, (int)$fee['id']]);

            $pdo->commit();

            return [
                'id' => $paymentId,
                'school_id' => $schoolId,
                'monthly_fee_id' => (int)$fee['id'],
                'monthly_fee_status' => $updatedStatus,
                'monthly_fee_remaining_amount' => $updatedRemaining,
                'message' => 'Payment created successfully',
            ];
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
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
            INNER JOIN monthly_fees mf ON mf.id = p.monthly_fee_id
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

        return $stmt->fetch() ?: false;
    }

    public function update(int $paymentId, array $data): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $payment = $this->getById($paymentId);
        if (!$payment) {
            return ['error' => 'Payment not found'];
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
            $pdo->beginTransaction();

            // Get the current monthly fee
            $feeStmt = $pdo->prepare('SELECT * FROM monthly_fees WHERE id = ? LIMIT 1');
            $feeStmt->execute([$payment['monthly_fee_id']]);
            $fee = $feeStmt->fetch();

            if (!$fee) {
                $pdo->rollBack();
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
                $pdo->rollBack();
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

            $pdo->commit();

            return [
                'id' => $paymentId,
                'monthly_fee_status' => $newStatus,
                'message' => 'Payment updated successfully',
            ];
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => 'Payment update failed'];
        }
    }

    public function delete(int $paymentId): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $payment = $this->getById($paymentId);
        if (!$payment) {
            return ['error' => 'Payment not found'];
        }

        try {
            $pdo->beginTransaction();

            // Get the monthly fee to update its status
            $feeStmt = $pdo->prepare('SELECT * FROM monthly_fees WHERE id = ? LIMIT 1');
            $feeStmt->execute([$payment['monthly_fee_id']]);
            $fee = $feeStmt->fetch();

            if (!$fee) {
                $pdo->rollBack();
                return ['error' => 'Monthly fee not found'];
            }

            // Delete the payment
            $deleteStmt = $pdo->prepare('DELETE FROM payments WHERE id = ?');
            $deleteStmt->execute([$paymentId]);

            // Recalculate monthly fee amounts
            $paymentsStmt = $pdo->prepare('
                SELECT COALESCE(SUM(amount_paid), 0) as total_paid
                FROM payments
                WHERE monthly_fee_id = ?
            ');
            $paymentsStmt->execute([$payment['monthly_fee_id']]);
            $result = $paymentsStmt->fetch();
            $totalPaid = round((float)($result['total_paid'] ?? 0), 2);

            $totalAmount = (float)$fee['total_amount'];
            $remaining = round(max(0, $totalAmount - $totalPaid), 2);

            $newStatus = 'UNPAID';
            if ($remaining <= 0) {
                $newStatus = 'PAID';
            } elseif ($totalPaid > 0) {
                $newStatus = 'PARTIAL';
            }

            $updateFee = $pdo->prepare('
                UPDATE monthly_fees
                SET amount_paid = ?, remaining_amount = ?, status = ?
                WHERE id = ?
            ');
            $updateFee->execute([$totalPaid, $remaining, $newStatus, $payment['monthly_fee_id']]);

            $pdo->commit();

            return ['id' => $paymentId, 'message' => 'Payment deleted successfully'];
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['error' => 'Payment deletion failed'];
        }
    }
}
