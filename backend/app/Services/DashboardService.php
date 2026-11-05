<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;

class DashboardService
{
    public function stats(): array
    {
        $pdo = Database::connect();
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;

        $isSuperAdmin = ($role === 'super_admin');
        $params = $isSuperAdmin ? [] : [$schoolId];
        $whereSchool = $isSuperAdmin ? '' : ' WHERE school_id = ?';
        $whereSchoolAnd = $isSuperAdmin ? ' WHERE ' : ' WHERE school_id = ? AND ';

        $schoolIds = $isSuperAdmin
            ? array_map('intval', $pdo->query('SELECT id FROM schools')->fetchAll(\PDO::FETCH_COLUMN))
            : ($schoolId > 0 ? [$schoolId] : []);
        $projectionService = new FinancialDataService();
        $financial = $this->financialDashboardData($projectionService, $schoolIds);

        $stmtStudents = $pdo->prepare("SELECT COUNT(*) AS total FROM students{$whereSchool}");
        $stmtStudents->execute($params);
        $studentsCount = (int)($stmtStudents->fetch()['total'] ?? 0);

        $stmtPayments = $pdo->prepare("SELECT COUNT(*) AS total FROM payments{$whereSchoolAnd}status = 'COMPLETED'");
        $stmtPayments->execute($params);
        $paymentsCount = (int)($stmtPayments->fetch()['total'] ?? 0);

        $coverageRate = $financial['total_due'] > 0
            ? round(($financial['total_collected'] / $financial['total_due']) * 100, 2)
            : 0.0;

        $recentSql = "
            SELECT
                p.id,
                p.student_id,
                p.amount_paid,
                p.payment_date,
                p.payment_method,
                CONCAT(s.first_name, ' ', s.last_name) AS student_name
            FROM payments p
            JOIN students s ON s.id = p.student_id
            " . ($isSuperAdmin ? "WHERE p.status = 'COMPLETED'" : "WHERE p.school_id = ? AND p.status = 'COMPLETED'") . "
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT 5
        ";
        $stmtRecent = $pdo->prepare($recentSql);
        $stmtRecent->execute($params);
        $recentPayments = $stmtRecent->fetchAll();

        return [
            'total_due' => $financial['total_due'],
            'total_collected' => $financial['total_collected'],
            'total_unpaid' => $financial['total_unpaid'],
            'total_overdue' => $financial['total_overdue'],
            'unpaid_breakdown' => $financial['unpaid_breakdown'],
            'late_students' => $financial['late_students'],
            'students_count' => $studentsCount,
            'payments_count' => $paymentsCount,
            'coverage_rate' => $coverageRate,
            'recent_payments' => $recentPayments,
            'top_unpaid_students' => $financial['top_unpaid_students'],
        ];
    }

    private function financialDashboardData(FinancialDataService $service, array $schoolIds): array
    {
        $result = [
            'total_due' => 0.0,
            'total_collected' => 0.0,
            'total_unpaid' => 0.0,
            'total_overdue' => 0.0,
            'late_students' => 0,
            'unpaid_breakdown' => ['monthly' => 0.0, 'flexible' => 0.0],
            'top_unpaid_students' => [],
        ];

        foreach ($schoolIds as $schoolId) {
            $projection = $service->getSchoolFinancialSummary($schoolId);
            $summary = $projection['summary'];
            $result['total_due'] += (float) $summary['total_due'];
            $result['total_collected'] += (float) $summary['total_paid'];
            $result['total_unpaid'] += (float) $summary['total_remaining'];
            $result['total_overdue'] += (float) $summary['overdue']['total_remaining'];
            $result['late_students'] += (int) $projection['late_students'];
            $result['unpaid_breakdown']['monthly'] += (float) $summary['monthly']['total_remaining'];
            $result['unpaid_breakdown']['flexible'] += (float) $summary['flexible']['total_remaining'];
            $result['top_unpaid_students'] = array_merge($result['top_unpaid_students'], $projection['top_unpaid_students']);
        }

        foreach (['total_due', 'total_collected', 'total_unpaid', 'total_overdue'] as $key) {
            $result[$key] = round($result[$key], 2);
        }
        foreach (['monthly', 'flexible'] as $key) {
            $result['unpaid_breakdown'][$key] = round($result['unpaid_breakdown'][$key], 2);
        }
        usort($result['top_unpaid_students'], static fn(array $left, array $right): int =>
            ($right['total_remaining'] <=> $left['total_remaining'])
            ?: strcmp($left['student_name'], $right['student_name'])
        );
        $result['top_unpaid_students'] = array_slice($result['top_unpaid_students'], 0, 5);

        return $result;
    }
}
