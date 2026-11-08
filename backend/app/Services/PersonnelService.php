<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Request;
use DateTimeImmutable;
use PDO;
use PDOException;

class PersonnelService
{
    /** `TEACHER` is accepted only for records created before the PROFESSOR rename. */
    private const TYPES = ['PROFESSOR', 'ADMINISTRATIVE_STAFF'];
    private const LEGACY_TEACHER_TYPE = 'TEACHER';
    private const STATUSES = ['ACTIF', 'EN_CONGE', 'ARCHIVE'];
    private const CONTRACTS = ['CDI', 'CDD', 'VACATAIRE', 'STAGE', 'AUTRE'];
    private const GENDERS = ['MALE', 'FEMALE'];
    private const WORK_TIMES = ['TEMPS_PLEIN', 'TEMPS_PARTIEL'];

    private function school(): int { return (int) (Request::get('auth_user', [])['school_id'] ?? 0); }

    private function number(int $schoolId): string
    {
        $stmt = Database::connect()->prepare('SELECT COUNT(*) FROM personnel_profiles WHERE school_id = ?');
        $stmt->execute([$schoolId]);
        return 'PERS-' . $schoolId . '-' . str_pad((string) ((int) $stmt->fetchColumn() + 1), 5, '0', STR_PAD_LEFT);
    }

    public function nextNumber(): array
    {
        $schoolId = $this->school();
        return $schoolId ? ['personnel_number' => $this->number($schoolId)] : ['error' => 'Établissement introuvable'];
    }

    public function all(array $filters = []): array
    {
        $schoolId = $this->school();
        if (!$schoolId) return [];
        $where = ['school_id = ?']; $params = [$schoolId];
        foreach (['personnel_type', 'status', 'service_assignment'] as $field) {
            if (empty($filters[$field])) continue;
            if ($field === 'personnel_type' && $filters[$field] === 'PROFESSOR') {
                // Keep professors created with the previous enum visible during migration.
                $where[] = 'personnel_type IN (?, ?)';
                array_push($params, 'PROFESSOR', self::LEGACY_TEACHER_TYPE);
                continue;
            }
            $where[] = "$field = ?";
            $params[] = $filters[$field];
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') { $where[] = '(first_name LIKE ? OR last_name LIKE ? OR personnel_number LIKE ? OR cin LIKE ?)'; $search = '%' . $search . '%'; array_push($params, $search, $search, $search, $search); }
        $stmt = Database::connect()->prepare('SELECT * FROM personnel_profiles WHERE ' . implode(' AND ', $where) . ' ORDER BY last_name, first_name');
        $stmt->execute($params);
        return array_map([$this, 'present'], $stmt->fetchAll());
    }

    public function one(int $id): array|false
    {
        $stmt = Database::connect()->prepare('SELECT * FROM personnel_profiles WHERE id = ? AND school_id = ?');
        $stmt->execute([$id, $this->school()]); $personnel = $stmt->fetch();
        return $personnel ? $this->present($personnel, true) : false;
    }

    public function create(array $data): array
    {
        $schoolId = $this->school(); if (!$schoolId) return ['error' => 'Établissement introuvable'];
        $validated = $this->validate($data, $data, true); if (isset($validated['error'])) return $validated;
        $pdo = Database::connect();
        try {
            $started = !$pdo->inTransaction(); if ($started) $pdo->beginTransaction(); $number = $this->number($schoolId);
            $columns = array_keys($validated['fields']); $values = array_values($validated['fields']);
            array_unshift($columns, 'school_id', 'personnel_number', 'status'); array_unshift($values, $schoolId, $number, 'ACTIF');
            $pdo->prepare('INSERT INTO personnel_profiles (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute($values);
            $id = (int) $pdo->lastInsertId();
            $this->syncTeachingRelations($pdo, $id, $schoolId, $validated['personnel_type'], $validated['subject_ids'], $validated['class_level_ids']);
            if ($started) $pdo->commit(); return ['id' => $id, 'personnel_number' => $number, 'status' => 'ACTIF'];
        } catch (PDOException $exception) { if (($started ?? false) && $pdo->inTransaction()) $pdo->rollBack(); return ['error' => $this->databaseError($exception)]; }
    }

    public function update(int $id, array $data): array
    {
        $current = $this->rawOne($id); if (!$current) return ['error' => 'Personnel introuvable'];
        $validated = $this->validate(array_merge($current, $data), $data, false); if (isset($validated['error'])) return $validated;
        $pdo = Database::connect();
        try {
            $started = !$pdo->inTransaction(); if ($started) $pdo->beginTransaction();
            $set = implode(', ', array_map(static fn (string $field): string => "$field = ?", array_keys($validated['fields'])));
            $values = array_values($validated['fields']); $values[] = $id; $values[] = $this->school();
            $pdo->prepare("UPDATE personnel_profiles SET $set WHERE id = ? AND school_id = ?")->execute($values);
            $this->syncTeachingRelations($pdo, $id, $this->school(), $validated['personnel_type'], $validated['subject_ids'], $validated['class_level_ids']);
            if ($started) $pdo->commit(); return ['id' => $id];
        } catch (PDOException $exception) { if (($started ?? false) && $pdo->inTransaction()) $pdo->rollBack(); return ['error' => $this->databaseError($exception)]; }
    }

    public function status(int $id, string $status): array
    {
        if (!$this->rawOne($id)) return ['error' => 'Personnel introuvable'];
        if (!in_array($status, self::STATUSES, true)) return ['error' => 'Statut invalide'];
        Database::connect()->prepare('UPDATE personnel_profiles SET status = ? WHERE id = ? AND school_id = ?')->execute([$status, $id, $this->school()]);
        return ['id' => $id, 'status' => $status];
    }

    private function rawOne(int $id): array|false
    {
        $stmt = Database::connect()->prepare('SELECT * FROM personnel_profiles WHERE id = ? AND school_id = ?'); $stmt->execute([$id, $this->school()]); return $stmt->fetch();
    }

    private function validate(array $merged, ?array $provided, bool $creating): array
    {
        $aliases = ['function_name' => 'job_function', 'employment_status' => 'status', 'department' => 'administrative_department', 'main_mission' => 'primary_mission', 'assignment' => 'service_assignment', 'birth_date' => 'date_of_birth', 'main_diploma' => 'main_degree', 'observation' => 'notes'];
        foreach ($aliases as $incoming => $stored) if ($provided !== null && array_key_exists($incoming, $provided)) $merged[$stored] = $provided[$incoming];
        $text = static fn (string $key): ?string => trim((string) ($merged[$key] ?? '')) ?: null;
        $type = strtoupper((string) ($merged['personnel_type'] ?? ''));
        if ($type === self::LEGACY_TEACHER_TYPE) $type = 'PROFESSOR';
        $firstName = $text('first_name'); $lastName = $text('last_name'); $phone = $text('phone'); $jobTitle = $text('job_title') ?? $text('job_function');
        if (!$firstName || !$lastName || !$phone || !$jobTitle || !in_array($type, self::TYPES, true)) return ['error' => 'Type, nom, prénom, téléphone et poste obligatoires'];
        $email = $text('email'); if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'Adresse e-mail invalide'];
        $cin = strtoupper((string) preg_replace('/\s+/', '', (string) ($merged['cin'] ?? '')));
        $fields = [
            'first_name' => $firstName, 'last_name' => $lastName, 'first_name_ar' => $text('first_name_ar'), 'last_name_ar' => $text('last_name_ar'), 'cin' => $cin ?: null,
            'date_of_birth' => $text('date_of_birth'), 'birth_place' => $text('birth_place'), 'gender' => $text('gender'), 'nationality' => $text('nationality'), 'address' => $text('address'), 'city' => $text('city'), 'phone' => $phone, 'email' => $email,
            'emergency_contact_name' => $text('emergency_contact_name'), 'emergency_contact_phone' => $text('emergency_contact_phone'), 'personnel_type' => $type, 'job_title' => $jobTitle, 'job_function' => $text('job_function'), 'service_assignment' => $text('service_assignment'), 'entry_date' => $text('entry_date'), 'contract_type' => $text('contract_type'), 'main_degree' => $text('main_degree'), 'specialty' => $type === 'PROFESSOR' ? $text('specialty') : null, 'work_time' => $text('work_time'), 'administrative_department' => $type === 'ADMINISTRATIVE_STAFF' ? $text('administrative_department') : null, 'primary_mission' => $type === 'ADMINISTRATIVE_STAFF' ? $text('primary_mission') : null, 'notes' => $text('notes'),
        ];
        foreach (['date_of_birth', 'entry_date'] as $field) if ($fields[$field] && !$this->validDate($fields[$field])) return ['error' => 'Date invalide'];
        if ($fields['gender'] && !in_array($fields['gender'], self::GENDERS, true)) return ['error' => 'Sexe invalide'];
        if ($fields['contract_type'] && !in_array($fields['contract_type'], self::CONTRACTS, true)) return ['error' => 'Type de contrat invalide'];
        if ($fields['work_time'] && !in_array($fields['work_time'], self::WORK_TIMES, true)) return ['error' => 'Temps de travail invalide'];
        if (!$creating && $provided !== null && (array_key_exists('employment_status', $provided) || array_key_exists('status', $provided))) { $status = (string) ($provided['employment_status'] ?? $provided['status']); if (!in_array($status, self::STATUSES, true)) return ['error' => 'Statut invalide']; $fields['status'] = $status; }
        $subjectIds = $this->ids($provided['subject_ids'] ?? $provided['subjects'] ?? null); $classLevelIds = $this->ids($provided['class_level_ids'] ?? $provided['class_levels'] ?? null);
        if (!$creating && $provided !== null && !array_key_exists('subject_ids', $provided) && !array_key_exists('subjects', $provided)) $subjectIds = $this->currentRelationIds('personnel_subjects', 'subject_id', (int) $merged['id']);
        if (!$creating && $provided !== null && !array_key_exists('class_level_ids', $provided) && !array_key_exists('class_levels', $provided)) $classLevelIds = $this->currentRelationIds('personnel_class_levels', 'class_level_id', (int) $merged['id']);
        if ($type === 'PROFESSOR') {
            // A personnel profile may be created before pedagogical assignments are known.
            // When assignments are supplied, keep the previous strict integrity checks.
            if (($subjectIds && !$classLevelIds) || (!$subjectIds && $classLevelIds)) {
                return ['error' => 'Sélectionnez à la fois les matières et les classes, ou laissez les affectations vides.'];
            }
            if ($subjectIds && $classLevelIds) {
                $error = $this->validateRelations($this->school(), $subjectIds, $classLevelIds);
                if ($error) return ['error' => $error];
            }
        } else { $subjectIds = []; $classLevelIds = []; }
        return ['fields' => $fields, 'personnel_type' => $type, 'subject_ids' => $subjectIds, 'class_level_ids' => $classLevelIds];
    }

    private function validDate(string $value): bool { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date !== false && $date->format('Y-m-d') === $value; }
    private function ids(mixed $values): array { if (!is_array($values)) return []; return array_values(array_unique(array_filter(array_map(static fn ($value): int => is_array($value) ? (int) ($value['id'] ?? 0) : (int) $value, $values)))); }

    private function validateRelations(int $schoolId, array $subjectIds, array $classLevelIds): ?string
    {
        $pdo = Database::connect();
        if ($classLevelIds) {
            $marks = implode(',', array_fill(0, count($classLevelIds), '?'));
            $activeYear = $this->activeSchoolYear($pdo, $schoolId);
            $yearCondition = $activeYear === null ? '' : " AND (school_year IS NULL OR REPLACE(school_year, '-', '/') = ?)";
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM class_levels WHERE school_id = ? AND status = 'ACTIVE'$yearCondition AND id IN ($marks)");
            $parameters = [$schoolId];
            if ($activeYear !== null) $parameters[] = $activeYear;
            $stmt->execute([...$parameters, ...$classLevelIds]);
            if ((int) $stmt->fetchColumn() !== count($classLevelIds)) return 'Une classe ou un niveau doit être actif, appartenir à votre établissement et à l’année scolaire active';
        }
        if ($subjectIds) {
            // The frontend obtains subjects from these exact class-level mappings. Seed a
            // newly created level before validating it, so the API and dropdown agree.
            $subjectService = new SubjectService();
            foreach ($classLevelIds as $classLevelId) $subjectService->ensureSubjectsForClassLevel($classLevelId);
            $subjectMarks = implode(',', array_fill(0, count($subjectIds), '?'));
            $levelMarks = implode(',', array_fill(0, count($classLevelIds), '?'));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE status = 'ACTIVE' AND id IN ($subjectMarks)");
            $stmt->execute($subjectIds);
            if ((int) $stmt->fetchColumn() !== count($subjectIds)) return 'Une matière sélectionnée est invalide';
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT scl.subject_id) FROM subject_class_levels scl INNER JOIN class_levels cl ON cl.id = scl.class_level_id WHERE cl.school_id = ? AND cl.status = 'ACTIVE' AND scl.class_level_id IN ($levelMarks) AND scl.subject_id IN ($subjectMarks)");
            $stmt->execute([$schoolId, ...$classLevelIds, ...$subjectIds]);
            if ((int) $stmt->fetchColumn() !== count($subjectIds)) return 'Chaque matière doit être disponible pour au moins une des classes ou niveaux sélectionnés';
        }
        return null;
    }

    private function activeSchoolYear(PDO $pdo, int $schoolId): ?string
    {
        $stmt = $pdo->prepare('SELECT COALESCE(NULLIF(ay.name, ""), NULLIF(ay.label, "")) FROM school_settings settings INNER JOIN academic_years ay ON ay.id = CAST(settings.setting_value AS UNSIGNED) AND ay.school_id = settings.school_id WHERE settings.school_id = ? AND settings.setting_key = "active_academic_year_id" AND ay.status = "ACTIVE" LIMIT 1');
        $stmt->execute([$schoolId]);
        $year = trim((string) $stmt->fetchColumn());
        return $year === '' ? null : str_replace('-', '/', $year);
    }

    private function syncTeachingRelations(PDO $pdo, int $personnelId, int $schoolId, string $type, array $subjectIds, array $classLevelIds): void
    {
        foreach ([['personnel_subjects', 'subject_id', $subjectIds], ['personnel_class_levels', 'class_level_id', $classLevelIds]] as [$table, $column, $ids]) { $pdo->prepare("DELETE FROM $table WHERE personnel_id = ? AND school_id = ?")->execute([$personnelId, $schoolId]); if ($type !== 'PROFESSOR') continue; $stmt = $pdo->prepare("INSERT INTO $table (personnel_id, school_id, $column) VALUES (?, ?, ?)"); foreach ($ids as $relationId) $stmt->execute([$personnelId, $schoolId, $relationId]); }
    }

    private function currentRelationIds(string $table, string $column, int $personnelId): array { $stmt = Database::connect()->prepare("SELECT $column FROM $table WHERE personnel_id = ? AND school_id = ?"); $stmt->execute([$personnelId, $this->school()]); return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)); }

    private function present(array $personnel, bool $withRelations = false): array
    {
        if (($personnel['personnel_type'] ?? null) === self::LEGACY_TEACHER_TYPE) $personnel['personnel_type'] = 'PROFESSOR';
        $personnel['function_name'] = $personnel['job_function']; $personnel['employment_status'] = $personnel['status']; $personnel['department'] = $personnel['administrative_department']; $personnel['main_mission'] = $personnel['primary_mission']; $personnel['assignment'] = $personnel['service_assignment']; $personnel['birth_date'] = $personnel['date_of_birth']; $personnel['main_diploma'] = $personnel['main_degree']; $personnel['observation'] = $personnel['notes'];
        if (!$withRelations) return $personnel;
        $id = (int) $personnel['id']; $pdo = Database::connect();
        $subjects = $pdo->prepare('SELECT s.id, s.name, s.code FROM personnel_subjects ps INNER JOIN subjects s ON s.id = ps.subject_id WHERE ps.personnel_id = ? AND ps.school_id = ? ORDER BY s.sort_order, s.name'); $subjects->execute([$id, $this->school()]);
        $levels = $pdo->prepare('SELECT cl.id, cl.name, cl.level_name, cl.group_name, cl.code FROM personnel_class_levels pcl INNER JOIN class_levels cl ON cl.id = pcl.class_level_id WHERE pcl.personnel_id = ? AND pcl.school_id = ? ORDER BY cl.sort_order, cl.name'); $levels->execute([$id, $this->school()]);
        $personnel['subjects'] = $subjects->fetchAll(); $personnel['subject_ids'] = array_map(static fn (array $subject): int => (int) $subject['id'], $personnel['subjects']); $personnel['class_levels'] = $levels->fetchAll(); $personnel['class_level_ids'] = array_map(static fn (array $level): int => (int) $level['id'], $personnel['class_levels']);
        return $personnel;
    }

    private function databaseError(PDOException $exception): string { return $exception->getCode() === '23000' ? 'Matricule ou CIN déjà utilisé dans cet établissement' : 'Enregistrement impossible'; }
}
