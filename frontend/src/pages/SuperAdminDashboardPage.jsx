import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { getSuperAdminDashboard } from "../services/schoolService";

const formatMoney = (value) =>
  new Intl.NumberFormat("fr-MA", { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

export default function SuperAdminDashboardPage() {
  const [stats, setStats] = useState({
    schools_total: 0,
    schools_active: 0,
    users_total: 0,
    users_active: 0,
    total_collected: 0,
    outstanding_balance: 0,
  });

  useEffect(() => {
    getSuperAdminDashboard()
      .then(setStats)
      .catch(() => null);
  }, []);

  return (
    <div className="admin-grid">
      <section className="panel hero-panel">
        <p className="brand-kicker">Vision globale</p>
        <h1>Pilotage central des etablissements</h1>
        <p className="muted">Vue consolidee des ecoles, des acces et de la performance financiere.</p>
      </section>

      <section className="kpi-grid">
        <article className="panel kpi"><p className="kpi-label">Ecoles</p><h2>{stats.schools_total}</h2></article>
        <article className="panel kpi"><p className="kpi-label">Ecoles actives</p><h2>{stats.schools_active}</h2></article>
        <article className="panel kpi"><p className="kpi-label">Utilisateurs</p><h2>{stats.users_total}</h2></article>
      </section>

      <section className="panel split-panel">
        <div>
          <h3>Operations centrales</h3>
          <p className="muted">Gerez les ecoles, le branding et les droits d'acces.</p>
          <div className="chips">
            <Link to="/super-admin/schools"><span>Gestion des ecoles</span></Link>
            <span>Encaisse: {formatMoney(stats.total_collected)}</span>
            <span>Solde restant: {formatMoney(stats.outstanding_balance)}</span>
          </div>
        </div>
      </section>
    </div>
  );
}
