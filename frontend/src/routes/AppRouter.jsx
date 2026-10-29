import { BrowserRouter, Navigate, Route, Routes } from "react-router-dom";
import ProtectedRoute from "../components/common/ProtectedRoute";
import RoleRoute from "../components/common/RoleRoute";
import DashboardLayout from "../layouts/DashboardLayout";
import AdminPage from "../pages/AdminPage";
import ClassesPage from "../pages/ClassesPage";
import DashboardPage from "../pages/DashboardPage";
import LoginPage from "../pages/LoginPage";
import SetupPage from "../pages/SetupPage";
import FirstPasswordPage from "../pages/FirstPasswordPage";
import MonthlyFeesPage from "../pages/MonthlyFeesPage";
import NotFoundPage from "../pages/NotFoundPage";
import SchoolAdminCreatePage from "../pages/SchoolAdminCreatePage";
import SchoolDetailsPage from "../pages/SchoolDetailsPage";
import SchoolsPage from "../pages/SchoolsPage";
import SchedulesPage from "../pages/SchedulesPage";
import StudentFormPage from "../pages/StudentFormPage";
import StudentDetailsPage from "../pages/StudentDetailsPage";
import StudentsPage from "../pages/StudentsPage";
import SuperAdminDashboardPage from "../pages/SuperAdminDashboardPage";
import TeachersPage from "../pages/TeachersPage";
import TeacherDashboardPage from "../pages/TeacherDashboardPage";
import TeacherAttendancePage from "../pages/TeacherAttendancePage";
import TeacherAssessmentsPage from "../pages/TeacherAssessmentsPage";
import TeacherGradeEntryPage from "../pages/TeacherGradeEntryPage";
import TeacherGradebookPage from "../pages/TeacherGradebookPage";
import TeacherProfilePage from "../pages/TeacherProfilePage";
import UnpaidPage from "../pages/UnpaidPage";
import PaymentCreatePage from "../pages/payments/PaymentCreatePage";
import PaymentDetailsPage from "../pages/payments/PaymentDetailsPage";
import PaymentsHistoryPage from "../pages/payments/PaymentsHistoryPage";

const FinanceRoute = ({ children }) => (
  <RoleRoute roles={["admin", "user"]}>{children}</RoleRoute>
);

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<LoginPage />} />
        <Route path="/setup" element={<SetupPage />} />
        <Route path="/account/activate" element={<ProtectedRoute allowPasswordChange><FirstPasswordPage /></ProtectedRoute>} />

        <Route element={<ProtectedRoute><DashboardLayout /></ProtectedRoute>}>
          <Route path="/dashboard" element={<RoleRoute roles={["admin", "user"]}><DashboardPage /></RoleRoute>} />
          <Route path="/students" element={<RoleRoute roles={["admin", "user"]}><StudentsPage /></RoleRoute>} />
          <Route path="/students/new" element={<RoleRoute roles={["admin"]}><StudentFormPage /></RoleRoute>} />
          <Route path="/students/:id/edit" element={<RoleRoute roles={["admin"]}><StudentFormPage /></RoleRoute>} />
          <Route path="/students/:id" element={<RoleRoute roles={["admin", "user"]}><StudentDetailsPage /></RoleRoute>} />
          <Route path="/classes" element={<RoleRoute roles={["admin"]}><ClassesPage /></RoleRoute>} />
          <Route path="/schedules" element={<RoleRoute roles={["admin", "professeur"]}><SchedulesPage /></RoleRoute>} />
          <Route path="/teachers" element={<RoleRoute roles={["admin"]}><TeachersPage /></RoleRoute>} />
          <Route path="/teacher/dashboard" element={<RoleRoute roles={["professeur"]}><TeacherDashboardPage /></RoleRoute>} />
          <Route path="/teacher/attendance" element={<RoleRoute roles={["professeur"]}><TeacherAttendancePage /></RoleRoute>} />
          <Route path="/teacher/assessments" element={<RoleRoute roles={["professeur"]}><TeacherAssessmentsPage /></RoleRoute>} />
          <Route path="/teacher/assessments/:id/grades" element={<RoleRoute roles={["professeur"]}><TeacherGradeEntryPage /></RoleRoute>} />
          <Route path="/teacher/gradebook" element={<RoleRoute roles={["professeur"]}><TeacherGradebookPage /></RoleRoute>} />
          <Route path="/teacher/profile" element={<RoleRoute roles={["professeur"]}><TeacherProfilePage /></RoleRoute>} />

          <Route path="/finances/payments/new" element={<FinanceRoute><PaymentCreatePage /></FinanceRoute>} />
          <Route path="/finances/payments/history" element={<FinanceRoute><PaymentsHistoryPage /></FinanceRoute>} />
          <Route path="/finances/payments/:id" element={<FinanceRoute><PaymentDetailsPage /></FinanceRoute>} />
          <Route path="/finances/monthly-fees" element={<FinanceRoute><MonthlyFeesPage /></FinanceRoute>} />
          <Route path="/finances/unpaid" element={<FinanceRoute><UnpaidPage /></FinanceRoute>} />

          <Route path="/payments" element={<Navigate to="/finances/payments/history" replace />} />
          <Route path="/monthly-fees" element={<Navigate to="/finances/monthly-fees" replace />} />
          <Route path="/unpaid" element={<Navigate to="/finances/unpaid" replace />} />

          <Route path="/admin" element={<RoleRoute roles={["admin", "super_admin"]}><AdminPage /></RoleRoute>} />
          <Route path="/super-admin/dashboard" element={<RoleRoute roles={["super_admin"]}><SuperAdminDashboardPage /></RoleRoute>} />
          <Route path="/super-admin/schools" element={<RoleRoute roles={["super_admin"]}><SchoolsPage /></RoleRoute>} />
          <Route path="/super-admin/schools/:id" element={<RoleRoute roles={["super_admin"]}><SchoolDetailsPage /></RoleRoute>} />
          <Route path="/super-admin/schools/:id/admin" element={<RoleRoute roles={["super_admin"]}><SchoolAdminCreatePage /></RoleRoute>} />
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </BrowserRouter>
  );
}
