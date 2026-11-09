<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;
use PDOException;

class StudentService
{
    public function getAll(array $filters = []): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $sql = '
            SELECT
                s.*,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                cl.group_name AS class_group_name,
                p.phone AS parent_phone
            FROM students s
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            LEFT JOIN parents p ON p.id = s.parent_id
        ';

        $conditions = [];
        $params = [];

        if ($role === 'super_admin') {
            $requestedSchoolId = isset($filters['school_id']) ? (int)$filters['school_id'] : 0;
            if ($requestedSchoolId > 0) {
                $conditions[] = 's.school_id = ?';
                $params[] = $requestedSchoolId;
            }
        } else {
            $conditions[] = 's.school_id = ?';
            $params[] = $schoolId;
        }

        $requestedClassLevelId = isset($filters['class_level_id']) ? (int)$filters['class_level_id'] : 0;
        if ($requestedClassLevelId > 0) {
            $conditions[] = 's.class_level_id = ?';
            $params[] = $requestedClassLevelId;
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

        $lastName = trim((string)($filters['last_name'] ?? ''));
        if ($lastName !== '') {
            $conditions[] = 'LOWER(s.last_name) LIKE ?';
            $params[] = '%' . strtolower($lastName) . '%';
        }

        $firstName = trim((string)($filters['first_name'] ?? ''));
        if ($firstName !== '') {
            $conditions[] = 'LOWER(s.first_name) LIKE ?';
            $params[] = '%' . strtolower($firstName) . '%';
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

        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $pdo->prepare($sql . ' ORDER BY s.last_name ASC, s.first_name ASC, s.id DESC');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getById(int $studentId): array|false
    {
        if ($studentId <= 0) {
            return false;
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();
        $sql = '
            SELECT
                s.*,
                COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                COALESCE(cl.group_name, s.class_name) AS class_group_name,
                p.first_name AS parent_first_name,
                p.last_name AS parent_last_name,
                p.phone AS parent_phone,
                p.email AS parent_email,
                e.id AS enrollment_id,
                e.enrollment_date,
                e.status AS enrollment_status,
                ay.name AS academic_year_name
            FROM students s
            LEFT JOIN class_levels cl ON cl.id = s.class_level_id
            LEFT JOIN parents p ON p.id = s.parent_id
            LEFT JOIN enrollments e ON e.student_id = s.id AND e.school_id = s.school_id
            LEFT JOIN academic_years ay ON ay.id = e.academic_year_id
            WHERE s.id = ?
        ';
        $params = [$studentId];
        if ($role !== 'super_admin') {
            $sql .= ' AND s.school_id = ?';
            $params[] = $schoolId;
        }
        $sql .= ' ORDER BY COALESCE(ay.is_current, 0) DESC, e.id DESC LIMIT 1';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $student = $stmt->fetch();
        if (!$student) {
            return false;
        }

        $financeStmt = $pdo->prepare('
            SELECT
                COALESCE(SUM(total_amount), 0) AS total_billed,
                COALESCE(SUM(amount_paid), 0) AS total_paid,
                COALESCE(SUM(remaining_amount), 0) AS total_remaining,
                SUM(CASE WHEN status = "UNPAID" THEN 1 ELSE 0 END) AS unpaid_count,
                SUM(CASE WHEN status = "PARTIAL" THEN 1 ELSE 0 END) AS partial_count
            FROM monthly_fees
            WHERE student_id = ? AND school_id = ?
        ');
        $financeStmt->execute([$studentId, (int)$student['school_id']]);
        $finance = $financeStmt->fetch() ?: [];

        $feesStmt = $pdo->prepare('
            SELECT id, month_label, year_value, total_amount, amount_paid, remaining_amount, status
            FROM monthly_fees
            WHERE student_id = ? AND school_id = ?
            ORDER BY year_value DESC, CAST(month_label AS UNSIGNED) DESC, id DESC
        ');
        $feesStmt->execute([$studentId, (int)$student['school_id']]);

        $receiptProjection = $this->paymentReceiptProjection($pdo);
        $paymentsStmt = $pdo->prepare('
            SELECT
                p.id, ' . $receiptProjection . ', p.amount_paid, p.payment_date, p.payment_method,
                p.created_at,
                mf.month_label, mf.year_value,
                pm.label AS payment_method_label
            FROM payments p
            INNER JOIN monthly_fees mf ON mf.id = p.monthly_fee_id
            LEFT JOIN payment_methods pm ON pm.id = p.payment_method_id
            WHERE p.student_id = ? AND p.school_id = ?
            ORDER BY p.payment_date DESC, p.id DESC
        ');
        $paymentsStmt->execute([$studentId, (int)$student['school_id']]);

        $guardiansStmt = $pdo->prepare('SELECT g.id,g.full_name,g.phone_primary,g.phone_secondary,g.email,sg.relationship_type,sg.is_financial_responsible FROM student_guardians sg INNER JOIN guardians g ON g.id=sg.guardian_id AND g.school_id=sg.school_id WHERE sg.student_id=? AND sg.school_id=? ORDER BY sg.is_financial_responsible DESC,g.id');
        $guardiansStmt->execute([$studentId, (int)$student['school_id']]);
        $chargesStmt = $pdo->prepare('SELECT fi.id,fi.paid_amount,fi.status,details.charge_category_id,details.original_amount,details.discount_type,details.discount_value,details.final_amount,details.due_date,GREATEST(0,details.final_amount-fi.paid_amount) remaining_amount,fi.label,fdi.family_discount_id,fdi.applied_discount_amount AS family_discount_amount,fd.reason AS family_discount_reason FROM student_financial_items fi INNER JOIN student_financial_item_details details ON details.student_financial_item_id=fi.id AND details.school_id=fi.school_id LEFT JOIN family_discount_impacts fdi ON fdi.student_financial_item_id=fi.id AND fdi.school_id=fi.school_id AND fdi.status="APPLIED" LEFT JOIN family_discounts fd ON fd.id=fdi.family_discount_id AND fd.status="ACTIVE" WHERE fi.student_id=? AND fi.school_id=? ORDER BY fi.id DESC');
        $chargesStmt->execute([$studentId, (int)$student['school_id']]);

        $siblings = [];
        if (!empty($student['parent_id'])) {
            $siblingsStmt = $pdo->prepare('
                SELECT
                    s.id, s.first_name, s.last_name, s.status,
                    COALESCE(cl.level_name, cl.name, s.class_level) AS class_level_name,
                    COALESCE(cl.group_name, s.class_name) AS class_group_name
                FROM students s
                LEFT JOIN class_levels cl ON cl.id = s.class_level_id
                WHERE s.parent_id = ? AND s.school_id = ? AND s.id != ?
                ORDER BY s.last_name ASC, s.first_name ASC
            ');
            $siblingsStmt->execute([(int)$student['parent_id'], (int)$student['school_id'], $studentId]);
            $siblings = $siblingsStmt->fetchAll();
        }

        $financialProjection = (new FinancialDataService())->getStudentFinancialSummary((int)$student['school_id'], $studentId);
        $financialPayments = (new FinancialDataService())->getStudentPayments((int)$student['school_id'], $studentId);

        return [
            'student' => $student,
            'parent' => [
                'id' => $student['parent_id'] ? (int)$student['parent_id'] : null,
                'first_name' => $student['parent_first_name'],
                'last_name' => $student['parent_last_name'],
                'name' => trim((string)$student['parent_first_name'] . ' ' . (string)$student['parent_last_name']) ?: $student['parent_name'],
                'phone' => $student['parent_phone'] ?: $student['phone'],
                'email' => $student['parent_email'],
            ],
            'siblings' => $siblings,
            'finance' => [
                'total_billed' => (float)$financialProjection['total_due'],
                'total_initial' => (float)$financialProjection['total_initial'],
                'total_discount' => (float)$financialProjection['total_discount'],
                'total_due' => (float)$financialProjection['total_due'],
                'total_paid' => (float)$financialProjection['total_paid'],
                'total_remaining' => (float)$financialProjection['total_remaining'],
                'total_overpaid' => (float)$financialProjection['total_overpaid'],
                'unpaid_count' => (int)$financialProjection['monthly']['unpaid_count'] + (int)$financialProjection['flexible']['unpaid_count'],
                'partial_count' => (int)$financialProjection['monthly']['partial_count'] + (int)$financialProjection['flexible']['partial_count'],
            ],
            'financial_items' => $financialProjection['items'],
            'financial_payments' => $financialPayments,
            'monthly_fees' => $feesStmt->fetchAll(),
            'payments' => $paymentsStmt->fetchAll(),
            'guardians' => $guardiansStmt->fetchAll(),
            'charges' => $chargesStmt->fetchAll(),
        ];
    }

    public function getPhotoStorageContext(int $studentId): array
    {
        if ($studentId <= 0) {
            return ['error' => 'Student not found'];
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();
        $stmt = $pdo->prepare('
            SELECT id, school_id, first_name, last_name, photo_path
            FROM students
            WHERE id = ?
            LIMIT 1
        ');
        $stmt->execute([$studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            return ['error' => 'Student not found'];
        }
        if ($role !== 'super_admin' && (int)$student['school_id'] !== $schoolId) {
            return ['error' => 'Forbidden'];
        }

        return [
            'id' => (int)$student['id'],
            'school_id' => (int)$student['school_id'],
            'first_name' => (string)$student['first_name'],
            'last_name' => (string)$student['last_name'],
            'photo_path' => $student['photo_path'] ?: null,
        ];
    }

    public function updatePhoto(int $studentId, string $photoPath): array
    {
        $student = $this->getPhotoStorageContext($studentId);
        if (isset($student['error'])) {
            return $student;
        }

        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare('UPDATE students SET photo_path = ? WHERE id = ? AND school_id = ?');
            $stmt->execute([$photoPath, $studentId, (int)$student['school_id']]);
        } catch (PDOException) {
            return ['error' => 'Student photo could not be linked to the student record'];
        }

        return ['id' => $studentId, 'photo_path' => $photoPath, 'message' => 'Student photo updated'];
    }

    public function create(array $data): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        if ($role === 'super_admin') {
            $schoolId = isset($data['school_id']) ? (int)$data['school_id'] : null;
        }

        if (!$schoolId || $schoolId <= 0) {
            return ['error' => 'School is required to create student'];
        }

        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        if ($firstName === '' || $lastName === '') {
            return ['error' => 'first_name and last_name are required'];
        }

        $parentPhone = trim((string)($data['parent_phone'] ?? ($data['phone'] ?? '')));
        if ($parentPhone === '') {
            return ['error' => 'parent_phone is required'];
        }

        $monthlyAmount = (float)($data['monthly_amount'] ?? 0);
        if ($monthlyAmount < 0) {
            return ['error' => 'monthly_amount must be positive'];
        }

        $discountPercent = (float)($data['discount_percent'] ?? 0);
        if ($discountPercent < 0 || $discountPercent > 100) {
            return ['error' => 'discount_percent must be between 0 and 100'];
        }

        $classLevel = $this->resolveClassLevel($pdo, $schoolId, $data);
        if (isset($classLevel['error'])) {
            return $classLevel;
        }

        $parentName = trim((string)($data['parent_name'] ?? ''));
        if ($parentName === '') {
            $parentName = 'Parent ' . $lastName;
        }

        $parentFirstName = trim((string)($data['parent_first_name'] ?? ''));
        $parentLastName = trim((string)($data['parent_last_name'] ?? ''));
        if ($parentFirstName === '' || $parentLastName === '') {
            $parts = preg_split('/\s+/', $parentName);
            $parentFirstName = $parentFirstName !== '' ? $parentFirstName : (string)($parts[0] ?? 'Parent');
            $parentLastName = $parentLastName !== '' ? $parentLastName : (string)($parts[1] ?? $lastName);
        }

        $schoolYear = trim((string)($data['school_year'] ?? ''));
        $massarCode = trim((string)($data['massar_code'] ?? ''));
        $dateOfBirth = $this->normalizeDate($data['date_of_birth'] ?? null);
        $gender = $this->nullable($data['gender'] ?? null);
        $className = $this->nullable($data['class_name'] ?? null);
        $address = $this->nullable($data['address'] ?? null);
        $status = strtoupper(trim((string)($data['status'] ?? 'REGISTERED')));
        $status = $status === 'ACTIVE' ? 'REGISTERED' : ($status === 'INACTIVE' ? 'ARCHIVED' : $status);
        if (!in_array($status, ['PRE_REGISTERED', 'REGISTERED', 'WAITING_LIST', 'CANCELLED', 'ARCHIVED'], true)) {
            return ['error' => 'Invalid student status'];
        }

        $parentId = $this->resolveOrCreateParent(
            $pdo,
            $schoolId,
            $parentFirstName,
            $parentLastName,
            $parentPhone,
            $data['parent_email'] ?? null
        );

        try {
            $stmt = $pdo->prepare('
                INSERT INTO students (
                    school_id, parent_id, massar_code, first_name, last_name, date_of_birth, gender, class_level, class_name, class_level_id,
                    parent_name, phone, address, monthly_amount, discount_percent, school_year, status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $schoolId,
                $parentId,
                $massarCode !== '' ? $massarCode : null,
                $firstName,
                $lastName,
                $dateOfBirth,
                $gender,
                $classLevel['name'],
                $className,
                $classLevel['id'],
                $parentName,
                $parentPhone,
                $address,
                $monthlyAmount,
                $discountPercent,
                $schoolYear !== '' ? $schoolYear : null,
                $status,
            ]);
        } catch (PDOException) {
            return ['error' => 'Student creation failed'];
        }

        return [
            'id' => (int)$pdo->lastInsertId(),
            'school_id' => $schoolId,
            'message' => 'Student created successfully',
        ];
    }

    public function update(int $studentId, array $data): array
    {
        if ($studentId <= 0) {
            return ['error' => 'Student not found'];
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $student = $this->findStudent($pdo, $studentId);
        if (!$student) {
            return ['error' => 'Student not found'];
        }

        if ($role !== 'super_admin' && (int)$student['school_id'] !== $schoolId) {
            return ['error' => 'Forbidden'];
        }

        $schoolId = (int)$student['school_id'];
        $firstName = trim((string)($data['first_name'] ?? $student['first_name']));
        $lastName = trim((string)($data['last_name'] ?? $student['last_name']));
        if ($firstName === '' || $lastName === '') {
            return ['error' => 'first_name and last_name are required'];
        }

        $parentPhone = trim((string)($data['parent_phone'] ?? ($data['phone'] ?? $student['phone'] ?? '')));
        if ($parentPhone === '') {
            return ['error' => 'parent_phone is required'];
        }

        $monthlyAmount = isset($data['monthly_amount']) ? (float)$data['monthly_amount'] : (float)$student['monthly_amount'];
        if ($monthlyAmount < 0) {
            return ['error' => 'monthly_amount must be positive'];
        }

        $discountPercent = isset($data['discount_percent']) ? (float)$data['discount_percent'] : (float)($student['discount_percent'] ?? 0);
        if ($discountPercent < 0 || $discountPercent > 100) {
            return ['error' => 'discount_percent must be between 0 and 100'];
        }

        $classLevelData = [
            'class_level_id' => $data['class_level_id'] ?? $student['class_level_id'],
            'class_level' => $data['class_level'] ?? $student['class_level'],
        ];
        $classLevel = $this->resolveClassLevel($pdo, $schoolId, $classLevelData);
        if (isset($classLevel['error'])) {
            return $classLevel;
        }

        $parentName = trim((string)($data['parent_name'] ?? $student['parent_name']));
        if ($parentName === '') {
            $parentName = 'Parent ' . $lastName;
        }

        $parentFirstName = trim((string)($data['parent_first_name'] ?? ''));
        $parentLastName = trim((string)($data['parent_last_name'] ?? ''));
        if ($parentFirstName === '' || $parentLastName === '') {
            $parts = preg_split('/\s+/', $parentName);
            $parentFirstName = $parentFirstName !== '' ? $parentFirstName : (string)($parts[0] ?? 'Parent');
            $parentLastName = $parentLastName !== '' ? $parentLastName : (string)($parts[1] ?? $lastName);
        }

        $parentId = $this->resolveOrCreateParent(
            $pdo,
            $schoolId,
            $parentFirstName,
            $parentLastName,
            $parentPhone,
            $data['parent_email'] ?? null
        );

        $dateOfBirth = array_key_exists('date_of_birth', $data)
            ? $this->normalizeDate($data['date_of_birth'])
            : ($student['date_of_birth'] ?: null);
        $gender = array_key_exists('gender', $data)
            ? $this->nullable($data['gender'])
            : ($student['gender'] ?? null);
        $className = array_key_exists('class_name', $data)
            ? $this->nullable($data['class_name'])
            : ($student['class_name'] ?? null);
        $address = array_key_exists('address', $data)
            ? $this->nullable($data['address'])
            : ($student['address'] ?? null);

        $schoolYear = array_key_exists('school_year', $data)
            ? trim((string)$data['school_year'])
            : (string)($student['school_year'] ?? '');

        $status = strtoupper(trim((string)($data['status'] ?? $student['status'] ?? 'REGISTERED')));
        $status = $status === 'ACTIVE' ? 'REGISTERED' : ($status === 'INACTIVE' ? 'ARCHIVED' : $status);
        if (!in_array($status, ['PRE_REGISTERED', 'REGISTERED', 'WAITING_LIST', 'CANCELLED', 'ARCHIVED'], true)) {
            return ['error' => 'Invalid student status'];
        }

        $stmt = $pdo->prepare('
            UPDATE students
            SET
                parent_id = ?,
                first_name = ?,
                last_name = ?,
                date_of_birth = ?,
                gender = ?,
                class_level = ?,
                class_name = ?,
                class_level_id = ?,
                parent_name = ?,
                phone = ?,
                address = ?,
                monthly_amount = ?,
                discount_percent = ?,
                school_year = ?,
                status = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $parentId,
            $firstName,
            $lastName,
            $dateOfBirth,
            $gender,
            $classLevel['name'],
            $className,
            $classLevel['id'],
            $parentName,
            $parentPhone,
            $address,
            $monthlyAmount,
            $discountPercent,
            $schoolYear !== '' ? $schoolYear : null,
            $status,
            $studentId,
        ]);

        return [
            'id' => $studentId,
            'message' => 'Student updated successfully',
        ];
    }

    public function delete(int $studentId): array
    {
        if ($studentId <= 0) {
            return ['error' => 'Student not found'];
        }

        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $student = $this->findStudent($pdo, $studentId);
        if (!$student) {
            return ['error' => 'Student not found'];
        }

        if ($role !== 'super_admin' && (int)$student['school_id'] !== $schoolId) {
            return ['error' => 'Forbidden'];
        }

        $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
        $stmt->execute([$studentId]);

        return [
            'id' => $studentId,
            'message' => 'Student deleted successfully',
        ];
    }

    public function parentSummary(?string $search = null): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();

        $conditions = [];
        $params = [];

        if ($role !== 'super_admin') {
            $conditions[] = 's.school_id = ?';
            $params[] = $schoolId;
        }

        if ($search !== null && trim($search) !== '') {
            $conditions[] = '(LOWER(s.parent_name) LIKE ? OR LOWER(COALESCE(s.phone, "")) LIKE ?)';
            $term = '%' . strtolower(trim($search)) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "
            SELECT
                s.parent_name,
                s.phone,
                COUNT(DISTINCT s.id) AS children_count,
                GROUP_CONCAT(DISTINCT CONCAT(s.first_name, ' ', s.last_name) ORDER BY s.last_name SEPARATOR ', ') AS children_names,
                COALESCE(SUM(CASE WHEN mf.status != 'PAID' THEN mf.remaining_amount ELSE 0 END), 0) AS total_remaining,
                SUM(CASE WHEN mf.status = 'UNPAID' THEN 1 ELSE 0 END) AS unpaid_months,
                SUM(CASE WHEN mf.status = 'PARTIAL' THEN 1 ELSE 0 END) AS partial_months
            FROM students s
            LEFT JOIN monthly_fees mf ON mf.student_id = s.id AND mf.school_id = s.school_id
            {$whereSql}
            GROUP BY s.parent_name, s.phone
            ORDER BY total_remaining DESC, children_count DESC, s.parent_name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function createModern(array $data): array
    {
        $pdo = Database::connect();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $result = $this->create($data);
            if (isset($result['error'])) throw new \DomainException($result['error']);
            $this->syncModernProfile($pdo, (int)$result['id'], (int)$result['school_id'], $data, true);
            if ($ownsTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return ['error' => $exception instanceof \DomainException ? $exception->getMessage() : 'Student creation failed'];
        }
    }

    public function updateModern(int $studentId, array $data): array
    {
        $pdo = Database::connect();
        $student = $this->findStudent($pdo, $studentId);
        if (!$student) return ['error' => 'Student not found'];
        [$role, $scopeSchool] = $this->authScope();
        if ($role !== 'super_admin' && (int)$student['school_id'] !== (int)$scopeSchool) return ['error' => 'Forbidden'];
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $result = $this->update($studentId, $data);
            if (isset($result['error'])) throw new \DomainException($result['error']);
            $this->syncModernProfile($pdo, $studentId, (int)$student['school_id'], $data, false);
            if ($ownsTransaction) $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return ['error' => $exception instanceof \DomainException ? $exception->getMessage() : 'Student update failed'];
        }
    }

    private function syncModernProfile(PDO $pdo, int $studentId, int $schoolId, array $data, bool $creating): void
    {
        $yearId = $this->activeAcademicYearId($pdo, $schoolId);
        if (!$yearId) throw new \DomainException('No active academic year configured for this school');
        $student = $this->findStudent($pdo, $studentId);
        $pdo->prepare('UPDATE students SET internal_number=?, massar_code=?, cne=?, first_name_ar=?, last_name_ar=?, uses_transport=?, academic_year_id=? WHERE id=? AND school_id=?')->execute([
            $this->nullable($data['internal_number'] ?? $student['internal_number'] ?? null),
            $this->nullable($data['massar_code'] ?? $student['massar_code'] ?? null),
            $this->nullable($data['cne'] ?? $student['cne'] ?? null),
            $this->nullable($data['first_name_ar'] ?? $student['first_name_ar'] ?? null),
            $this->nullable($data['last_name_ar'] ?? $student['last_name_ar'] ?? null),
            !empty($data['uses_transport']) ? 1 : 0,
            $yearId, $studentId, $schoolId,
        ]);
        if (!empty($student['class_level_id'])) {
            $pdo->prepare('INSERT INTO enrollments (school_id,academic_year_id,student_id,class_level_id,enrollment_date,status) VALUES (?,?,?,?,?,"ACTIVE") ON DUPLICATE KEY UPDATE class_level_id=VALUES(class_level_id),status="ACTIVE"')->execute([$schoolId, $yearId, $studentId, (int)$student['class_level_id'], date('Y-m-d')]);
        }
        $this->syncGuardians($pdo, $studentId, $schoolId, (array)($data['guardians'] ?? []), $data['family_id'] ?? null);
        foreach ((array)($data['charges'] ?? []) as $charge) {
            if (!empty($charge['id'])) continue;
            $this->createModernCharge($pdo, $studentId, $schoolId, $yearId, (array)$charge);
        }
    }

    private function activeAcademicYearId(PDO $pdo, int $schoolId): ?int
    {
        $stmt = $pdo->prepare('SELECT ay.id FROM school_settings settings INNER JOIN academic_years ay ON ay.id=CAST(settings.setting_value AS UNSIGNED) AND ay.school_id=settings.school_id WHERE settings.school_id=? AND settings.setting_key="active_academic_year_id"');
        $stmt->execute([$schoolId]); $id = (int)($stmt->fetchColumn() ?: 0);
        return $id ?: null;
    }

    private function syncGuardians(PDO $pdo, int $studentId, int $schoolId, array $guardians, mixed $familyId): void
    {
        $guardians = array_values(array_filter($guardians, static fn ($guardian) => trim((string)($guardian['full_name'] ?? '')) !== ''));
        if (!$guardians) return;
        if (count($guardians) > 3) throw new \DomainException('A student can have at most three guardians');
        $familyId = (int)$familyId;
        if ($familyId) {
            $check = $pdo->prepare('SELECT id FROM families WHERE id=? AND school_id=?'); $check->execute([$familyId, $schoolId]);
            if (!$check->fetch()) throw new \DomainException('Invalid family');
        } else {
            $reference = 'FAM-' . $schoolId . '-' . strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare('INSERT INTO families (school_id,family_reference,family_name) VALUES (?,?,?)')->execute([$schoolId, $reference, trim((string)$guardians[0]['full_name'])]);
            $familyId = (int)$pdo->lastInsertId();
        }
        $guardianIds = [];
        foreach ($guardians as $index => $guardian) {
            $guardianId = (int)($guardian['id'] ?? 0);
            if ($guardianId) {
                $check = $pdo->prepare('SELECT id FROM guardians WHERE id=? AND school_id=?'); $check->execute([$guardianId, $schoolId]);
                if (!$check->fetch()) throw new \DomainException('Invalid guardian');
            } else {
                $phone = $this->nullable($guardian['phone_primary'] ?? null);
                $email = $this->nullable($guardian['email'] ?? null);
                $existing = $pdo->prepare('SELECT id FROM guardians WHERE school_id=? AND ((? IS NOT NULL AND phone_primary=?) OR (? IS NOT NULL AND email=?)) LIMIT 1');
                $existing->execute([$schoolId, $phone, $phone, $email, $email]); $guardianId = (int)($existing->fetchColumn() ?: 0);
                if (!$guardianId) {
                    $pdo->prepare('INSERT INTO guardians (school_id,full_name,phone_primary,phone_secondary,email) VALUES (?,?,?,?,?)')->execute([$schoolId, trim((string)$guardian['full_name']), $phone, $this->nullable($guardian['phone_secondary'] ?? null), $email]);
                    $guardianId = (int)$pdo->lastInsertId();
                }
            }
            $relationship = trim((string)($guardian['relationship_type'] ?? 'Responsable'));
            $pdo->prepare('INSERT IGNORE INTO student_guardians (student_id,guardian_id,school_id,relationship_type,is_financial_responsible,is_emergency_contact) VALUES (?,?,?,?,?,?)')->execute([$studentId, $guardianId, $schoolId, $relationship, $index === 0 ? 1 : 0, $index === 0 ? 1 : 0]);
            $pdo->prepare('UPDATE student_guardians SET relationship_type=?,is_financial_responsible=?,is_emergency_contact=? WHERE student_id=? AND guardian_id=? AND school_id=?')->execute([$relationship, $index === 0 ? 1 : 0, $index === 0 ? 1 : 0, $studentId, $guardianId, $schoolId]);
            if ($index === 0) $pdo->prepare('UPDATE families SET primary_guardian_id=? WHERE id=? AND school_id=?')->execute([$guardianId, $familyId, $schoolId]);
            $guardianIds[] = $guardianId;
        }
        $placeholders = implode(',', array_fill(0, count($guardianIds), '?'));
        $pdo->prepare("DELETE FROM student_guardians WHERE student_id=? AND school_id=? AND guardian_id NOT IN ({$placeholders})")
            ->execute([$studentId, $schoolId, ...$guardianIds]);
        $pdo->prepare('UPDATE students SET family_id=? WHERE id=? AND school_id=?')->execute([$familyId, $studentId, $schoolId]);
    }

    private function createModernCharge(PDO $pdo, int $studentId, int $schoolId, int $yearId, array $charge): void
    {
        $categoryId = (int)($charge['charge_category_id'] ?? 0);
        $category = $pdo->prepare('SELECT id,code,label FROM charge_categories WHERE id=? AND school_id=? AND status="ACTIVE"'); $category->execute([$categoryId, $schoolId]); $category = $category->fetch();
        if (!$category) throw new \DomainException('Invalid charge category');
        $amount = round((float)($charge['original_amount'] ?? 0), 2); $type = $charge['discount_type'] ?? null; $value = round((float)($charge['discount_value'] ?? 0), 2);
        if ($amount < 0 || !in_array($type, [null, '', 'FIXED', 'PERCENTAGE'], true) || $value < 0 || ($type === 'PERCENTAGE' && $value > 100)) throw new \DomainException('Invalid charge discount');
        $discount = $type === 'PERCENTAGE' ? round($amount * $value / 100, 2) : ($type === 'FIXED' ? $value : 0);
        if ($discount > $amount) throw new \DomainException('The discount cannot exceed the charge amount');
        $final = round($amount - $discount, 2); $due = $this->normalizeDate($charge['due_date'] ?? null);
        $pdo->prepare('INSERT INTO student_financial_items (school_id,student_id,academic_year_id,item_code,label,unit_amount,total_amount,discount_amount,paid_amount,status,created_by) VALUES (?,?,?,?,?,?,?, ?,0,"UNPAID",?)')->execute([$schoolId, $studentId, $yearId, $category['code'] . '_' . bin2hex(random_bytes(3)), $this->nullable($charge['label'] ?? null) ?: $category['label'], $amount, $final, $discount, (int)(Request::get('auth_user', [])['id'] ?? 0) ?: null]);
        $itemId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO student_financial_item_details (student_financial_item_id,school_id,charge_category_id,due_date,original_amount,discount_type,discount_value,final_amount,modified_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$itemId,$schoolId,$category['id'],$due,$amount,$type ?: null,$type ? $value : null,$final,(int)(Request::get('auth_user', [])['id'] ?? 0) ?: null]);
        $pdo->prepare('INSERT INTO student_financial_item_history (school_id,student_financial_item_id,action,after_data,changed_by) VALUES (?,?,"CREATED",?,?)')->execute([$schoolId,$itemId,json_encode(['final_amount'=>$final]),(int)(Request::get('auth_user', [])['id'] ?? 0) ?: null]);
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }

    private function normalizeDate(mixed $date): ?string
    {
        $value = trim((string)$date);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function resolveClassLevel(PDO $pdo, int $schoolId, array $data): array
    {
        $classLevelId = isset($data['class_level_id']) ? (int)$data['class_level_id'] : 0;
        if ($classLevelId > 0) {
            $stmt = $pdo->prepare('
                SELECT id, name
                FROM class_levels
                WHERE id = ? AND school_id = ?
                LIMIT 1
            ');
            $stmt->execute([$classLevelId, $schoolId]);
            $row = $stmt->fetch();
            if (!$row) {
                return ['error' => 'Invalid class_level_id'];
            }
            return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }

        $classLevelName = trim((string)($data['class_level'] ?? ''));
        if ($classLevelName === '') {
            return ['error' => 'class_level_id or class_level is required'];
        }

        $find = $pdo->prepare('
            SELECT id, name
            FROM class_levels
            WHERE school_id = ? AND name = ?
            LIMIT 1
        ');
        $find->execute([$schoolId, $classLevelName]);
        $existing = $find->fetch();
        if ($existing) {
            return ['id' => (int)$existing['id'], 'name' => (string)$existing['name']];
        }

        $insert = $pdo->prepare('
            INSERT INTO class_levels (school_id, name, status)
            VALUES (?, ?, "ACTIVE")
        ');
        $insert->execute([$schoolId, $classLevelName]);

        return ['id' => (int)$pdo->lastInsertId(), 'name' => $classLevelName];
    }

    private function resolveOrCreateParent(
        PDO $pdo,
        int $schoolId,
        string $firstName,
        string $lastName,
        string $phone,
        mixed $email
    ): int {
        $emailValue = trim((string)$email);
        $stmt = $pdo->prepare('
            SELECT id
            FROM parents
            WHERE school_id = ? AND phone = ?
            LIMIT 1
        ');
        $stmt->execute([$schoolId, $phone]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int)$existing['id'];
        }

        $insert = $pdo->prepare('
            INSERT INTO parents (school_id, first_name, last_name, phone, email)
            VALUES (?, ?, ?, ?, ?)
        ');
        $insert->execute([
            $schoolId,
            $firstName,
            $lastName,
            $phone,
            $emailValue !== '' ? $emailValue : null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    private function findStudent(PDO $pdo, int $studentId): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
        $stmt->execute([$studentId]);
        return $stmt->fetch();
    }

    private function paymentReceiptProjection(PDO $pdo): string
    {
        $stmt = $pdo->query('
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = "payments"
              AND column_name IN ("receipt_number", "remaining_after_payment")
        ');
        $hasProfessionalReceiptColumns = (int)$stmt->fetchColumn() === 2;

        if ($hasProfessionalReceiptColumns) {
            return 'p.receipt_number, p.remaining_after_payment';
        }

        return 'CONCAT("REC-", LPAD(p.id, 6, "0")) AS receipt_number, NULL AS remaining_after_payment';
    }

    private function nullable(mixed $value): ?string
    {
        $str = trim((string)$value);
        return $str === '' ? null : $str;
    }
}
