<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDOException;

class PaymentMethodService
{
    public function getAll(): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->query('SELECT id, code, label, status FROM payment_methods ORDER BY id ASC');
        return $stmt->fetchAll();
    }

    public function getById(int $methodId): array|false
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('SELECT id, code, label, status FROM payment_methods WHERE id = ? LIMIT 1');
        $stmt->execute([$methodId]);
        return $stmt->fetch() ?: false;
    }

    public function create(array $data): array
    {
        $pdo = Database::connect();

        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $label = trim((string)($data['label'] ?? ''));

        if ($code === '' || $label === '') {
            return ['error' => 'code and label are required'];
        }

        $status = strtoupper(trim((string)($data['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid status'];
        }

        try {
            // Check if code already exists
            $existsStmt = $pdo->prepare('SELECT id FROM payment_methods WHERE code = ? LIMIT 1');
            $existsStmt->execute([$code]);
            if ($existsStmt->fetch()) {
                return ['error' => 'Payment method code already exists'];
            }

            $insertStmt = $pdo->prepare('
                INSERT INTO payment_methods (code, label, status)
                VALUES (?, ?, ?)
            ');
            $insertStmt->execute([$code, $label, $status]);

            $methodId = (int)$pdo->lastInsertId();
            return [
                'id' => $methodId,
                'code' => $code,
                'label' => $label,
                'status' => $status,
                'message' => 'Payment method created successfully',
            ];
        } catch (PDOException) {
            return ['error' => 'Payment method creation failed'];
        }
    }

    public function update(int $methodId, array $data): array
    {
        $pdo = Database::connect();

        $method = $this->getById($methodId);
        if (!$method) {
            return ['error' => 'Payment method not found'];
        }

        $label = isset($data['label']) ? trim((string)$data['label']) : (string)$method['label'];
        if ($label === '') {
            return ['error' => 'label is required'];
        }

        $status = isset($data['status']) ? strtoupper(trim((string)$data['status'])) : (string)$method['status'];
        if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            return ['error' => 'Invalid status'];
        }

        try {
            $updateStmt = $pdo->prepare('
                UPDATE payment_methods
                SET label = ?, status = ?
                WHERE id = ?
            ');
            $updateStmt->execute([$label, $status, $methodId]);

            return [
                'id' => $methodId,
                'code' => $method['code'],
                'label' => $label,
                'status' => $status,
                'message' => 'Payment method updated successfully',
            ];
        } catch (PDOException) {
            return ['error' => 'Payment method update failed'];
        }
    }

    public function delete(int $methodId): array
    {
        $pdo = Database::connect();

        $method = $this->getById($methodId);
        if (!$method) {
            return ['error' => 'Payment method not found'];
        }

        // Check if method is used in any payments
        $usageStmt = $pdo->prepare('SELECT COUNT(*) as count FROM payments WHERE payment_method_id = ?');
        $usageStmt->execute([$methodId]);
        $result = $usageStmt->fetch();

        if ($result && (int)$result['count'] > 0) {
            return ['error' => 'Cannot delete payment method that is in use'];
        }

        try {
            $deleteStmt = $pdo->prepare('DELETE FROM payment_methods WHERE id = ?');
            $deleteStmt->execute([$methodId]);

            return ['id' => $methodId, 'message' => 'Payment method deleted successfully'];
        } catch (PDOException) {
            return ['error' => 'Payment method deletion failed'];
        }
    }
}
