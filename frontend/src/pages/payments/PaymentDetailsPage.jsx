import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import useI18n from "../../hooks/useI18n";
import { getPaymentById } from "../../services/paymentService";
import { downloadReceiptPdf, getReceiptData, getReceiptPdfBlob } from "../../services/receiptService";

const statusLabel = (value, t) => {
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

export default function PaymentDetailsPage() {
  const { id } = useParams();
  const { language, t } = useI18n();
  const [details, setDetails] = useState(null);
  const [previewUrl, setPreviewUrl] = useState("");
  const [loading, setLoading] = useState(true);
  const [actionLoading, setActionLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    setLoading(true);
    getPaymentById(id)
      .then((payment) => {
        setDetails(payment);
        return getReceiptData(id, language)
          .then((receipt) => setDetails((current) => ({ ...current, ...receipt })))
          .catch(() => setError(t("payments.previewError")));
      })
      .catch(() => setError(t("payments.detailLoadError")))
      .finally(() => setLoading(false));
  }, [id, language, t]);

  useEffect(() => () => {
    if (previewUrl) window.URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  const previewReceipt = async () => {
    setActionLoading(true);
    setError("");
    try {
      const { blob } = await getReceiptPdfBlob(id, language);
      if (previewUrl) window.URL.revokeObjectURL(previewUrl);
      setPreviewUrl(window.URL.createObjectURL(blob));
    } catch {
      setError(t("payments.previewError"));
    } finally {
      setActionLoading(false);
    }
  };

  const downloadReceipt = async () => {
    setActionLoading(true);
    setError("");
    try {
      await downloadReceiptPdf(id, language);
    } catch {
      setError(t("payments.downloadError"));
    } finally {
      setActionLoading(false);
    }
  };

  if (loading) return <section className="panel"><p>{t("common.loading")}</p></section>;

  return (
    <div className="admin-grid">
      <section className="panel hero-modern panel-header">
        <div>
          <p className="brand-kicker">{t("navigation.finance")}</p>
          <h2>{t("payments.details")}</h2>
          <p className="muted">{t("payments.detailsDescription")}</p>
        </div>
        <Link className="secondary-btn button-link" to="/finances/payments/history">{t("payments.backToHistory")}</Link>
      </section>

      {error && <section className="panel"><p className="error-text">{error}</p></section>}
      {details && (
        <>
          <section className="panel payment-detail-panel">
            <div className="payment-detail-grid">
              <div><span>{t("payments.receiptNumber")}</span><strong>{details.receipt_number}</strong></div>
              <div><span>{t("common.student")}</span><strong>{details.student || `${details.first_name || ""} ${details.last_name || ""}`.trim() || "-"}</strong></div>
              <div><span>{t("payments.parentName")}</span><strong>{details.parent_name || "-"}</strong></div>
              <div><span>{t("common.level")}</span><strong>{details.class_level || "-"}</strong></div>
              <div><span>{t("common.class")}</span><strong>{details.class_name || "-"}</strong></div>
              <div><span>{t("payments.period")}</span><strong>{details.period_label || (details.month_label ? `${details.month_label}/${details.year_value}` : "Frais flexibles")}</strong></div>
              <div><span>{t("common.amount")}</span><strong>{details.amount_paid_label || Number(details.amount_paid || 0).toLocaleString(language === "ar" ? "ar-MA" : "fr-MA", { style: "currency", currency: "MAD" })}</strong></div>
              {details.monthly_fee_id && <><div><span>{t("payments.feeTotal")}</span><strong>{details.fee_total_label || "-"}</strong></div><div><span>{t("payments.alreadyPaid")}</span><strong>{details.fee_amount_paid_label || "-"}</strong></div><div><span>{t("payments.remaining")}</span><strong>{details.fee_remaining_label || "-"}</strong></div></>}
              <div><span>{t("payments.method")}</span><strong>{paymentMethodLabel(details.payment_method, t)}</strong></div>
              <div><span>{t("common.date")}</span><strong>{details.payment_date ? new Intl.DateTimeFormat(language === "ar" ? "ar-MA" : "fr-MA").format(new Date(details.payment_date)) : "-"}</strong></div>
              <div><span>{t("common.status")}</span><strong>{statusLabel(details.fee_status, t)}</strong></div>
              {(details.payment_status || details.status) === "CANCELLED" && <div><span>Statut du paiement</span><strong>Annulé{details.cancellation_reason ? ` — ${details.cancellation_reason}` : ""}</strong></div>}
              <div><span>{t("payments.school")}</span><strong>{details.school_name || "-"}</strong></div>
            </div>
            {Array.isArray(details.allocations) && details.allocations.length > 0 && <section className="panel"><h3>Frais réglés</h3><ul>{details.allocations.map((allocation) => <li key={allocation.student_financial_item_id}>{allocation.label} : {Number(allocation.amount || 0).toLocaleString(language === "ar" ? "ar-MA" : "fr-MA", { style: "currency", currency: "MAD" })}</li>)}</ul></section>}
            <div className="form-actions payment-detail-actions">
              <button type="button" className="secondary-btn" onClick={previewReceipt} disabled={actionLoading}>{t("payments.viewReceipt")}</button>
              <button type="button" onClick={downloadReceipt} disabled={actionLoading}>{t("payments.downloadReceipt")}</button>
            </div>
          </section>

          {previewUrl && (
            <section className="panel receipt-preview-panel">
              <h3>{t("payments.receiptPreview")}</h3>
              <iframe title={t("payments.receiptPreview")} src={previewUrl} />
            </section>
          )}
        </>
      )}
    </div>
  );
}
