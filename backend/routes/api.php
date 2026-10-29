<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\AccountController;
use App\Controllers\AttendanceController;
use App\Controllers\DashboardController;
use App\Controllers\AlertController;
use App\Controllers\AcademicYearController;
use App\Controllers\ClassLevelController;
use App\Controllers\EnrollmentController;
use App\Controllers\MonthlyFeeController;
use App\Controllers\PaymentController;
use App\Controllers\PaymentDocumentController;
use App\Controllers\PaymentMethodController;
use App\Controllers\ReceiptController;
use App\Controllers\ReportCardController;
use App\Controllers\SchoolController;
use App\Controllers\ScheduleController;
use App\Controllers\SubjectController;
use App\Controllers\StudentController;
use App\Controllers\StudentDocumentController;
use App\Controllers\ChargeCategoryController;
use App\Controllers\DiscountController;
use App\Controllers\StudentChargeController;
use App\Controllers\SuperAdminController;
use App\Controllers\TeacherController;
use App\Controllers\TeacherAssignmentController;
use App\Controllers\GradeController;
use App\Controllers\InitialSetupController;
use App\Controllers\UserController;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\SchoolRoleMiddleware;

Router::add('POST', '/api/login', [new AuthController(), 'login']);
Router::add('GET', '/api/setup/status', [new InitialSetupController(), 'status']);
Router::add('POST', '/api/setup', [new InitialSetupController(), 'install']);
Router::add('GET', '/api/account/profile', [new AccountController(), 'profile'], [AuthMiddleware::class]);
Router::add('PUT', '/api/account/password', [new AccountController(), 'changePassword'], [AuthMiddleware::class]);
Router::add('POST', '/api/account/photo', [new AccountController(), 'uploadPhoto'], [AuthMiddleware::class]);

Router::add('GET', '/api/teacher/dashboard', [new GradeController(), 'dashboard'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('GET', '/api/teacher/assessments', [new GradeController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('POST', '/api/teacher/assessments', [new GradeController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('GET', '/api/teacher/assessments/{id}/students', [new GradeController(), 'roster'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('PUT', '/api/teacher/assessments/{id}/grades', [new GradeController(), 'saveGrades'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('PUT', '/api/teacher/assessments/{id}/status', [new GradeController(), 'updateStatus'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'professeur'])]);
Router::add('GET', '/api/teacher/gradebook', [new GradeController(), 'gradebook'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('GET', '/api/teacher/report-cards/{studentId}/pdf', [new ReportCardController(), 'download'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'professeur'])]);
Router::add('GET', '/api/teacher/sessions', [new AttendanceController(), 'sessions'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('GET', '/api/teacher/sessions/{scheduleId}/students', [new AttendanceController(), 'roster'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);
Router::add('POST', '/api/teacher/sessions/{scheduleId}/attendance', [new AttendanceController(), 'save'], [AuthMiddleware::class, new SchoolRoleMiddleware('professeur')]);

Router::add('GET', '/api/academic-years', [new AcademicYearController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'professeur'])]);
Router::add('POST', '/api/academic-years', [new AcademicYearController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('PUT', '/api/academic-years/{id}', [new AcademicYearController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);

Router::add('GET', '/api/enrollments', [new EnrollmentController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'professeur'])]);
Router::add('POST', '/api/enrollments', [new EnrollmentController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('PUT', '/api/enrollments/{id}', [new EnrollmentController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);

Router::add('GET', '/api/teacher-assignments', [new TeacherAssignmentController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'professeur'])]);
Router::add('POST', '/api/teacher-assignments', [new TeacherAssignmentController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('PUT', '/api/teacher-assignments/{id}', [new TeacherAssignmentController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('DELETE', '/api/teacher-assignments/{id}', [new TeacherAssignmentController(), 'delete'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);

Router::add('GET', '/api/students', [new StudentController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('POST', '/api/students', [new StudentController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('POST', '/api/students/import', [new StudentController(), 'importClass'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/students/{id}', [new StudentController(), 'show'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('POST', '/api/students/{id}/photo', [new StudentController(), 'uploadPhoto'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('PUT', '/api/students/{id}', [new StudentController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('DELETE', '/api/students/{id}', [new StudentController(), 'delete'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/parents/summary', [new StudentController(), 'parentSummary'], [AuthMiddleware::class]);
Router::add('GET', '/api/class-levels', [new ClassLevelController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/class-levels', [new ClassLevelController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/class-levels/{id}', [new ClassLevelController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/class-levels/{id}', [new ClassLevelController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('DELETE', '/api/class-levels/{id}', [new ClassLevelController(), 'delete'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);

Router::add('GET', '/api/subjects', [new SubjectController(), 'index'], [AuthMiddleware::class]);
Router::add('PUT', '/api/subjects/{id}/class-levels/{classLevelId}', [new SubjectController(), 'updateClassLevelHours'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/teachers', [new TeacherController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/teachers', [new TeacherController(), 'store'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('PUT', '/api/teachers/{id}', [new TeacherController(), 'update'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);
Router::add('DELETE', '/api/teachers/{id}', [new TeacherController(), 'delete'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);

Router::add('GET', '/api/schedules', [new ScheduleController(), 'index'], [AuthMiddleware::class]);
Router::add('POST', '/api/schedules', [new ScheduleController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/schedules/workloads', [new ScheduleController(), 'workloads'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'professeur'])]);
Router::add('PUT', '/api/schedules/{id}/move', [new ScheduleController(), 'move'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/schedules/{id}', [new ScheduleController(), 'show'], [AuthMiddleware::class]);
Router::add('PUT', '/api/schedules/{id}', [new ScheduleController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('DELETE', '/api/schedules/{id}', [new ScheduleController(), 'delete'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);

Router::add('GET', '/api/payments', [new PaymentController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('POST', '/api/payments', [new PaymentController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/payments/{id}', [new PaymentController(), 'show'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('PUT', '/api/payments/{id}', [new PaymentController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('POST', '/api/payments/{id}/cancel', [new PaymentController(), 'cancel'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('DELETE', '/api/payments/{id}', [new PaymentController(), 'delete'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/payment-documents/monthly', [new PaymentDocumentController(), 'monthly'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/payment-documents/students/{id}', [new PaymentDocumentController(), 'student'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/payment-documents/families/{id}', [new PaymentDocumentController(), 'family'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/payment-documents/unpaid', [new PaymentDocumentController(), 'unpaid'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/student-documents/{id}/financial-statement', [new StudentDocumentController(), 'financialStatement'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
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
Router::add('GET', '/api/charge-categories', [new ChargeCategoryController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('POST', '/api/charge-categories', [new ChargeCategoryController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/charge-categories/{id}', [new ChargeCategoryController(), 'show'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('PUT', '/api/charge-categories/{id}', [new ChargeCategoryController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('PATCH', '/api/charge-categories/{id}/archive', [new ChargeCategoryController(), 'archive'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/student-charges', [new StudentChargeController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/student-charges/unpaid', [new StudentChargeController(), 'unpaid'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('POST', '/api/student-charges/preview', [new StudentChargeController(), 'preview'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('POST', '/api/student-charges/bulk', [new StudentChargeController(), 'bulk'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('POST', '/api/student-charges', [new StudentChargeController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('PATCH', '/api/student-charges/{id}/cancel', [new StudentChargeController(), 'cancel'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/student-charges/{id}/history', [new StudentChargeController(), 'history'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/student-charges/{id}', [new StudentChargeController(), 'show'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('PUT', '/api/student-charges/{id}', [new StudentChargeController(), 'update'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/discounts', [new DiscountController(), 'index'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('POST', '/api/discounts/preview', [new DiscountController(), 'preview'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('POST', '/api/discounts', [new DiscountController(), 'store'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/discounts/{id}/history', [new DiscountController(), 'history'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('PATCH', '/api/discounts/{id}/cancel', [new DiscountController(), 'cancel'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('POST', '/api/discounts/{id}/replace', [new DiscountController(), 'replace'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);
Router::add('GET', '/api/discounts/{id}', [new DiscountController(), 'show'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/dashboard', [new DashboardController(), 'index'], [AuthMiddleware::class, new RoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/dashboard/alerts', [new AlertController(), 'index'], [AuthMiddleware::class, new RoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/school/dashboard', [new DashboardController(), 'index'], [AuthMiddleware::class, new RoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/super-admin/dashboard', [new SuperAdminController(), 'dashboard'], [AuthMiddleware::class, new RoleMiddleware('super_admin')]);

Router::add('GET', '/api/school/current', [new SchoolController(), 'current'], [AuthMiddleware::class]);
Router::add('GET', '/api/school/current/active-academic-year', [new SchoolController(), 'activeAcademicYear'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('PUT', '/api/school/current/active-academic-year', [new SchoolController(), 'updateActiveAcademicYear'], [AuthMiddleware::class, new SchoolRoleMiddleware('admin')]);

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
Router::add('POST', '/api/users/{id}/reset-password', [new UserController(), 'resetPassword'], [AuthMiddleware::class, new RoleMiddleware(['super_admin', 'admin'])]);

Router::add('GET', '/api/receipts/{id}/pdf', [new ReceiptController(), 'downloadPdf'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);
Router::add('GET', '/api/receipts/{id}', [new ReceiptController(), 'show'], [AuthMiddleware::class, new SchoolRoleMiddleware(['admin', 'user'])]);

Router::dispatch(Request::method(), Request::uri());
