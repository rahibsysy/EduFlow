<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use PDO;

/**
 * Read and maintain the tuition grid used by student registration.
 *
 * A price is deliberately scoped to a school year, a level and an optional
 * stream.  It is never inferred from a class name and a missing price is a
 * valid, explicit state (rather than an implicit 0 DH tuition).
 */
class LevelFeeService
{
    private function school(): int
    {
        return (int) (Request::get('auth_user', [])['school_id'] ?? 0);
    }

    public function show(int $classLevelId): array|false
    {
        $class = $this->classContext($classLevelId);
        if (!$class) return false;

        return $this->forContext($class) ?: [
            'configured' => false,
            'school_id' => (int) $class['school_id'],
            'school_year' => $class['school_year'],
            'level_name' => $class['level_name'],
            'stream' => $class['stream'],
            'monthly_amount' => null,
        ];
    }

    public function save(array $data): array
    {
        $schoolId = $this->school();
        $year = trim((string) ($data['school_year'] ?? ''));
        $level = trim((string) ($data['level_name'] ?? ''));
        $stream = trim((string) ($data['stream'] ?? ''));
        $amount = array_key_exists('monthly_amount', $data) ? round((float) $data['monthly_amount'], 2) : -1;

        if ($schoolId <= 0 || $year === '' || $level === '' || $amount < 0) {
            return ['error' => 'Année scolaire, niveau et mensualité valides sont obligatoires'];
        }

        $pdo = Database::connect();
        $pdo->prepare(
            'INSERT INTO tuition_fee_schedules (school_id,school_year,level_name,stream,monthly_amount,updated_by)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE monthly_amount=VALUES(monthly_amount),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
        )->execute([$schoolId, $year, $level, $stream, $amount, Request::get('auth_user', [])['id'] ?? null]);

        return ['configured' => true, 'school_year' => $year, 'level_name' => $level, 'stream' => $stream ?: null, 'monthly_amount' => $amount];
    }

    private function classContext(int $classLevelId): array|false
    {
        $stmt = Database::connect()->prepare(
            'SELECT id,school_id,school_year,COALESCE(NULLIF(level_name,""),name) level_name,
                    NULLIF(group_name, "") stream
             FROM class_levels WHERE id=? AND school_id=? LIMIT 1'
        );
        $stmt->execute([$classLevelId, $this->school()]);
        return $stmt->fetch() ?: false;
    }

    private function forContext(array $class): array|false
    {
        $pdo = Database::connect();
        // An exact stream wins; an empty-stream row is the level-wide fallback.
        $stmt = $pdo->prepare(
            'SELECT id,school_id,school_year,level_name,stream,monthly_amount,created_at,updated_at
             FROM tuition_fee_schedules
             WHERE school_id=? AND school_year=? AND level_name=?
               AND (stream = ? OR stream = "")
             ORDER BY (stream = ?) DESC, id DESC LIMIT 1'
        );
        $stmt->execute([
            (int) $class['school_id'], (string) $class['school_year'], (string) $class['level_name'],
            (string) ($class['stream'] ?? ''), (string) ($class['stream'] ?? ''),
        ]);
        $row = $stmt->fetch();
        if (!$row) return false;
        $row['configured'] = true;
        $row['monthly_amount'] = (float) $row['monthly_amount'];
        return $row;
    }
}
