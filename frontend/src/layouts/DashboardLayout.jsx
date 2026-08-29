import { useEffect, useMemo, useState } from "react";
import { NavLink, Outlet, useNavigate } from "react-router-dom";
import useAuth from "../hooks/useAuth";
import { BRAND_NAME, SCHOOL_NAME } from "../config/brand";
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

const resolveUserDisplayName = (user) => {
  const firstName = String(user?.first_name || "").trim();
  const lastName = String(user?.last_name || "").trim();
  const fullName = `${firstName} ${lastName}`.trim();

  if (fullName) {
    return toTitleCase(fullName);
  }

  const emailLocalPart = String(user?.email || "").split("@")[0] || "";
  const fromEmail = emailLocalPart.replace(/[._-]+/g, " ").trim();
  return fromEmail ? toTitleCase(fromEmail) : "Utilisateur";
};

const resolveRoleLabel = (role) => {
  const mapping = {
    super_admin: "Super admin",
    admin: "Admin",
    user: "Utilisateur",
  };

  return mapping[role] || "Utilisateur";
};

export default function DashboardLayout() {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [school, setSchool] = useState({ id: null, name: null, logo_path: null });
  const [logoSrc, setLogoSrc] = useState(defaultLogo);

  useEffect(() => {
    getCurrentSchool().then(setSchool).catch(() => null);
  }, []);

  useEffect(() => {
    setLogoSrc(resolveLogoUrl(school?.logo_path));
  }, [school?.logo_path]);

  const schoolName = useMemo(() => school?.name || SCHOOL_NAME, [school?.name]);
  const userDisplayName = useMemo(() => resolveUserDisplayName(user), [user]);

  const onLogout = () => {
    logout();
    navigate("/");
  };

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="sidebar-head">
          <img
            className="brand-logo"
            src={logoSrc}
            alt={`${schoolName} logo`}
            onError={() => setLogoSrc(defaultLogo)}
          />
          <div>
            <p className="brand-kicker">{BRAND_NAME}</p>
            <h3 className="brand-title">{schoolName}</h3>
            <p className="muted">Connecte: {userDisplayName}</p>
          </div>
        </div>

        <nav>
          <ul className="nav-list">
            {user?.role === "super_admin" ? (
              <>
                <li><NavLink to="/super-admin/dashboard">Vue globale</NavLink></li>
                <li><NavLink to="/super-admin/schools">Ecoles</NavLink></li>
                <li><NavLink to="/admin">Utilisateurs</NavLink></li>
                <li><NavLink to="/schedules">Emploi du temps</NavLink></li>
              </>
            ) : (
              <>
                <li><NavLink to="/dashboard">Tableau de bord</NavLink></li>
                <li><NavLink to="/students">Eleves</NavLink></li>
                <li><NavLink to="/classes">Classes</NavLink></li>
                <li><NavLink to="/schedules">Emploi du temps</NavLink></li>
                <li><NavLink to="/payments">Paiements</NavLink></li>
                <li><NavLink to="/monthly-fees">Mensualites</NavLink></li>
                <li><NavLink to="/unpaid">Impayes</NavLink></li>
                {user?.role === "admin" && (
                  <li><NavLink to="/admin">Equipe</NavLink></li>
                )}
              </>
            )}
          </ul>
        </nav>

        <button className="ghost-btn" onClick={onLogout}>Deconnexion</button>
      </aside>

      <main className="content">
        <header className="topbar">
          <div>
            <p className="brand-kicker">Pilotage financier</p>
            <h2 className="topbar-title">{BRAND_NAME} Centre de pilotage</h2>
          </div>
          <div className="role-pill">{resolveRoleLabel(user?.role)}</div>
        </header>

        <Outlet />
      </main>
    </div>
  );
}
