<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\PermissionMiddleware;
use App\Services\StudentImportService;
use App\Services\StudentService;

class StudentController
{
    private const STUDENT_FINANCIAL_FIELDS=['monthly_amount','discount_percent']; private const FINANCIAL_RESPONSE_FIELDS=['finance','financial_items','financial_payments','monthly_fees','payments','charges'];
    public function index(): void
    {
        Response::json((new StudentService())->getAll($_GET));
    }

    public function store(): void
    {
        $data = Request::json();
        $result = !empty($data['modern_profile'])
            ? (new StudentService())->createModern($data)
            : (new StudentService())->create($data);
        if (isset($result['error'])) {
            Response::json(['message' => $result['error']], 422);
        }

        Response::json($result, 201);
    }

    public function show(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new StudentService())->getById($id);
        if (!$result) {
            Response::json(['message' => 'Student not found'], 404);
        }
        if (!PermissionMiddleware::allows('finance.view')) { foreach(self::FINANCIAL_RESPONSE_FIELDS as $f)$result[$f]=[]; $result['finance']=null; foreach(self::STUDENT_FINANCIAL_FIELDS as $f)unset($result['student'][$f]); }

        Response::json($result);
    }

    public function uploadPhoto(): void
    {
        $id = (int)Request::param('id', 0);
        if (!isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'] ?? '')) {
            Response::json(['message' => 'Student photo is required'], 422);
        }

        $file = $_FILES['photo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Response::json(['message' => 'Student photo upload failed'], 422);
        }
        if ((int)($file['size'] ?? 0) > 3 * 1024 * 1024) {
            Response::json(['message' => 'Student photo must not exceed 3 MB'], 422);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            Response::json(['message' => 'Student photo must be JPG, PNG or WEBP'], 422);
        }

        $studentService = new StudentService();
        $student = $studentService->getPhotoStorageContext($id);
        if (isset($student['error'])) {
            Response::json(
                ['message' => $student['error']],
                $student['error'] === 'Forbidden' ? 403 : 404
            );
        }

        $directory = sprintf(
            '%s/../../public/uploads/students/school-%d/student-%d',
            __DIR__,
            (int)$student['school_id'],
            $id
        );
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            Response::json(['message' => 'Student photo directory is unavailable'], 500);
        }

        $filename = $this->studentPhotoSlug($student, $id) . '.jpg';
        $finalPath = $directory . '/' . $filename;
        $temporaryPath = $directory . '/.upload-' . bin2hex(random_bytes(8)) . '.tmp';
        $relativePath = sprintf(
            'uploads/students/school-%d/student-%d/%s',
            (int)$student['school_id'],
            $id,
            $filename
        );

        if (!$this->writeUploadedPhotoAsJpeg((string)$file['tmp_name'], (string)$mime, $temporaryPath)) {
            @unlink($temporaryPath);
            Response::json(['message' => 'Student photo could not be converted to JPG'], 500);
        }

        $backupPath = null;
        if (is_file($finalPath)) {
            $backupPath = $directory . '/.previous-' . bin2hex(random_bytes(8)) . '.bak';
            if (!rename($finalPath, $backupPath)) {
                @unlink($temporaryPath);
                Response::json(['message' => 'Existing student photo could not be secured'], 500);
            }
        }

        if (!rename($temporaryPath, $finalPath)) {
            if ($backupPath && is_file($backupPath)) {
                @rename($backupPath, $finalPath);
            }
            @unlink($temporaryPath);
            Response::json(['message' => 'Student photo could not be saved'], 500);
        }

        $result = $studentService->updatePhoto($id, $relativePath);
        if (isset($result['error'])) {
            @unlink($finalPath);
            if ($backupPath && is_file($backupPath)) {
                @rename($backupPath, $finalPath);
            }
            $status = match ($result['error']) {
                'Forbidden' => 403,
                'Student not found' => 404,
                default => 500,
            };
            Response::json(['message' => $result['error']], $status);
        }

        if ($backupPath && is_file($backupPath)) {
            @unlink($backupPath);
        }
        $this->deletePreviousStudentPhoto($student['photo_path'] ?? null, $relativePath);

        Response::json($result);
    }

    public function update(): void
    {
        $id = (int)Request::param('id', 0);
        $data = Request::json();
        $result = !empty($data['modern_profile'])
            ? (new StudentService())->updateModern($id, $data)
            : (new StudentService())->update($id, $data);
        if (isset($result['error'])) {
            $status = $result['error'] === 'Student not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    public function parentSummary(): void
    {
        $search = isset($_GET['search']) ? (string)$_GET['search'] : null;
        Response::json((new StudentService())->parentSummary($search));
    }

    public function profile(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new StudentService())->getById($id);
        if (!$result) {
            Response::json(['message' => 'Student not found'], 404);
        }
        Response::json($result);
    }

    public function status(): void
    {
        $result = (new StudentService())->changeStatus((int)Request::param('id', 0), Request::json());
        if (isset($result['error'])) Response::json(['message' => $result['error'], 'remaining_amount' => $result['remaining_amount'] ?? null], 422);
        Response::json($result);
    }

    public function statusHistory(): void
    {
        Response::json((new StudentService())->statusHistory((int)Request::param('id', 0)));
    }

    public function matriculePreview(): void
    {
        $result = (new StudentService())->matriculePreview();
        if (isset($result['error'])) Response::json(['message' => $result['error']], 422);
        Response::json($result);
    }

    public function massarCheck(): void
    {
        Response::json((new StudentService())->massarCheck((string)($_GET['massar_code'] ?? ''), isset($_GET['except_id']) ? (int)$_GET['except_id'] : null));
    }

    public function importClass(): void
    {
        if (!isset($_FILES['students_file'])) {
            Response::json(['message' => 'Le fichier des eleves est obligatoire.'], 422);
        }

        $result = (new StudentImportService())->import($_FILES['students_file'], $_POST);
        if (isset($result['error'])) {
            Response::json([
                'message' => $result['error'],
                'details' => $result['details'] ?? [],
            ], 422);
        }

        Response::json($result, 201);
    }

    public function delete(): void
    {
        $id = (int)Request::param('id', 0);
        $result = (new StudentService())->delete($id);
        if (isset($result['error'])) {
            $status = $result['error'] === 'Student not found' ? 404 : 422;
            if ($result['error'] === 'Forbidden') {
                $status = 403;
            }
            Response::json(['message' => $result['error']], $status);
        }

        Response::json($result);
    }

    private function studentPhotoSlug(array $student, int $studentId): string
    {
        $fullName = trim((string)($student['first_name'] ?? '') . ' ' . (string)($student['last_name'] ?? ''));
        $normalizedName = function_exists('mb_strtolower')
            ? mb_strtolower($fullName, 'UTF-8')
            : strtolower($fullName);
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $normalizedName);
        $slug = trim((string)$slug, '-');

        return $slug !== '' ? $slug : 'student-' . $studentId;
    }

    private function writeUploadedPhotoAsJpeg(string $sourcePath, string $mime, string $destinationPath): bool
    {
        if ($mime === 'image/jpeg') {
            return move_uploaded_file($sourcePath, $destinationPath);
        }

        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return false;
        }

        $imageContents = @file_get_contents($sourcePath);
        $sourceImage = $imageContents !== false ? @imagecreatefromstring($imageContents) : false;
        if ($sourceImage === false) {
            return false;
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        if ($sourceWidth <= 0 || $sourceHeight <= 0 || ($sourceWidth * $sourceHeight) > 40000000) {
            imagedestroy($sourceImage);
            return false;
        }

        $maxDimension = 1600;
        $scale = min(1, $maxDimension / max($sourceWidth, $sourceHeight));
        $targetWidth = max(1, (int)round($sourceWidth * $scale));
        $targetHeight = max(1, (int)round($sourceHeight * $scale));
        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($targetImage === false) {
            imagedestroy($sourceImage);
            return false;
        }

        $white = imagecolorallocate($targetImage, 255, 255, 255);
        imagefill($targetImage, 0, 0, $white);
        imagecopyresampled(
            $targetImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );
        $written = imagejpeg($targetImage, $destinationPath, 88);
        imagedestroy($targetImage);
        imagedestroy($sourceImage);

        return $written;
    }

    private function deletePreviousStudentPhoto(?string $previousPath, string $currentPath): void
    {
        $normalizedPreviousPath = str_replace('\\', '/', ltrim((string)$previousPath, '/'));
        if (
            $normalizedPreviousPath === ''
            || $normalizedPreviousPath === $currentPath
            || !str_starts_with($normalizedPreviousPath, 'uploads/students/')
        ) {
            return;
        }

        $publicDirectory = realpath(__DIR__ . '/../../public');
        $absolutePreviousPath = $publicDirectory
            ? realpath($publicDirectory . '/' . $normalizedPreviousPath)
            : false;
        $studentUploadsDirectory = $publicDirectory
            ? realpath($publicDirectory . '/uploads/students')
            : false;

        if (
            $absolutePreviousPath
            && $studentUploadsDirectory
            && is_file($absolutePreviousPath)
            && str_starts_with($absolutePreviousPath, $studentUploadsDirectory . DIRECTORY_SEPARATOR)
        ) {
            @unlink($absolutePreviousPath);
        }
    }
}
