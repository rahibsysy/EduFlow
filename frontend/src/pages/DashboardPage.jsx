import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import useAuth from "../hooks/useAuth";
import useI18n from "../hooks/useI18n";
import { getDashboardStats } from "../services/dashboardService";
import { SCHOOL_NAME } from "../config/brand";

const localeFor = (language) => (language === "ar" ? "ar-MA" : "fr-MA");

const formatMoney = (value, language) =>
  new Intl.NumberFormat(localeFor(language), { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

const formatDate = (value, language) => {
  if (!value) return "-";
  return new Intl.DateTimeFormat(localeFor(language)).format(new Date(value));
};

export default function DashboardPage() {
  const { user } = useAuth();
  const { language, t } = useI18n();
  const [stats, setStats] = useState({
    total_due: 0,
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
  const [hasError, setHasError] = useState(false);

  useEffect(() => {
    setLoading(true);
    setHasError(false);

    getDashboardStats()
      .then(setStats)
      .catch(() => setHasError(true))
      .finally(() => setLoading(false));
  }, []);

  const schoolName = user?.school_name || SCHOOL_NAME;
  const cards = useMemo(
    () => [
      {
        label: t("dashboard.totalDue"),
        value: formatMoney(stats.total_due, language),
        helper: t("dashboard.totalDueHelp"),
        to: "/finances/unpaid",
      },
      {
        label: t("dashboard.collectedAmount"),
        value: formatMoney(stats.total_collected, language),
        helper: t("dashboard.collectedAmountHelp"),
        to: "/finances/payments/history",
      },
      {
        label: t("dashboard.amountToRecover"),
        value: formatMoney(stats.total_unpaid, language),
        helper: t("dashboard.amountToRecoverHelp"),
        to: "/finances/unpaid",
      },
      {
        label: t("dashboard.lateStudents"),
        value: Number(stats.late_students || 0).toString(),
        helper: t("dashboard.lateStudentsHelp"),
        to: "/finances/unpaid",
      },
      {
        label: t("dashboard.totalStudents"),
        value: Number(stats.students_count || 0).toString(),
        helper: t("dashboard.totalStudentsHelp"),
        to: "/students",
      },
      {
        label: t("dashboard.coverageRate"),
        value: `${Number(stats.coverage_rate || 0).toFixed(2)}%`,
        helper: t("dashboard.coverageRateHelp"),
        to: "/finances/monthly-fees",
      },
    ],
    [language, stats, t]
  );

  if (loading) {
    return (
      <section className="panel">
        <h2>{t("dashboard.title")}</h2>
        <p className="muted">{t("dashboard.loading")}</p>
      </section>
    );
  }

  if (hasError) {
    return (
      <section className="panel">
        <h2>{t("dashboard.title")}</h2>
        <p className="error-text">{t("dashboard.loadError")}</p>
      </section>
    );
  }

  return (
    <div className="admin-grid">
      <section className="panel hero-panel hero-modern">
        <p className="brand-kicker">{t("dashboard.operationalView")}</p>
        <h1>{t("dashboard.financialCenter", { school: schoolName })}</h1>
        <p className="muted">{t("dashboard.description")}</p>
      </section>

      <section className="kpi-grid">
        {cards.map((card) => (
          <Link key={card.label} className="kpi-link" to={card.to} aria-label={`${card.label}: ${card.value}`}>
            <article className="panel kpi kpi-modern">
              <p className="kpi-label">{card.label}</p>
              <h2>{card.value}</h2>
              <p className="muted">{card.helper}</p>
            </article>
          </Link>
        ))}
      </section>

      <section className="split-panel">
        <article className="panel">
          <h3>{t("dashboard.recentPayments")}</h3>
          <p className="muted">{t("dashboard.recentPaymentsHelp")}</p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>{t("dashboard.date")}</th>
                  <th>{t("dashboard.student")}</th>
                  <th>{t("dashboard.amount")}</th>
                  <th>{t("dashboard.method")}</th>
                </tr>
              </thead>
              <tbody>
                {(stats.recent_payments || []).map((row) => (
                  <tr key={row.id}>
                    <td>{formatDate(row.payment_date, language)}</td>
                    <td><Link className="text-link" to={`/students/${row.student_id}`}>{row.student_name || "-"}</Link></td>
                    <td>{formatMoney(row.amount_paid, language)}</td>
                    <td>{row.payment_method || "-"}</td>
                  </tr>
                ))}
                {!(stats.recent_payments || []).length && (
                  <tr>
                    <td colSpan="4" className="table-empty">{t("dashboard.noRecentPayments")}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </article>

        <article className="panel">
          <h3>{t("dashboard.topUnpaid")}</h3>
          <p className="muted">{t("dashboard.topUnpaidHelp")}</p>
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>{t("dashboard.student")}</th>
                  <th>{t("dashboard.remainingAmount")}</th>
                </tr>
              </thead>
              <tbody>
                {(stats.top_unpaid_students || []).map((row) => (
                  <tr key={row.student_id}>
                    <td><Link className="text-link" to={`/students/${row.student_id}`}>{row.student_name || "-"}</Link></td>
                    <td>{formatMoney(row.total_remaining, language)}</td>
                  </tr>
                ))}
                {!(stats.top_unpaid_students || []).length && (
                  <tr>
                    <td colSpan="2" className="table-empty">{t("dashboard.noUnpaidFees")}</td>
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
