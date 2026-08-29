import { useMemo, useState } from "react";
import { SCHOOL_YEAR_MONTH_OPTIONS } from "../config/schoolOptions";
import { getUnpaidMonthlyFees } from "../services/monthlyFeeService";

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

export default function UnpaidPage() {
  const [items, setItems] = useState([]);
  const [filters, setFilters] = useState({
    search: "",
    class_level: "",
    class_name: "",
    month_label: "",
    year_value: "",
  });
  const [filtersApplied, setFiltersApplied] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const totalRemaining = useMemo(
    () => items.reduce((sum, item) => sum + Number(item.remaining_amount || 0), 0),
    [items]
  );

  const applyFilters = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const data = await getUnpaidMonthlyFees({
        search: filters.search || undefined,
        class_level: filters.class_level || undefined,
        class_name: filters.class_name || undefined,
        month_label: filters.month_label || undefined,
        year_value: filters.year_value || undefined,
      });
      setItems(Array.isArray(data) ? data : []);
      setFiltersApplied(true);
    } catch (err) {
      setItems([]);
      setFiltersApplied(false);
      setError(err?.response?.data?.message || "Impossible de charger les impayes.");
    } finally {
      setLoading(false);
    }
  };

  const resetFilters = () => {
    setFilters({ search: "", class_level: "", class_name: "", month_label: "", year_value: "" });
    setItems([]);
    setFiltersApplied(false);
    setError("");
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>Impayes</h2>
        <p className="muted">Suivi des soldes restants et du retard de paiement.</p>
      </section>

      <section className="kpi-grid one-col">
        <article className="panel kpi">
          <p className="kpi-label">Total a recouvrer</p>
          <h2>{formatMoney(totalRemaining)}</h2>
          <p className="muted">Montant cumule de toutes les mensualites non reglees</p>
        </article>
      </section>

      <section className="panel">
        {error && <p className="error-text">{error}</p>}
        <form className="filters-grid" onSubmit={applyFilters}>
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
          <select
            value={filters.month_label}
            onChange={(e) => setFilters({ ...filters, month_label: e.target.value })}
          >
            <option value="">Tous les mois</option>
            {SCHOOL_YEAR_MONTH_OPTIONS.map((month) => (
              <option key={month.value} value={month.value}>{month.label}</option>
            ))}
          </select>
          <input
            type="number"
            placeholder="Annee"
            value={filters.year_value}
            onChange={(e) => setFilters({ ...filters, year_value: e.target.value })}
          />
          <button type="button" className="secondary-btn" onClick={resetFilters}>
            Réinitialiser
          </button>
          <button type="submit" disabled={loading}>
            {loading ? "Chargement..." : "Appliquer"}
          </button>
        </form>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Eleve</th>
                <th>Niveau</th>
                <th>Classe</th>
                <th>Parent</th>
                <th>Telephone</th>
                <th>Mois</th>
                <th>Annee</th>
                <th>Reste</th>
                <th>Echeance</th>
                <th>Statut</th>
                <th>Jours de retard</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>
                    {item.first_name} {item.last_name}
                  </td>
                  <td>{item.class_level_name || "-"}</td>
                  <td>{item.class_group_name || "-"}</td>
                  <td>{item.parent_name}</td>
                  <td>{item.phone || "-"}</td>
                  <td>{item.month_label}</td>
                  <td>{item.year_value}</td>
                  <td>{formatMoney(item.remaining_amount)}</td>
                  <td>{item.due_date || "-"}</td>
                  <td>{statusLabel(item.status)}</td>
                  <td>{item.days_late ?? "-"}</td>
                </tr>
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan="11" className="table-empty">
                    {filtersApplied ? "Aucun impaye trouve." : "Appliquez un filtre pour afficher les impayes."}
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
