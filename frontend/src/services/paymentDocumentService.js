import api from "./api";

const filenameFrom = (header, fallback) => header?.match(/filename="?([^";]+)"?/i)?.[1] || fallback;

const download = async (url, params, fallback) => {
  const response = await api.get(url, { params, responseType: "blob" });
  if ((response.headers["content-type"] || "").includes("application/json")) {
    const payload = JSON.parse(await response.data.text());
    throw new Error(payload.message || "La génération du PDF a échoué.");
  }
  const blobUrl = URL.createObjectURL(new Blob([response.data], { type: "application/pdf" }));
  const link = document.createElement("a");
  link.href = blobUrl;
  link.download = filenameFrom(response.headers["content-disposition"], fallback);
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(blobUrl);
};

export const downloadMonthlyPaymentsPdf = (params) => download("/api/payment-documents/monthly", params, `paiements-${params.month_label}-${params.year_value}.pdf`);
export const downloadFinancialStatementPdf = (studentId, params = {}) => download(`/api/student-documents/${studentId}/financial-statement`, params, "situation-financiere.pdf");
export const downloadStudentPaymentsPdf = (studentId, params) => download(`/api/payment-documents/students/${studentId}`, params, "paiements-eleve.pdf");
export const downloadFamilyPaymentsPdf = (familyId, params) => download(`/api/payment-documents/families/${familyId}`, params, "recapitulatif-famille.pdf");
export const downloadUnpaidPdf = (params = {}) => download("/api/payment-documents/unpaid", params, "impayes-consolides.pdf");
