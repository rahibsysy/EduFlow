import { useMemo, useState } from "react";
import { SCHOOL_YEAR_MONTH_OPTIONS } from "../config/schoolOptions";
import { getMonthlyFees } from "../services/monthlyFeeService";

const formatMoney = (value) =>
  new Intl.NumberFormat("fr-MA", { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

const statusLabel = (value) => {
  if (value === "PAID") return "Paye";
  if (value === "PARTIAL") return "Partiel";
  if (value === "UNPAID") return "Impaye";
  return value || "-";
};

export default function MonthlyFeesPage() {
  const [monthlyFees, setMonthlyFees] = useState([]);
  const [filters, setFilters] = useState({
    search: "",
    class_level: "",
    class_name: "",
    month_label: "09",
    year_value: "",
    status: "",
  });
  const [filtersApplied, setFiltersApplied] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const loadData = async (params) => {
    const data = await getMonthlyFees(params);
    setMonthlyFees(Array.isArray(data) ? data : []);
  };

  const totals = useMemo(() => {
    return monthlyFees.reduce(
      (acc, item) => {
        acc.total += Number(item.total_amount || 0);
        acc.paid += Number(item.amount_paid || 0);
        acc.remaining += Number(item.remaining_amount || 0);
        return acc;
      },
      { total: 0, paid: 0, remaining: 0 }
    );
  }, [monthlyFees]);

  const applyFilters = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const params = {
        search: filters.search || undefined,
        class_level: filters.class_level || undefined,
        class_name: filters.class_name || undefined,
        month_label: filters.month_label || undefined,
        year_value: filters.year_value || undefined,
        status: filters.status || undefined,
      };
      await loadData(params);
      setFiltersApplied(true);
    } catch {
      setMonthlyFees([]);
      setFiltersApplied(false);
      setError("Impossible d'appliquer les filtres.");
    } finally {
      setLoading(false);
    }
  };

  const resetFilters = () => {
    setFilters({
      search: "",
      class_level: "",
      class_name: "",
      month_label: "09",
      year_value: "",
      status: "",
    });
    setMonthlyFees([]);
    setFiltersApplied(false);
    setError("");
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>Mensualites</h2>
        <p className="muted">Suivi des dues mensuelles par eleve et par periode.</p>
      </section>

      <section className="kpi-grid three-col">
        <article className="panel kpi">
          <p className="kpi-label">Total facture</p>
          <h2>{formatMoney(totals.total)}</h2>
        </article>
        <article className="panel kpi">
          <p className="kpi-label">Total encaisse</p>
          <h2>{formatMoney(totals.paid)}</h2>
        </article>
        <article className="panel kpi">
          <p className="kpi-label">Total restant</p>
          <h2>{formatMoney(totals.remaining)}</h2>
        </article>
      </section>

      <section className="panel">
        <h3>Filtres</h3>
        <form className="form-grid" onSubmit={applyFilters}>
          <select
            value={filters.month_label}
            onChange={(e) => setFilters({ ...filters, month_label: e.target.value })}
          >
            {SCHOOL_YEAR_MONTH_OPTIONS.map((month) => (
              <option key={month.value} value={month.value}>{month.label}</option>
            ))}
          </select>
          <input
            placeholder="Rechercher un eleve"
            value={filters.search}
            onChange={(e) => setFilters({ ...filters, search: e.target.value })}
          />
          <input
            placeholder="Filtrer par niveau"
            value={filters.class_level}
            onChange={(e) => setFilters({ ...filters, class_level: e.target.value })}
          />
          <input
            placeholder="Filtrer par classe/groupe"
            value={filters.class_name}
            onChange={(e) => setFilters({ ...filters, class_name: e.target.value })}
          />
          <input
            type="number"
            placeholder="Annee"
            value={filters.year_value}
            onChange={(e) => setFilters({ ...filters, year_value: e.target.value })}
          />
          <select
            value={filters.status}
            onChange={(e) => setFilters({ ...filters, status: e.target.value })}
          >
            <option value="">Tous les statuts</option>
            <option value="PAID">Paye</option>
            <option value="PARTIAL">Partiel</option>
            <option value="UNPAID">Impaye</option>
          </select>
          <button type="button" className="secondary-btn" onClick={resetFilters}>
            Réinitialiser
          </button>
          <button type="submit" disabled={loading}>
            {loading ? "Chargement..." : "Appliquer"}
          </button>
        </form>
        {error && <p className="error-text">{error}</p>}
      </section>

      <section className="panel">
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Eleve</th>
                <th>Niveau</th>
                <th>Mois</th>
                <th>Annee</th>
                <th>Total</th>
                <th>Paye</th>
                <th>Reste</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              {monthlyFees.map((fee) => (
                <tr key={fee.id}>
                  <td>
                    {fee.first_name} {fee.last_name}
                  </td>
                  <td>{fee.class_level_name || "-"}</td>
                  <td>{fee.month_label}</td>
                  <td>{fee.year_value}</td>
                  <td>{formatMoney(fee.total_amount)}</td>
                  <td>{formatMoney(fee.amount_paid)}</td>
                  <td>{formatMoney(fee.remaining_amount)}</td>
                  <td>{statusLabel(fee.status)}</td>
                </tr>
              ))}
              {monthlyFees.length === 0 && (
                <tr>
                  <td colSpan="8" className="table-empty">
                    {filtersApplied ? "Aucune mensualite trouvee." : "Appliquez un filtre pour afficher les mensualites."}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
