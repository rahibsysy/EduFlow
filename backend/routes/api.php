<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\AlertController;
use App\Controllers\ClassLevelController;
use App\Controllers\MonthlyFeeController;
use App\Controllers\PaymentController;
use App\Controllers\PaymentMethodController;
use App\Controllers\ReceiptController;
use App\Controllers\SchoolController;
use App\Controllers\ScheduleController;
use App\Controllers\SubjectController;
use App\Controllers\StudentController;
use App\Controllers\SuperAdminController;
use App\Controllers\TeacherController;
use App\Controllers\UserController;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

Router::add('POST', '/api/login', [new AuthController(), 'login']);

Router::add('GET', '/api/students', [new StudentController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/students', [new StudentController(), 'store'], [AuthMiddleware::class]);
Router::add('POST', '/api/students/import', [new StudentController(), 'importClass'], [AuthMiddleware::class]);
Router::add('GET', '/api/students/{id}', [new StudentController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/students/{id}', [new StudentController(), 'update'], [AuthMiddleware::class]);
Router::add('DELETE', '/api/students/{id}', [new StudentController(), 'delete'], [AuthMiddleware::class]);
Router::add('GET', '/api/parents/summary', [new StudentController(), 'parentSummary'], [AuthMiddleware::class]);
Router::add('GET', '/api/class-levels', [new ClassLevelController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/class-levels', [new ClassLevelController(), 'store'], [AuthMiddleware::class]);
Router::add('GET', '/api/class-levels/{id}', [new ClassLevelController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/class-levels/{id}', [new ClassLevelController(), 'update'], [AuthMiddleware::class]);
Router::add('DELETE', '/api/class-levels/{id}', [new ClassLevelController(), 'delete'], [AuthMiddleware::class]);

Router::add('GET', '/api/subjects', [new SubjectController(), 'index'], [AuthMiddleware::class]);
Router::add('PUT', '/api/subjects/{id}/class-levels/{classLevelId}', [new SubjectController(), 'updateClassLevelHours'], [AuthMiddleware::class]);
Router::add('GET', '/api/teachers', [new TeacherController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/teachers', [new TeacherController(), 'store'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('PUT', '/api/teachers/{id}', [new TeacherController(), 'update'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('DELETE', '/api/teachers/{id}', [new TeacherController(), 'delete'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);

Router::add('GET', '/api/schedules', [new ScheduleController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/schedules', [new ScheduleController(), 'store'], [AuthMiddleware::class]);
Router::add('GET', '/api/schedules/{id}', [new ScheduleController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/schedules/{id}', [new ScheduleController(), 'update'], [AuthMiddleware::class]);
Router::add('DELETE', '/api/schedules/{id}', [new ScheduleController(), 'delete'], [AuthMiddleware::class]);

Router::add('GET', '/api/payments', [new PaymentController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/payments', [new PaymentController(), 'store'], [AuthMiddleware::class]);
Router::add('GET', '/api/payments/{id}', [new PaymentController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/payments/{id}', [new PaymentController(), 'update'], [AuthMiddleware::class]);
Router::add('DELETE', '/api/payments/{id}', [new PaymentController(), 'delete'], [AuthMiddleware::class]);
Router::add('GET', '/api/payment-methods', [new PaymentMethodController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/payment-methods', [new PaymentMethodController(), 'store'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('GET', '/api/payment-methods/{id}', [new PaymentMethodController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/payment-methods/{id}', [new PaymentMethodController(), 'update'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('DELETE', '/api/payment-methods/{id}', [new PaymentMethodController(), 'delete'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);

Router::add('GET', '/api/monthly-fees', [new MonthlyFeeController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/monthly-fees', [new MonthlyFeeController(), 'store'], [AuthMiddleware::class]);
Router::add('GET', '/api/monthly-fees/unpaid', [new MonthlyFeeController(), 'unpaid'], [AuthMiddleware::class]);
Router::add('GET', '/api/monthly-fees/{id}', [new MonthlyFeeController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/monthly-fees/{id}', [new MonthlyFeeController(), 'update'], [AuthMiddleware::class]);
Router::add('DELETE', '/api/monthly-fees/{id}', [new MonthlyFeeController(), 'delete'], [AuthMiddleware::class]);
Router::add('GET', '/api/dashboard', [new DashboardController(), 'index'], [AuthMiddleware::class]);
Router::add('GET', '/api/dashboard/alerts', [new AlertController(), 'index'], [AuthMiddleware::class]);
Router::add('GET', '/api/school/dashboard', [new DashboardController(), 'index'], [AuthMiddleware::class]);
Router::add('GET', '/api/super-admin/dashboard', [new SuperAdminController(), 'dashboard'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);

Router::add('GET', '/api/school/current', [new SchoolController(), 'current'], [AuthMiddleware::class]);

// School routes - specific routes before generic {id} routes
Router::add('GET', '/api/schools', [new SchoolController(), 'index'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('POST', '/api/schools', [new SchoolController(), 'store'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('POST', '/api/schools/logo', [new SchoolController(), 'uploadLogo'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);

// School {id} routes
Router::add('GET', '/api/schools/{id}', [new SchoolController(), 'show'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('PUT', '/api/schools/{id}', [new SchoolController(), 'update'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('DELETE', '/api/schools/{id}', [new SchoolController(), 'delete'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('POST', '/api/schools/{id}/admin', [new SchoolController(), 'createAdmin'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);
Router::add('POST', '/api/schools/{id}/import-data', [new SchoolController(), 'importData'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);

Router::add('GET', '/api/users', [new UserController(), 'index'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('POST', '/api/users', [new UserController(), 'store'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('GET', '/api/users/{id}', [new UserController(), 'show'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('PUT', '/api/users/{id}', [new UserController(), 'update'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('DELETE', '/api/users/{id}', [new UserController(), 'delete'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('POST', '/api/users/{id}/reset-password', [new UserController(), 'resetPassword'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);

Router::add('GET', '/api/receipts', function (): void {
    $paymentId = (int)($_GET['payment_id'] ?? 0);
    (new ReceiptController())->show($paymentId);
}, [AuthMiddleware::class]);

Router::dispatch(Request::method(), Request::uri());
