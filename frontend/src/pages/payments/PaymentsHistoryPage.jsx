import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { MONTH_OPTIONS, normalizeSearch } from "../../config/schoolOptions";
import useI18n from "../../hooks/useI18n";
import { getPayments } from "../../services/paymentService";
import { downloadReceiptPdf } from "../../services/receiptService";
import { downloadMonthlyPaymentsPdf } from "../../services/paymentDocumentService";

const formatMoney = (value, language) => new Intl.NumberFormat(
  language === "ar" ? "ar-MA" : "fr-MA",
  { style: "currency", currency: "MAD" }
).format(Number(value || 0));

const formatDate = (value, language) => value
  ? new Intl.DateTimeFormat(language === "ar" ? "ar-MA" : "fr-MA").format(new Date(value))
  : "-";

const statusLabel = (value, t) => {
  if (value === "CANCELLED") return "Annulé";
  if (value === "PAID") return t("statuses.paid");
  if (value === "PARTIAL") return t("statuses.partial");
  if (value === "UNPAID") return t("statuses.unpaid");
  return value || "-";
};

const paymentMethodLabel = (value, t) => {
  const normalized = String(value || "").trim().toUpperCase();
  const keys = {
    CASH: "methodCash", ESPECES: "methodCash", "ESPÈCES": "methodCash",
    CARD: "methodCard", "CARTE BANCAIRE": "methodCard",
    BANK_TRANSFER: "methodBankTransfer", "VIREMENT BANCAIRE": "methodBankTransfer",
    CHECK: "methodCheck", CHEQUE: "methodCheck", "CHÈQUE": "methodCheck",
    MOBILE: "methodMobile", "PAIEMENT MOBILE": "methodMobile",
  };
  return keys[normalized] ? t(`payments.${keys[normalized]}`) : value || "-";
};

const emptyFilters = { last_name: "", first_name: "", class_level: "", class_name: "", month_label: "", year_value: "", status: "" };

export default function PaymentsHistoryPage() {
  const { language, t } = useI18n();
  const [payments, setPayments] = useState([]);
  const [filters, setFilters] = useState(emptyFilters);
  const [downloadingId, setDownloadingId] = useState(null);
  const [exporting, setExporting] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    getPayments()
      .then((data) => setPayments(Array.isArray(data) ? data : []))
      .catch(() => setError(t("payments.loadError")));
  }, [t]);

  const totalPaid = useMemo(() => payments.filter((item) => item.payment_status !== "CANCELLED").reduce((sum, item) => sum + Number(item.amount_paid || 0), 0), [payments]);
  const levelOptions = useMemo(() => Array.from(new Set(payments.map((item) => item.class_level_name).filter(Boolean))).sort((a, b) => a.localeCompare(b)), [payments]);
  const filteredPayments = useMemo(() => {
    const includes = (value, search) => !search || normalizeSearch(value).includes(normalizeSearch(search));
    return payments.filter((payment) => (
      includes(payment.last_name, filters.last_name)
      && includes(payment.first_name, filters.first_name)
      && includes(payment.class_level_name, filters.class_level)
      && includes(payment.class_name, filters.class_name)
      && (!filters.month_label || String(payment.month_label).padStart(2, "0") === filters.month_label)
      && (!filters.year_value || String(payment.year_value) === String(filters.year_value))
      && (!filters.status || payment.monthly_fee_status === filters.status || payment.payment_status === filters.status)
    ));
  }, [filters, payments]);

  const downloadReceipt = async (paymentId) => {
    setDownloadingId(paymentId);
    setError("");
    try {
      await downloadReceiptPdf(paymentId, language);
    } catch {
      setError(t("payments.downloadError"));
    } finally {
      setDownloadingId(null);
    }
  };

  const exportMonth = async () => {
    if (!filters.month_label || !filters.year_value || exporting) {
      setError("Sélectionnez un mois et une année avant l’export PDF.");
      return;
    }
    setExporting(true);
    setError("");
    try {
      await downloadMonthlyPaymentsPdf({ month_label: filters.month_label, year_value: filters.year_value });
    } catch {
      setError("Impossible d’exporter les paiements.");
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="admin-grid">
      <section className="panel hero-modern panel-header">
        <div>
          <p className="brand-kicker">{t("navigation.finance")}</p>
          <h2>{t("payments.history")}</h2>
          <p className="muted">{t("payments.historyDescription")}</p>
        </div>
        <Link className="button-link" to="/finances/payments/new">+ {t("payments.newPayment")}</Link>
      </section>

      <section className="kpi-grid two-col">
        <article className="panel kpi"><p className="kpi-label">{t("payments.recordedOperations")}</p><h2>{payments.length}</h2></article>
        <article className="panel kpi"><p className="kpi-label">{t("payments.totalCollected")}</p><h2>{formatMoney(totalPaid, language)}</h2></article>
      </section>

      <section className="panel">
        <h3>{t("monthlyFees.filters")}</h3>
        <div className="filters-grid">
          <input placeholder={t("payments.filterLastName")} value={filters.last_name} onChange={(event) => setFilters({ ...filters, last_name: event.target.value })} />
          <input placeholder={t("payments.filterFirstName")} value={filters.first_name} onChange={(event) => setFilters({ ...filters, first_name: event.target.value })} />
          <select value={filters.class_level} onChange={(event) => setFilters({ ...filters, class_level: event.target.value })}>
            <option value="">{t("payments.allLevels")}</option>
            {levelOptions.map((level) => <option key={level} value={level}>{level}</option>)}
          </select>
          <input placeholder={t("payments.filterClass")} value={filters.class_name} onChange={(event) => setFilters({ ...filters, class_name: event.target.value })} />
          <select value={filters.month_label} onChange={(event) => setFilters({ ...filters, month_label: event.target.value })}>
            <option value="">{t("payments.allMonths")}</option>
            {MONTH_OPTIONS.map((month) => <option key={month.value} value={month.value}>{t(`months.${month.value}`)}</option>)}
          </select>
          <input type="number" min="2000" max="2100" placeholder={t("common.year")} value={filters.year_value} onChange={(event) => setFilters({ ...filters, year_value: event.target.value })} />
          <select value={filters.status} onChange={(event) => setFilters({ ...filters, status: event.target.value })}>
            <option value="">{t("payments.allStatuses")}</option>
            <option value="PAID">{t("statuses.paid")}</option>
            <option value="PARTIAL">{t("statuses.partial")}</option>
            <option value="UNPAID">{t("statuses.unpaid")}</option>
            <option value="CANCELLED">Annulé</option>
          </select>
          <button type="button" className="secondary-btn" onClick={() => setFilters(emptyFilters)}>{t("common.resetFilters")}</button>
          <button type="button" onClick={exportMonth} disabled={exporting}>{exporting ? "Export en cours…" : "Exporter les paiements en PDF"}</button>
        </div>
      </section>

      <section className="panel">
        {error && <p className="error-text">{error}</p>}
        <div className="table-wrap">
          <table>
            <thead><tr>
              <th>{t("common.id")}</th><th>{t("common.student")}</th><th>{t("common.level")}</th><th>{t("common.class")}</th>
              <th>{t("payments.period")}</th><th>{t("common.amount")}</th><th>{t("common.date")}</th><th>{t("payments.method")}</th>
              <th>{t("common.status")}</th><th>{t("common.actions")}</th>
            </tr></thead>
            <tbody>
              {filteredPayments.map((payment) => (
                <tr key={payment.id}>
                  <td>{payment.id}</td><td>{payment.first_name} {payment.last_name}</td><td>{payment.class_level_name || "-"}</td><td>{payment.class_name || "-"}</td>
                  <td>{t(`months.${String(payment.month_label).padStart(2, "0")}`)} {payment.year_value}</td>
                  <td>{formatMoney(payment.amount_paid, language)}</td><td>{formatDate(payment.payment_date, language)}</td>
                  <td>{paymentMethodLabel(payment.payment_method_label || payment.payment_method, t)}</td><td>{statusLabel(payment.payment_status === "CANCELLED" ? "CANCELLED" : payment.monthly_fee_status, t)}</td>
                  <td><div className="table-actions payment-table-actions">
                    <Link className="secondary-btn button-link" to={`/finances/payments/${payment.id}`}>{t("payments.viewDetails")}</Link>
                    <button type="button" onClick={() => downloadReceipt(payment.id)} disabled={downloadingId === payment.id}>{t("payments.downloadReceipt")}</button>
                  </div></td>
                </tr>
              ))}
              {filteredPayments.length === 0 && <tr><td colSpan="10" className="table-empty">{t("payments.empty")}</td></tr>}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
