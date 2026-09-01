<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;

class MonthlyFeeService
{
    public function getAll(array $filters = []): array
    {
        [$role, $schoolId] = $this->authScope();
        $pdo = Database::connect();

        $conditions = [];
        $params = [];

        if ($role !== 'super_admin') {
            $conditions[] = 'mf.school_id = ?';
            $params[] = $schoolId;
        }

        if (!empty($filters['month_label'])) {
            $conditions[] = 'mf.month_label = ?';
            $params[] = (string)$filters['month_label'];
        }

        if (!empty($filters['year_value'])) {
            $conditions[] = 'mf.year_value = ?';
            $params[] = (int)$filters['year_value'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = 'mf.status = ?';
            $params[] = (string)$filters['status'];
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $conditions[] = '(
                LOWER(s.first_name) LIKE ?
                OR LOWER(s.last_name) LIKE ?
                OR LOWER(CONCAT(s.first_name, " ", s.last_name)) LIKE ?
                OR LOWER(CONCAT(s.last_name, " ", s.first_name)) LIKE ?
            )';
            $term = '%' . strtolower($search) . '%';
            array_push($params, $term, $term, $term, $term);
        }

        $classLevel = trim((string)($filters['class_level'] ?? ''));
        if ($classLevel !== '') {
            $conditions[] = 'LOWER(COALESCE(cl.level_name, cl.name, s.class_level)) LIKE ?';
            $params[] = '%' . strtolower($classLevel) . '%';
        }

        $className = trim((string)($filters['class_name'] ?? ''));
        if ($className !== '') {
            $conditions[] = 'LOWER(COALESCE(cl.group_name, s.class_name, "")) LIKE ?';
            $params[] = '%' . strtolower($className) . '%';
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "
            SELECT
                mf.*,
                s.first_name,
                s.last_name,
                s.parent_name,
                s.phone,
                s.school_year,
                s.discount_percent,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                COALESCE(cl.group_name, s.class_name) AS class_group_name
            FROM monthly_fees mf
            INNER JOIN students s ON s.id = mf.student_id
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            {$whereSql}
            ORDER BY mf.year_value ASC, FIELD(LPAD(mf.month_label, 2, '0'), '09', '10', '11', '12', '01', '02', '03', '04', '05', '06'), s.last_name ASC, s.first_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function generateForMonth(string $monthLabel, int $yearValue): array
    {
        [$role, $schoolId] = $this->authScope();
        if ($role === 'super_admin' && !$schoolId) {
            return ['error' => 'school_id is required for super_admin'];
        }

        $pdo = Database::connect();

        $studentsStmt = $pdo->prepare('
            SELECT id, monthly_amount, discount_percent, status
            FROM students
            WHERE school_id = ?
        ');
        $studentsStmt->execute([$schoolId]);
        $students = $studentsStmt->fetchAll();

        $created = 0;
        foreach ($students as $student) {
            if (($student['status'] ?? 'ACTIVE') !== 'ACTIVE') {
                continue;
            }

            $existsStmt = $pdo->prepare('SELECT id FROM monthly_fees WHERE school_id = ? AND student_id = ? AND month_label = ? AND year_value = ? LIMIT 1');
            $existsStmt->execute([$schoolId, $student['id'], $monthLabel, $yearValue]);
            if ($existsStmt->fetch()) {
                continue;
            }

            $amount = $this->netAmountFromStudent((float)$student['monthly_amount'], (float)($student['discount_percent'] ?? 0));
            $insert = $pdo->prepare('
                INSERT INTO monthly_fees (school_id, student_id, month_label, year_value, total_amount, amount_paid, remaining_amount, status)
                VALUES (?, ?, ?, ?, ?, 0, ?, "UNPAID")
            ');
            $insert->execute([$schoolId, $student['id'], $monthLabel, $yearValue, $amount, $amount]);
            $created++;
        }

        return [
            'school_id' => $schoolId,
            'month_label' => $monthLabel,
            'year_value' => $yearValue,
            'created_fees' => $created,
            'message' => 'Monthly fees generated',
        ];
    }

    public function getUnpaid(array $filters = []): array
    {
        [$role, $schoolId] = $this->authScope();
        $pdo = Database::connect();

        $sql = '
            SELECT
                mf.id,
                mf.school_id,
                mf.student_id,
                mf.month_label,
                mf.year_value,
                mf.total_amount,
                mf.amount_paid,
                mf.remaining_amount,
                mf.status,
                s.first_name,
                s.last_name,
                s.parent_name,
                s.phone,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                COALESCE(cl.group_name, s.class_name) AS class_group_name,
                CASE
                    WHEN mf.month_label REGEXP "^[0-9]{1,2}$" THEN
                        STR_TO_DATE(
                            CONCAT(
                                mf.year_value, "-",
                                LPAD(mf.month_label, 2, "0"), "-",
                                LPAD(
                                    LEAST(
                                        DAY(s.created_at),
                                        DAY(LAST_DAY(STR_TO_DATE(CONCAT(mf.year_value, "-", LPAD(mf.month_label, 2, "0"), "-01"), "%Y-%m-%d")))
                                    ),
                                    2,
                                    "0"
                                )
                            ),
                            "%Y-%m-%d"
                        )
                    ELSE NULL
                END AS due_date,
                CASE
                    WHEN mf.month_label REGEXP "^[0-9]{1,2}$" THEN
                        GREATEST(
                            DATEDIFF(
                                CURDATE(),
                                STR_TO_DATE(
                                    CONCAT(
                                        mf.year_value, "-",
                                        LPAD(mf.month_label, 2, "0"), "-",
                                        LPAD(
                                            LEAST(
                                                DAY(s.created_at),
                                                DAY(LAST_DAY(STR_TO_DATE(CONCAT(mf.year_value, "-", LPAD(mf.month_label, 2, "0"), "-01"), "%Y-%m-%d")))
                                            ),
                                            2,
                                            "0"
                                        )
                                    ),
                                    "%Y-%m-%d"
                                )
                            ),
                            0
                        )
                    ELSE NULL
                END AS days_late
            FROM monthly_fees mf
            INNER JOIN students s ON s.id = mf.student_id
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            WHERE mf.status != "PAID"
        ';

        $sql .= ' AND (
            CASE
                WHEN mf.month_label REGEXP "^[0-9]{1,2}$" THEN
                    STR_TO_DATE(
                        CONCAT(
                            mf.year_value, "-",
                            LPAD(mf.month_label, 2, "0"), "-",
                            LPAD(
                                LEAST(
                                    DAY(s.created_at),
                                    DAY(LAST_DAY(STR_TO_DATE(CONCAT(mf.year_value, "-", LPAD(mf.month_label, 2, "0"), "-01"), "%Y-%m-%d")))
                                ),
                                2,
                                "0"
                            )
                        ),
                        "%Y-%m-%d"
                    )
                ELSE NULL
            END
        ) <= CURDATE()';

        $params = [];
        if ($role !== 'super_admin') {
            $sql .= ' AND mf.school_id = ?';
            $params[] = $schoolId;
        }

        if (!empty($filters['month_label'])) {
            $sql .= ' AND mf.month_label = ?';
            $params[] = (string)$filters['month_label'];
        }

        if (!empty($filters['year_value'])) {
            $sql .= ' AND mf.year_value = ?';
            $params[] = (int)$filters['year_value'];
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $sql .= ' AND (
                LOWER(s.first_name) LIKE ?
                OR LOWER(s.last_name) LIKE ?
                OR LOWER(CONCAT(s.first_name, " ", s.last_name)) LIKE ?
                OR LOWER(CONCAT(s.last_name, " ", s.first_name)) LIKE ?
            )';
            $term = '%' . strtolower($search) . '%';
            array_push($params, $term, $term, $term, $term);
        }

        $classLevel = trim((string)($filters['class_level'] ?? ''));
        if ($classLevel !== '') {
            $sql .= ' AND LOWER(COALESCE(cl.level_name, cl.name, s.class_level)) LIKE ?';
            $params[] = '%' . strtolower($classLevel) . '%';
        }

        $className = trim((string)($filters['class_name'] ?? ''));
        if ($className !== '') {
            $sql .= ' AND LOWER(COALESCE(cl.group_name, s.class_name, "")) LIKE ?';
            $params[] = '%' . strtolower($className) . '%';
        }

        $stmt = $pdo->prepare($sql . ' ORDER BY due_date ASC, mf.year_value ASC, FIELD(LPAD(mf.month_label, 2, "0"), "09", "10", "11", "12", "01", "02", "03", "04", "05", "06"), s.last_name ASC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function resolveOrCreateFee(int $schoolId, int $studentId, ?int $monthlyFeeId, ?string $monthLabel, ?int $yearValue): array
    {
        $pdo = Database::connect();

        if ($monthlyFeeId !== null && $monthlyFeeId > 0) {
            $stmt = $pdo->prepare('
                SELECT id, total_amount, amount_paid, remaining_amount, status
                FROM monthly_fees
                WHERE id = ? AND school_id = ? AND student_id = ?
                LIMIT 1
            ');
            $stmt->execute([$monthlyFeeId, $schoolId, $studentId]);
            $fee = $stmt->fetch();
            if (!$fee) {
                return ['error' => 'Monthly fee not found for this school/student'];
            }
            return ['fee' => $fee];
        }

        if (!$monthLabel || !$yearValue) {
            return ['error' => 'monthly_fee_id or (month_label and year_value) is required'];
        }

        $exists = $pdo->prepare('
            SELECT id, total_amount, amount_paid, remaining_amount, status
            FROM monthly_fees
            WHERE school_id = ? AND student_id = ? AND month_label = ? AND year_value = ?
            LIMIT 1
        ');
        $exists->execute([$schoolId, $studentId, $monthLabel, $yearValue]);
        $fee = $exists->fetch();
        if ($fee) {
            return ['fee' => $fee];
        }

        $studentStmt = $pdo->prepare('
            SELECT monthly_amount, discount_percent, status
            FROM students
            WHERE id = ? AND school_id = ?
            LIMIT 1
        ');
        $studentStmt->execute([$studentId, $schoolId]);
        $student = $studentStmt->fetch();
        if (!$student) {
            return ['error' => 'Student not found for this school'];
        }

        if (($student['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            return ['error' => 'Inactive student cannot receive monthly fee'];
        }

        $amount = $this->netAmountFromStudent((float)$student['monthly_amount'], (float)($student['discount_percent'] ?? 0));
        $insert = $pdo->prepare('
            INSERT INTO monthly_fees (school_id, student_id, month_label, year_value, total_amount, amount_paid, remaining_amount, status)
            VALUES (?, ?, ?, ?, ?, 0, ?, "UNPAID")
        ');
        $insert->execute([$schoolId, $studentId, $monthLabel, $yearValue, $amount, $amount]);

        return [
            'fee' => [
                'id' => (int)$pdo->lastInsertId(),
                'total_amount' => $amount,
                'amount_paid' => 0,
                'remaining_amount' => $amount,
                'status' => 'UNPAID',
            ],
        ];
    }

    public function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }

    private function netAmountFromStudent(float $monthlyAmount, float $discountPercent): float
    {
        $discountPercent = max(0.0, min(100.0, $discountPercent));
        $net = $monthlyAmount * ((100.0 - $discountPercent) / 100.0);
        return round(max(0.0, $net), 2);
    }

    public function getById(int $monthlyFeeId): array|false
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $sql = '
            SELECT
                mf.*,
                s.first_name,
                s.last_name,
                s.parent_name,
                s.phone,
                s.school_year,
                s.discount_percent,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                COALESCE(cl.group_name, s.class_name) AS class_group_name
            FROM monthly_fees mf
            INNER JOIN students s ON s.id = mf.student_id
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            WHERE mf.id = ?
        ';

        if ($role !== 'super_admin') {
            $sql .= ' AND mf.school_id = ?';
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute([$monthlyFeeId, $schoolId]);
        } else {
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute([$monthlyFeeId]);
        }

        return $stmt->fetch() ?: false;
    }

    public function create(array $data): array
    {
        [$role, $schoolId] = $this->authScope();
        $pdo = Database::connect();

        if ($role === 'super_admin') {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
        }

        if (!$schoolId) {
            return ['error' => 'school_id is required'];
        }

        $studentId = (int)($data['student_id'] ?? 0);
        if ($studentId <= 0) {
            return ['error' => 'student_id is required'];
        }

        $monthLabel = trim((string)($data['month_label'] ?? ''));
        $yearValue = (int)($data['year_value'] ?? 0);

        if ($monthLabel === '' || $yearValue <= 0) {
            return ['error' => 'month_label and year_value are required'];
        }

        // Check if student exists
        $studentStmt = $pdo->prepare('SELECT id FROM students WHERE id = ? AND school_id = ? LIMIT 1');
        $studentStmt->execute([$studentId, $schoolId]);
        if (!$studentStmt->fetch()) {
            return ['error' => 'Student not found for this school'];
        }

        // Check if fee already exists
        $existsStmt = $pdo->prepare('
            SELECT id FROM monthly_fees
            WHERE school_id = ? AND student_id = ? AND month_label = ? AND year_value = ?
            LIMIT 1
        ');
        $existsStmt->execute([$schoolId, $studentId, $monthLabel, $yearValue]);
        if ($existsStmt->fetch()) {
            return ['error' => 'Monthly fee already exists for this student/month/year'];
        }

        $totalAmount = round((float)($data['total_amount'] ?? 0), 2);
        if ($totalAmount <= 0) {
            return ['error' => 'total_amount must be greater than 0'];
        }

        $amountPaid = isset($data['amount_paid']) ? round((float)$data['amount_paid'], 2) : 0;
        if ($amountPaid < 0 || $amountPaid > $totalAmount) {
            return ['error' => 'amount_paid must be between 0 and total_amount'];
        }

        $remainingAmount = round($totalAmount - $amountPaid, 2);
        $status = 'UNPAID';
        if ($remainingAmount <= 0) {
            $status = 'PAID';
        } elseif ($amountPaid > 0) {
            $status = 'PARTIAL';
        }

        try {
            $insertStmt = $pdo->prepare('
                INSERT INTO monthly_fees (
                    school_id, student_id, month_label, year_value,
                    total_amount, amount_paid, remaining_amount, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertStmt->execute([
                $schoolId,
                $studentId,
                $monthLabel,
                $yearValue,
                $totalAmount,
                $amountPaid,
                $remainingAmount,
                $status,
            ]);

            $feeId = (int)$pdo->lastInsertId();
            return [
                'id' => $feeId,
                'school_id' => $schoolId,
                'student_id' => $studentId,
                'month_label' => $monthLabel,
                'year_value' => $yearValue,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'remaining_amount' => $remainingAmount,
                'status' => $status,
                'message' => 'Monthly fee created successfully',
            ];
        } catch (\Throwable) {
            return ['error' => 'Monthly fee creation failed'];
        }
    }

    public function update(int $monthlyFeeId, array $data): array
    {
        $pdo = Database::connect();

        $fee = $this->getById($monthlyFeeId);
        if (!$fee) {
            return ['error' => 'Monthly fee not found'];
        }

        $totalAmount = isset($data['total_amount']) ? round((float)$data['total_amount'], 2) : (float)$fee['total_amount'];
        if ($totalAmount <= 0) {
            return ['error' => 'total_amount must be greater than 0'];
        }

        $amountPaid = isset($data['amount_paid']) ? round((float)$data['amount_paid'], 2) : (float)$fee['amount_paid'];
        if ($amountPaid < 0 || $amountPaid > $totalAmount) {
            return ['error' => 'amount_paid must be between 0 and total_amount'];
        }

        $remainingAmount = round($totalAmount - $amountPaid, 2);
        $status = $data['status'] ?? 'UNPAID';

        if (!in_array($status, ['UNPAID', 'PARTIAL', 'PAID'], true)) {
            return ['error' => 'Invalid status'];
        }

        try {
            $updateStmt = $pdo->prepare('
                UPDATE monthly_fees
                SET total_amount = ?, amount_paid = ?, remaining_amount = ?, status = ?
                WHERE id = ?
            ');
            $updateStmt->execute([$totalAmount, $amountPaid, $remainingAmount, $status, $monthlyFeeId]);

            return [
                'id' => $monthlyFeeId,
                'total_amount' => $totalAmount,
                'amount_paid' => $amountPaid,
                'remaining_amount' => $remainingAmount,
                'status' => $status,
                'message' => 'Monthly fee updated successfully',
            ];
        } catch (\Throwable) {
            return ['error' => 'Monthly fee update failed'];
        }
    }

    public function delete(int $monthlyFeeId): array
    {
        $pdo = Database::connect();

        $fee = $this->getById($monthlyFeeId);
        if (!$fee) {
            return ['error' => 'Monthly fee not found'];
        }

        // Check if there are any payments for this fee
        $paymentCount = $pdo->prepare('SELECT COUNT(*) as count FROM payments WHERE monthly_fee_id = ?');
        $paymentCount->execute([$monthlyFeeId]);
        $result = $paymentCount->fetch();

        if ($result && (int)$result['count'] > 0) {
            return ['error' => 'Cannot delete monthly fee with existing payments'];
        }

        try {
            $deleteStmt = $pdo->prepare('DELETE FROM monthly_fees WHERE id = ?');
            $deleteStmt->execute([$monthlyFeeId]);

            return ['id' => $monthlyFeeId, 'message' => 'Monthly fee deleted successfully'];
        } catch (\Throwable) {
            return ['error' => 'Monthly fee deletion failed'];
        }
    }
}
