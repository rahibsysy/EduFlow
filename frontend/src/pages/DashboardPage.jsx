import { useEffect, useMemo, useState } from "react";
import { getDashboardStats } from "../services/dashboardService";
import { SCHOOL_NAME } from "../config/brand";

const formatMoney = (value) =>
  new Intl.NumberFormat("fr-MA", { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

const formatDate = (value) => {
  if (!value) return "-";
  return new Intl.DateTimeFormat("fr-FR").format(new Date(value));
};

export default function DashboardPage() {
  const [stats, setStats] = useState({
    total_collected: 0,
    total_unpaid: 0,
    late_students: 0,
    students_count: 0,
    payments_count: 0,
    coverage_rate: 0,
    recent_payments: [],
    top_unpaid_students: [],
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    setLoading(true);
    setError("");

    getDashboardStats()
      .then((statsData) => {
        setStats(statsData);
      })
      .catch(() => setError("Impossible de charger le dashboard."))
      .finally(() => setLoading(false));
  }, []);

  const cards = useMemo(
    () => [
      {
        label: "Montant encaisse",
        value: formatMoney(stats.total_collected),
        helper: "Paiements valides enregistres",
      },
      {
        label: "Montant a recouvrer",
        value: formatMoney(stats.total_unpaid),
        helper: "Factures impayees et partielles",
      },
      {
        label: "Eleves en retard",
        value: Number(stats.late_students || 0).toString(),
        helper: "Suivi des echeances non reglees",
      },
      {
        label: "Total eleves",
        value: Number(stats.students_count || 0).toString(),
        helper: "Base eleves active",
      },
      {
        label: "Taux de couverture",
        value: `${Number(stats.coverage_rate || 0).toFixed(2)}%`,
        helper: "Encaisse / (Encaisse + Impaye)",
      },
    ],
    [stats]
  );

  if (loading) {
    return (
      <section className="panel">
        <h2>Dashboard</h2>
        <p className="muted">Chargement...</p>
      </section>
    );
  }

  if (error) {
    return (
      <section className="panel">
        <h2>Dashboard</h2>
        <p className="error-text">{error}</p>
      </section>
    );
  }

  return (
    <div className="admin-grid">
      <section className="panel hero-panel hero-modern">
        <p className="brand-kicker">Vue operationnelle</p>
        <h1>Centre financier {SCHOOL_NAME}</h1>
        <p className="muted">
          Suivi quotidien des encaissements, impayes et priorites de recouvrement.
        </p>
      </section>

      <section className="kpi-grid">
        {cards.map((card) => (
          <article key={card.label} className="panel kpi kpi-modern">
            <p className="kpi-label">{card.label}</p>
            <h2>{card.value}</h2>
            <p className="muted">{card.helper}</p>
          </article>
        ))}
      </section>

      <section className="split-panel">
        <article className="panel">
          <h3>Paiements recents</h3>
          <p className="muted">5 derniers encaissements</p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Eleve</th>
                  <th>Montant</th>
                  <th>Mode</th>
                </tr>
              </thead>
              <tbody>
                {(stats.recent_payments || []).map((row) => (
                  <tr key={row.id}>
                    <td>{formatDate(row.payment_date)}</td>
                    <td>{row.student_name || "-"}</td>
                    <td>{formatMoney(row.amount_paid)}</td>
                    <td>{row.payment_method || "-"}</td>
                  </tr>
                ))}
                {!(stats.recent_payments || []).length && (
                  <tr>
                    <td colSpan="4" className="table-empty">Aucun paiement recent.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </article>

        <article className="panel">
          <h3>Top impayes</h3>
          <p className="muted">5 eleves avec le plus grand reste a payer</p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Eleve</th>
                  <th>Reste a payer</th>
                </tr>
              </thead>
              <tbody>
                {(stats.top_unpaid_students || []).map((row) => (
                  <tr key={row.student_id}>
                    <td>{row.student_name || "-"}</td>
                    <td>{formatMoney(row.total_remaining)}</td>
                  </tr>
                ))}
                {!(stats.top_unpaid_students || []).length && (
                  <tr>
                    <td colSpan="2" className="table-empty">Aucun impaye.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </article>
      </section>
    </div>
  );
}
