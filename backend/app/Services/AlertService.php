<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;

class AlertService
{
    public function alerts(): array
    {
        $pdo = Database::connect();
        [$role, $schoolId] = $this->authScope();
        $isSuperAdmin = $role === 'super_admin';
        $feeScope = $isSuperAdmin ? '' : ' AND mf.school_id = ?';
        $schoolScope = $isSuperAdmin ? '' : ' WHERE school_stats.school_id = ?';
        $params = $isSuperAdmin ? [] : [$schoolId];
        $dueDateExpr = $this->dueDateExpression();

        $upcomingCount = $this->countQuery(
            $pdo,
            "SELECT COUNT(*) AS total FROM monthly_fees mf
             INNER JOIN students s ON s.id = mf.student_id
             WHERE mf.status != 'PAID'
               AND {$dueDateExpr} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
             . $feeScope,
            $params
        );

        $late30Count = $this->countQuery(
            $pdo,
            "SELECT COUNT(*) AS total FROM monthly_fees mf
             INNER JOIN students s ON s.id = mf.student_id
             WHERE mf.status != 'PAID'
               AND {$dueDateExpr} < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
             . " AND {$dueDateExpr} >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)"
             . $feeScope,
            $params
        );

        $late60Count = $this->countQuery(
            $pdo,
            "SELECT COUNT(*) AS total FROM monthly_fees mf
             INNER JOIN students s ON s.id = mf.student_id
             WHERE mf.status != 'PAID'
               AND {$dueDateExpr} < DATE_SUB(CURDATE(), INTERVAL 60 DAY)"
             . $feeScope,
            $params
        );

        $studentsWithMultipleUnpaid = $this->countQuery(
            $pdo,
            "SELECT COUNT(*) AS total FROM (
                SELECT mf.student_id
                FROM monthly_fees mf
                WHERE mf.status != 'PAID'" . $feeScope . "
                GROUP BY mf.student_id
                HAVING COUNT(*) > 2
            ) AS sub",
            $params
        );

        $schoolsHighUnpaidRate = $this->countQuery(
            $pdo,
            "SELECT COUNT(*) AS total FROM (
                SELECT
                    school_stats.school_id,
                    SUM(CASE WHEN school_stats.status != 'PAID' THEN school_stats.remaining_amount ELSE 0 END) AS unpaid_amount,
                    SUM(school_stats.total_amount) AS total_amount
                FROM monthly_fees
                AS school_stats
                " . $schoolScope . "
                GROUP BY school_stats.school_id
                HAVING unpaid_amount / NULLIF(total_amount, 0) > 0.20
            ) AS sub",
            $params
        );

        return [
            $this->alert(
                code: 'payments_due_7_days',
                title: 'Paiements à échéance proche',
                description: 'Paiements arrivant à échéance dans les 7 prochains jours.',
                count: $upcomingCount,
                priority: 'Information',
                icon: 'Calendar',
            ),
            $this->alert(
                code: 'payments_late_30_days',
                title: 'Retards supérieurs à 30 jours',
                description: 'Paiements en retard depuis 31 à 60 jours.',
                count: $late30Count,
                priority: 'Élevée',
                icon: 'Clock',
            ),
            $this->alert(
                code: 'payments_late_60_days',
                title: 'Retards critiques',
                description: 'Paiements en retard depuis plus de 60 jours.',
                count: $late60Count,
                priority: 'Critique',
                icon: 'Flame',
            ),
            $this->alert(
                code: 'students_multiple_unpaid',
                title: 'Élèves avec plus de 2 impayés',
                description: 'Élèves ayant plus de 2 mensualités impayées.',
                count: $studentsWithMultipleUnpaid,
                priority: 'Élevée',
                icon: 'Users',
            ),
            $this->alert(
                code: 'schools_high_overdue_rate',
                title: 'Écoles à risque',
                description: "Écoles dont le taux d'impayés dépasse 20%.",
                count: $schoolsHighUnpaidRate,
                priority: 'Moyenne',
                icon: 'School',
            ),
        ];
    }

    private function alert(string $code, string $title, string $description, int $count, string $priority, string $icon): array
    {
        $styles = [
            'Critique' => ['level' => 'critical', 'color' => 'red'],
            'Élevée' => ['level' => 'high', 'color' => 'orange'],
            'Moyenne' => ['level' => 'medium', 'color' => 'yellow'],
            'Information' => ['level' => 'info', 'color' => 'blue'],
        ];

        return [
            'code' => $code,
            'title' => $title,
            'description' => $description,
            'count' => $count,
            'priority' => $priority,
            'level' => $styles[$priority]['level'] ?? 'info',
            'color' => $styles[$priority]['color'] ?? 'blue',
            'icon' => $icon,
        ];
    }

    private function countQuery(PDO $pdo, string $sql, array $params = []): int
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)($stmt->fetch()['total'] ?? 0);
    }

    private function dueDateExpression(): string
    {
        return 'CASE WHEN mf.month_label REGEXP "^[0-9]{1,2}$" THEN STR_TO_DATE(
                    CONCAT(
                        mf.year_value, "-", LPAD(mf.month_label, 2, "0"), "-",
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
                ) ELSE NULL END';
    }

    private function authScope(): array
    {
        $authUser = Request::get('auth_user', []);
        $role = (string)($authUser['role'] ?? '');
        $schoolId = isset($authUser['school_id']) ? (int)$authUser['school_id'] : null;
        return [$role, $schoolId];
    }

    private function isSuperAdmin(): bool
    {
        [$role] = $this->authScope();
        return $role === 'super_admin';
    }

    private function schoolId(): ?int
    {
        [, $schoolId] = $this->authScope();
        return $schoolId;
    }
}
