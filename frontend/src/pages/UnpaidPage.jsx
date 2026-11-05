import { useMemo, useState } from "react";
import { SCHOOL_YEAR_MONTH_OPTIONS } from "../config/schoolOptions";
import { getConsolidatedUnpaidItems } from "../services/financialDataService";
import useI18n from "../hooks/useI18n";
import { downloadUnpaidPdf } from "../services/paymentDocumentService";

const formatMoney = (value, language) =>
  new Intl.NumberFormat(language === "ar" ? "ar-MA" : "fr-MA", { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

const formatDate = (value, language) =>
  value ? new Intl.DateTimeFormat(language === "ar" ? "ar-MA" : "fr-MA").format(new Date(value)) : "-";

const statusLabel = (value, t) => {
  if (value === "PAID") return t("statuses.paid");
  if (value === "PARTIAL") return t("statuses.partial");
  if (value === "UNPAID") return t("statuses.unpaid");
  return value || "-";
};

export default function UnpaidPage() {
  const { language, t } = useI18n();
  const [items, setItems] = useState([]);
  const [summary, setSummary] = useState({ total_remaining: 0, overdue: { total_remaining: 0 } });
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
  const [exporting, setExporting] = useState(false);

  const totalRemaining = useMemo(() => Number(summary.total_remaining || 0), [summary]);
  const totalOverdue = useMemo(() => Number(summary.overdue?.total_remaining || 0), [summary]);

  const applyFilters = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const data = await getConsolidatedUnpaidItems({
        search: filters.search || undefined,
        class_level: filters.class_level || undefined,
        class_name: filters.class_name || undefined,
        month_label: filters.month_label || undefined,
        year_value: filters.year_value || undefined,
      });
      setItems(Array.isArray(data?.items) ? data.items : []);
      setSummary(data?.summary || { total_remaining: 0, overdue: { total_remaining: 0 } });
      setFiltersApplied(true);
    } catch (err) {
      setItems([]);
      setSummary({ total_remaining: 0, overdue: { total_remaining: 0 } });
      setFiltersApplied(false);
      setError(t("unpaid.loadError"));
    } finally {
      setLoading(false);
    }
  };

  const resetFilters = () => {
    setFilters({ search: "", class_level: "", class_name: "", month_label: "", year_value: "" });
    setItems([]);
    setSummary({ total_remaining: 0, overdue: { total_remaining: 0 } });
    setFiltersApplied(false);
    setError("");
  };

  const exportPdf = async () => {
    if (exporting) {
      return;
    }
    setExporting(true);
    setError("");
    try {
      await downloadUnpaidPdf({
        search: filters.search || undefined,
        class_level: filters.class_level || undefined,
        class_name: filters.class_name || undefined,
        month_label: filters.month_label || undefined,
        year_value: filters.year_value || undefined,
      });
    } catch {
      setError("Impossible d’exporter les impayés.");
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>{t("unpaid.title")}</h2>
        <p className="muted">{t("unpaid.description")}</p>
      </section>

      <section className="kpi-grid one-col">
        <article className="panel kpi">
          <p className="kpi-label">{t("unpaid.totalToRecover")}</p>
          <h2>{formatMoney(totalRemaining, language)}</h2>
          <p className="muted">{t("unpaid.totalHelp")}</p>
        </article>
        <article className="panel kpi">
          <p className="kpi-label">Montant en retard prouvé</p>
          <h2>{formatMoney(totalOverdue, language)}</h2>
          <p className="muted">Uniquement les frais avec une échéance enregistrée et dépassée.</p>
        </article>
      </section>

      <section className="panel">
        {error && <p className="error-text">{error}</p>}
        <form className="filters-grid" onSubmit={applyFilters}>
          <input
            placeholder={t("monthlyFees.searchStudent")}
            value={filters.search}
            onChange={(e) => setFilters({ ...filters, search: e.target.value })}
          />
          <input
            placeholder={t("monthlyFees.filterLevel")}
            value={filters.class_level}
            onChange={(e) => setFilters({ ...filters, class_level: e.target.value })}
          />
          <input
            placeholder={t("monthlyFees.filterClass")}
            value={filters.class_name}
            onChange={(e) => setFilters({ ...filters, class_name: e.target.value })}
          />
          <select
            value={filters.month_label}
            onChange={(e) => setFilters({ ...filters, month_label: e.target.value })}
          >
            <option value="">{t("unpaid.allMonths")}</option>
            {SCHOOL_YEAR_MONTH_OPTIONS.map((month) => (
              <option key={month.value} value={month.value}>{t(`months.${month.value}`)}</option>
            ))}
          </select>
          <input
            type="number"
            placeholder={t("common.year")}
            value={filters.year_value}
            onChange={(e) => setFilters({ ...filters, year_value: e.target.value })}
          />
          <button type="button" className="secondary-btn" onClick={resetFilters}>
            {t("common.reset")}
          </button>
          <button type="submit" disabled={loading}>
            {loading ? t("common.loading") : t("common.apply")}
          </button>
          <button type="button" onClick={exportPdf} disabled={exporting}>{exporting ? "Export en cours…" : "Exporter les impayés consolidés en PDF"}</button>
        </form>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{t("common.student")}</th>
                <th>{t("common.level")}</th>
                <th>{t("common.class")}</th>
                <th>{t("common.parent")}</th>
                <th>{t("common.phone")}</th>
                <th>Type</th>
                <th>Libellé</th>
                <th>{t("common.schoolYear")}</th>
                <th>Initial</th>
                <th>Remise</th>
                <th>Montant dû</th>
                <th>{t("monthlyFees.paid")}</th>
                <th>{t("monthlyFees.remaining")}</th>
                <th>{t("unpaid.dueDate")}</th>
                <th>{t("common.status")}</th>
                <th>{t("unpaid.daysLate")}</th>
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
                  <td>{item.fee_type === "MONTHLY" ? "Mensualité" : "Frais flexible"}</td>
                  <td>{item.label}</td>
                  <td>{item.academic_year_name || item.year_value || "-"}</td>
                  <td>{formatMoney(item.initial_amount, language)}</td>
                  <td>{formatMoney(item.discount_amount, language)}</td>
                  <td>{formatMoney(item.final_amount, language)}</td>
                  <td>{formatMoney(item.paid_amount, language)}</td>
                  <td>{formatMoney(item.remaining_amount, language)}</td>
                  <td>{formatDate(item.due_date, language)}</td>
                  <td>{statusLabel(item.status, t)}</td>
                  <td>{item.is_overdue ? item.days_late : "-"}</td>
                </tr>
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan="16" className="table-empty">
                    {filtersApplied ? t("unpaid.emptyFiltered") : t("unpaid.emptyInitial")}
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
