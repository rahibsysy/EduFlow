import { BrowserRouter, Route, Routes } from "react-router-dom";
import ProtectedRoute from "../components/common/ProtectedRoute";
import RoleRoute from "../components/common/RoleRoute";
import DashboardLayout from "../layouts/DashboardLayout";
import DashboardPage from "../pages/DashboardPage";
import LoginPage from "../pages/LoginPage";
import MonthlyFeesPage from "../pages/MonthlyFeesPage";
import NotFoundPage from "../pages/NotFoundPage";
import PaymentsPage from "../pages/PaymentsPage";
import AdminPage from "../pages/AdminPage";
import StudentFormPage from "../pages/StudentFormPage";
import StudentsPage from "../pages/StudentsPage";
import ClassesPage from "../pages/ClassesPage";
import UnpaidPage from "../pages/UnpaidPage";
import SuperAdminDashboardPage from "../pages/SuperAdminDashboardPage";
import SchoolsPage from "../pages/SchoolsPage";
import SchoolDetailsPage from "../pages/SchoolDetailsPage";
import SchoolAdminCreatePage from "../pages/SchoolAdminCreatePage";
import SchedulesPage from "../pages/SchedulesPage";

export default function AppRouter() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<LoginPage />} />

        <Route
          element={
            <ProtectedRoute>
              <DashboardLayout />
            </ProtectedRoute>
          }
        >
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/students" element={<StudentsPage />} />
          <Route path="/classes" element={<ClassesPage />} />
          <Route path="/schedules" element={<SchedulesPage />} />
          <Route path="/students/new" element={<StudentFormPage />} />
          <Route path="/payments" element={<PaymentsPage />} />
          <Route path="/monthly-fees" element={<MonthlyFeesPage />} />
          <Route path="/unpaid" element={<UnpaidPage />} />
          <Route
            path="/admin"
            element={
              <RoleRoute roles={["admin", "super_admin"]}>
                <AdminPage />
              </RoleRoute>
            }
          />

          <Route
            path="/super-admin/dashboard"
            element={
              <RoleRoute roles={["super_admin"]}>
                <SuperAdminDashboardPage />
              </RoleRoute>
            }
          />
          <Route
            path="/super-admin/schools"
            element={
              <RoleRoute roles={["super_admin"]}>
                <SchoolsPage />
              </RoleRoute>
            }
          />
          <Route
            path="/super-admin/schools/:id"
            element={
              <RoleRoute roles={["super_admin"]}>
                <SchoolDetailsPage />
              </RoleRoute>
            }
          />
          <Route
            path="/super-admin/schools/:id/admin"
            element={
              <RoleRoute roles={["super_admin"]}>
                <SchoolAdminCreatePage />
              </RoleRoute>
            }
          />
        </Route>

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </BrowserRouter>
  );
}
