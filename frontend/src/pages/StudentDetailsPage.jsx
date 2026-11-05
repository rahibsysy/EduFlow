import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import useI18n from "../hooks/useI18n";
import { downloadReceiptPdf } from "../services/receiptService";
import { downloadStudentPaymentsPdf } from "../services/paymentDocumentService";
import { getStudentById, uploadStudentPhoto } from "../services/studentService";
import useAuth from "../hooks/useAuth";

const API_URL = (import.meta.env.VITE_API_URL || "http://127.0.0.1:8080").replace(/\/+$/, "");
const localeFor = (language) => (language === "ar" ? "ar-MA" : "fr-MA");
const formatMoney = (value, language) => new Intl.NumberFormat(localeFor(language), {
  style: "currency",
  currency: "MAD",
}).format(Number(value || 0));
const formatDate = (value, language) => value
  ? new Intl.DateTimeFormat(localeFor(language)).format(new Date(`${String(value).slice(0, 10)}T00:00:00`))
  : "-";
const resolvePhoto = (path) => {
  if (!path) return "";
  if (/^https?:\/\//i.test(path)) return path;
  return `${API_URL}/${String(path).replace(/\\/g, "/").replace(/^\/+/, "")}`;
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

export default function StudentDetailsPage() {
  const { id } = useParams();
  const { language, t } = useI18n();
  const { can } = useAuth();
  const [details, setDetails] = useState(null);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [downloadingId, setDownloadingId] = useState(null);
  const [exportingPayments, setExportingPayments] = useState(false);
  const [error, setError] = useState("");

  const loadStudent = () => {
    setLoading(true);
    setError("");
    getStudentById(id)
      .then(setDetails)
      .catch(() => setError(t("studentProfile.loadError")))
      .finally(() => setLoading(false));
  };

  useEffect(loadStudent, [id, t]);

  const student = details?.student || {};
  const finance = details?.finance || {};
  const financialItems = details?.financial_items || [];
  const monthlyItems = financialItems.filter((item) => item.source_type === "LEGACY_MONTHLY_FEE");
  const flexibleItems = financialItems.filter((item) => item.source_type === "FLEXIBLE_ITEM");
  const financialPayments = details?.financial_payments || details?.payments || [];
  const canViewFinance = can("finance.view");
  const canManageFinance = can("finance.manage");
  const initials = useMemo(() => `${student.first_name?.[0] || ""}${student.last_name?.[0] || ""}`.toUpperCase(), [student.first_name, student.last_name]);
  const photoUrl = resolvePhoto(student.photo_path);

  const changePhoto = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setError("");
    try {
      await uploadStudentPhoto(id, file);
      await getStudentById(id).then(setDetails);
    } catch {
      setError(t("studentProfile.photoError"));
    } finally {
      setUploading(false);
      event.target.value = "";
    }
  };

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

  const exportPaymentHistory = async () => {
    if (exportingPayments) return;
    setExportingPayments(true);
    setError("");
    try {
      await downloadStudentPaymentsPdf(id, {});
    } catch {
      setError("Impossible d’exporter l’historique des paiements.");
    } finally {
      setExportingPayments(false);
    }
  };

  if (loading) return <section className="panel"><p>{t("common.loading")}</p></section>;
  if (!details) return <section className="panel"><p className="error-text">{error || t("studentProfile.loadError")}</p></section>;

  return (
    <div className="admin-grid student-profile-page">
      <section className="panel hero-modern panel-header">
        <div>
          <p className="brand-kicker">{t("students.title")}</p>
          <h1>{student.first_name} {student.last_name}</h1>
          <p className="muted">{t("studentProfile.description")}</p>
        </div>
        <div className="table-actions student-profile-main-actions">
          <Link className="secondary-btn button-link" to="/students">{t("studentProfile.backToStudents")}</Link>
          {canManageFinance && <Link className="button-link" to={`/finances/payments/new?student_id=${student.id}`}>{t("payments.newPayment")}</Link>}
        </div>
      </section>

      {error && <section className="panel"><p className="error-text">{error}</p></section>}

      <section className="student-profile-overview">
        <article className="panel student-identity-card">
          <div className="student-photo-frame">
            {photoUrl ? <img src={photoUrl} alt={`${student.first_name} ${student.last_name}`} /> : <span>{initials || "—"}</span>}
          </div>
          <label className="student-photo-action">
            <span>{uploading ? t("studentProfile.uploadingPhoto") : t("studentProfile.changePhoto")}</span>
            <input type="file" accept="image/jpeg,image/png,image/webp" onChange={changePhoto} disabled={uploading} />
          </label>
          <span className={`student-status status-${String(student.status || "inactive").toLowerCase()}`}>{student.status === "ACTIVE" ? t("statuses.active") : t("statuses.inactive")}</span>
        </article>

        <article className="panel student-information-card">
          <div className="panel-header"><div><p className="brand-kicker">{t("studentProfile.identity")}</p><h2>{t("studentProfile.schoolRecord")}</h2></div></div>
          <dl className="detail-list">
            <div><dt>{t("common.fullName")}</dt><dd>{student.first_name} {student.last_name}</dd></div>
            <div><dt>{t("students.birthDate")}</dt><dd>{formatDate(student.date_of_birth, language)}</dd></div>
            <div><dt>{t("students.gender")}</dt><dd>{["F", "FEMALE"].includes(student.gender) ? t("genders.female") : ["M", "MALE"].includes(student.gender) ? t("genders.male") : "-"}</dd></div>
            <div><dt>{t("common.phone")}</dt><dd>{student.phone || "-"}</dd></div>
            <div><dt>{t("common.address")}</dt><dd>{student.address || "-"}</dd></div>
            <div><dt>{t("common.schoolYear")}</dt><dd>{student.academic_year_name || student.school_year || "-"}</dd></div>
            <div><dt>{t("common.level")}</dt><dd>{student.class_level_name || "-"}</dd></div>
            <div><dt>{t("common.class")}</dt><dd>{student.class_group_name || "-"}</dd></div>
            <div><dt>{t("studentProfile.enrollmentDate")}</dt><dd>{formatDate(student.enrollment_date, language)}</dd></div>
          </dl>
        </article>
      </section>

      {canViewFinance && <section className="kpi-grid student-finance-kpis">
        <article className="panel kpi"><p className="kpi-label">Total dû</p><h2>{formatMoney(finance.total_due ?? finance.total_billed, language)}</h2><p className="muted">Initial {formatMoney(finance.total_initial, language)} · Remises {formatMoney(finance.total_discount, language)}</p></article>
        <article className="panel kpi"><p className="kpi-label">{t("studentProfile.totalPaid")}</p><h2>{formatMoney(finance.total_paid, language)}</h2></article>
        <article className="panel kpi"><p className="kpi-label">{t("studentProfile.totalRemaining")}</p><h2>{formatMoney(finance.total_remaining, language)}</h2><p className="muted">Trop-perçu {formatMoney(finance.total_overpaid, language)}</p></article>
      </section>}

      <section className="split-panel student-context-panels">
        <article className="panel">
          <p className="brand-kicker">{t("common.parent")}</p>
          <h3>{details.parent?.name || student.parent_name || "-"}</h3>
          <dl className="detail-list compact-detail-list">
            <div><dt>{t("common.phone")}</dt><dd>{details.parent?.phone || "-"}</dd></div>
            <div><dt>{t("common.email")}</dt><dd>{details.parent?.email || "-"}</dd></div>
          </dl>
        </article>
        <article className="panel">
          <p className="brand-kicker">{t("studentProfile.siblings")}</p>
          <div className="student-sibling-list">
            {(details.siblings || []).map((sibling) => (
              <Link key={sibling.id} to={`/students/${sibling.id}`}>
                <strong>{sibling.first_name} {sibling.last_name}</strong>
                <span>{sibling.class_level_name || "-"} · {sibling.class_group_name || "-"}</span>
              </Link>
            ))}
            {!(details.siblings || []).length && <p className="muted">{t("studentProfile.noSiblings")}</p>}
          </div>
        </article>
      </section>

      {canViewFinance && <section className="panel">
        <div className="panel-header"><div><h3>{t("studentProfile.monthlyFees")}</h3><p className="muted">{t("studentProfile.monthlyFeesHelp")}</p></div></div>
        <div className="table-wrap"><table><thead><tr><th>{t("payments.period")}</th><th>{t("studentProfile.billed")}</th><th>{t("studentProfile.paid")}</th><th>{t("payments.remaining")}</th><th>{t("common.status")}</th></tr></thead><tbody>
          {monthlyItems.map((fee) => <tr key={fee.source_id}><td>{fee.period || "-"}</td><td>{formatMoney(fee.final_amount, language)}</td><td>{formatMoney(fee.paid_amount, language)}</td><td>{formatMoney(fee.remaining_amount, language)}</td><td>{t(`statuses.${String(fee.status || "unpaid").toLowerCase()}`)}</td></tr>)}
          {!monthlyItems.length && <tr><td colSpan="5" className="table-empty">{t("studentProfile.noFees")}</td></tr>}
        </tbody></table></div>
      </section>}

      {canViewFinance && <section className="panel">
        <div className="panel-header"><div><h3>Frais flexibles</h3><p className="muted">Les échéances absentes ne sont jamais présentées comme des retards.</p></div></div>
        <div className="table-wrap"><table><thead><tr><th>Libellé</th><th>Initial</th><th>Remise</th><th>Dû</th><th>Payé</th><th>Reste</th><th>Échéance</th><th>Statut</th></tr></thead><tbody>
          {flexibleItems.map((item) => <tr key={item.source_id}><td>{item.label}</td><td>{formatMoney(item.initial_amount, language)}</td><td>{formatMoney(item.discount_amount, language)}</td><td>{formatMoney(item.final_amount, language)}</td><td>{formatMoney(item.paid_amount, language)}</td><td>{formatMoney(item.remaining_amount, language)}</td><td>{formatDate(item.due_date, language)}</td><td>{t(`statuses.${String(item.status || "unpaid").toLowerCase()}`)}</td></tr>)}
          {!flexibleItems.length && <tr><td colSpan="8" className="table-empty">Aucun frais flexible.</td></tr>}
        </tbody></table></div>
      </section>}

      {canViewFinance && <section className="panel">
        <div className="panel-header"><div><h3>{t("studentProfile.paymentHistory")}</h3><p className="muted">{t("studentProfile.paymentHistoryHelp")}</p></div><button type="button" onClick={exportPaymentHistory} disabled={exportingPayments}>{exportingPayments ? "Export en cours…" : "Exporter l’historique PDF"}</button></div>
        <div className="table-wrap"><table><thead><tr><th>{t("payments.receiptNumber")}</th><th>{t("common.date")}</th><th>{t("payments.period")}</th><th>{t("common.amount")}</th><th>{t("payments.method")}</th><th>{t("common.actions")}</th></tr></thead><tbody>
          {financialPayments.map((payment) => { const paymentId = payment.payment_id || payment.id; return <tr key={paymentId}><td>{payment.receipt_number || `REC-${String(paymentId).padStart(6, "0")}`}</td><td>{formatDate(payment.date || payment.payment_date, language)}</td><td>{payment.source === "FLEXIBLE" ? "Frais flexibles" : (payment.period || "-")}{payment.status === "CANCELLED" ? " · Annulé" : ""}</td><td>{formatMoney(payment.amount || payment.amount_paid, language)}</td><td>{paymentMethodLabel(payment.method || payment.payment_method_label || payment.payment_method, t)}</td><td><div className="table-actions"><Link className="secondary-btn button-link" to={`/finances/payments/${paymentId}`}>{t("common.details")}</Link><button type="button" onClick={() => downloadReceipt(paymentId)} disabled={downloadingId === paymentId}>{t("payments.downloadReceipt")}</button></div></td></tr>; })}
          {!financialPayments.length && <tr><td colSpan="6" className="table-empty">{t("studentProfile.noPayments")}</td></tr>}
        </tbody></table></div>
      </section>}
    </div>
  );
}
