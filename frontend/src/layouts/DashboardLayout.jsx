import { useEffect, useMemo, useState } from "react";
import { NavLink, Outlet, useLocation, useNavigate } from "react-router-dom";
import NavDropdown from "../components/navigation/NavDropdown";
import useAuth from "../hooks/useAuth";
import useI18n from "../hooks/useI18n";
import { SCHOOL_NAME } from "../config/brand";
import { getCurrentSchool } from "../services/schoolService";
import defaultLogo from "../assets/branding/logo-placeholder.svg";

const API_URL = (import.meta.env.VITE_API_URL || "http://127.0.0.1:8080").replace(/\/+$/, "");

const resolveLogoUrl = (logoPath) => {
  if (!logoPath) {
    return defaultLogo;
  }

  if (/^https?:\/\//i.test(logoPath)) {
    return logoPath;
  }

  const normalized = String(logoPath).replace(/\\/g, "/").replace(/^\/+/, "");
  return `${API_URL}/${normalized}`;
};

const toTitleCase = (value) =>
  value
    .split(" ")
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(" ");

const resolveUserDisplayName = (user, fallback) => {
  const firstName = String(user?.first_name || "").trim();
  const lastName = String(user?.last_name || "").trim();
  const fullName = `${firstName} ${lastName}`.trim();

  if (fullName) {
    return toTitleCase(fullName);
  }

  const emailLocalPart = String(user?.email || "").split("@")[0] || "";
  const fromEmail = emailLocalPart.replace(/[._-]+/g, " ").trim();
  return fromEmail ? toTitleCase(fromEmail) : fallback;
};

export default function DashboardLayout() {
  const { user, logout } = useAuth();
  const { language, setLanguage, t } = useI18n();
  const navigate = useNavigate();
  const location = useLocation();
  const [school, setSchool] = useState({ id: null, name: null, logo_path: null });
  const [logoSrc, setLogoSrc] = useState(defaultLogo);

  useEffect(() => {
    getCurrentSchool().then(setSchool).catch(() => null);
  }, []);

  useEffect(() => {
    setLogoSrc(resolveLogoUrl(school?.logo_path));
  }, [school?.logo_path]);

  const schoolName = useMemo(() => school?.name || SCHOOL_NAME, [school?.name]);
  const userDisplayName = useMemo(() => resolveUserDisplayName(user, t("roles.unknown")), [user, t]);
  const pageTitle = useMemo(() => {
    const path = location.pathname;
    if (path === "/dashboard") return t("navigation.dashboard");
    if (path === "/students/new") return t("navigation.addStudent");
    if (/^\/students\/\d+$/.test(path)) return t("studentProfile.title");
    if (path === "/students") return t("navigation.students");
    if (path === "/classes") return t("navigation.classes");
    if (path === "/teachers") return t("navigation.teachers");
    if (path === "/teacher/dashboard") return t("teacherPortal.dashboardTitle");
    if (path === "/teacher/attendance") return t("teacherPortal.attendanceTitle");
    if (path === "/teacher/assessments") return t("teacherPortal.assessmentsTitle");
    if (/^\/teacher\/assessments\/\d+\/grades$/.test(path)) return t("teacherPortal.gradeEntryTitle");
    if (path === "/teacher/gradebook") return t("teacherPortal.gradebookTitle");
    if (path === "/teacher/profile") return t("teacherAccount.profileTitle");
    if (path === "/schedules") return t("navigation.schedule");
    if (path === "/finances/payments/new") return t("navigation.newPayment");
    if (/^\/finances\/payments\/\d+$/.test(path)) return t("payments.details");
    if (path === "/finances/payments/history") return t("navigation.paymentHistory");
    if (path === "/finances/monthly-fees") return t("navigation.monthlyFees");
    if (path === "/finances/unpaid") return t("navigation.unpaid");
    if (path === "/finances/family-discounts") return "Remises familiales";
    if (path === "/admin") return t("navigation.team");
    if (path === "/super-admin/dashboard") return t("navigation.globalOverview");
    if (path === "/super-admin/schools") return t("navigation.schools");
    if (/^\/super-admin\/schools\/\d+\/admin$/.test(path)) return t("schoolAdmin.title");
    if (/^\/super-admin\/schools\/\d+$/.test(path)) return t("schools.details");
    return t("header.schoolManagement");
  }, [location.pathname, t]);

  const onLogout = () => {
    logout();
    navigate("/");
  };

  return (
    <div className="app-shell">
      <header className="app-header">
        <div className="header-brand">
          <img
            className="brand-logo"
            src={logoSrc}
            alt={t("common.logoAlt", { name: schoolName })}
            onError={() => setLogoSrc(defaultLogo)}
          />
          <div className="header-brand-copy">
            <p className="brand-kicker">{t("header.schoolManagement")}</p>
            <h3 className="brand-title">{schoolName}</h3>
            <p className="muted">{t("common.connectedAs", { name: userDisplayName })}</p>
          </div>
        </div>

        <nav className="header-nav" aria-label={t("header.schoolManagement")}>
          <ul className="nav-list">
            {user?.role === "super_admin" ? (
              <>
                <li><NavLink to="/super-admin/dashboard">{t("navigation.globalOverview")}</NavLink></li>
                <li><NavLink to="/super-admin/schools">{t("navigation.schools")}</NavLink></li>
                <li><NavLink to="/admin">{t("navigation.users")}</NavLink></li>
                <li><NavLink to="/schedules">{t("navigation.schedule")}</NavLink></li>
                <li><NavLink to="/finances/family-discounts">Remises familiales</NavLink></li>
              </>
            ) : user?.role === "professeur" ? (
              <>
                <li><NavLink to="/teacher/dashboard">{t("navigation.dashboard")}</NavLink></li>
                <NavDropdown
                  label={t("navigation.pedagogy")}
                  items={[
                    { to: "/teacher/attendance", label: t("teacherPortal.attendance"), end: true },
                    { to: "/teacher/assessments", label: t("teacherPortal.assessments"), activePrefix: "/teacher/assessments/" },
                    { to: "/teacher/gradebook", label: t("teacherPortal.averages"), end: true },
                    { to: "/schedules", label: t("navigation.schedule"), end: true },
                  ]}
                />
                <li><NavLink to="/teacher/profile">{t("teacherPortal.myAccount")}</NavLink></li>
              </>
            ) : (
              <>
                <NavDropdown
                  label={t("navigation.pilotage")}
                  items={[{ to: "/dashboard", label: t("navigation.dashboard"), end: true }]}
                />
                <NavDropdown
                  label={t("navigation.schooling")}
                  items={[
                    { to: "/students", label: t("navigation.students"), end: true, activePrefix: "/students/" },
                    ...(user?.role === "admin" ? [{ to: "/students/new", label: t("navigation.addStudent"), end: true }] : []),
                    { to: "/classes", label: t("navigation.classes"), end: true },
                  ]}
                />
                <NavDropdown
                  label={t("navigation.pedagogy")}
                  items={[
                    ...(user?.role === "admin" ? [{ to: "/teachers", label: t("navigation.teachers"), end: true }] : []),
                    { to: "/schedules", label: t("navigation.schedule"), end: true },
                  ]}
                />
                {["admin", "user"].includes(user?.role) && (
                  <NavDropdown
                    label={t("navigation.finance")}
                    items={[
                      { to: "/finances/payments/new", label: t("navigation.newPayment"), end: true },
                      { to: "/finances/payments/history", label: t("navigation.paymentHistory"), activePrefix: "/finances/payments/" },
                      { to: "/finances/monthly-fees", label: t("navigation.monthlyFees"), end: true },
                      { to: "/finances/unpaid", label: t("navigation.unpaid"), end: true },
                    ]}
                  />
                )}
                {user?.role === "admin" && (
                  <NavDropdown
                    label={t("navigation.administration")}
                    items={[
                      { to: "/admin", label: t("navigation.team"), end: true },
                    ]}
                  />
                )}
              </>
            )}
          </ul>
        </nav>

        <div className="header-actions">
          <div className="language-switcher" role="group" aria-label={t("common.languageSelector")}>
            <button
              type="button"
              className={language === "fr" ? "active" : ""}
              aria-pressed={language === "fr"}
              onClick={() => setLanguage("fr")}
            >
              FR
            </button>
            <button
              type="button"
              className={language === "ar" ? "active" : ""}
              aria-pressed={language === "ar"}
              onClick={() => setLanguage("ar")}
            >
              AR
            </button>
          </div>
          <div className="role-pill">{t(`roles.${user?.role || "unknown"}`)}</div>
          <button type="button" className="header-logout" onClick={onLogout}>
            {t("common.logout")}
          </button>
        </div>
      </header>

      <main className="content">
        <header className="topbar">
          <div>
            <p className="brand-kicker">{t("header.schoolManagement")}</p>
            <h2 className="topbar-title">{pageTitle}</h2>
          </div>
        </header>

        <Outlet />
      </main>
    </div>
  );
}
