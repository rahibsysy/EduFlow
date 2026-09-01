import api from "./api";

const triggerBlobDownload = (blob, filename) => {
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
};

export const downloadReceiptPdf = async (paymentId) => {
  const response = await api.get(`/api/receipts/${paymentId}/pdf`, {
    responseType: "blob",
  });

  const contentType = response.headers["content-type"] || "";
  if (contentType.includes("application/json")) {
    const text = await response.data.text();
    const payload = JSON.parse(text);
    throw new Error(payload.message || "Impossible de generer le recu PDF.");
  }

  const filename = `recu-paiement-${paymentId}.pdf`;
  const blob = new Blob([response.data], { type: "application/pdf" });
  triggerBlobDownload(blob, filename);
  return filename;
};

export const getReceiptData = async (paymentId) => {
  const response = await api.get(`/api/receipts/${paymentId}`);
  return response.data;
};
