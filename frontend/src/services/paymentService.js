import api from "./api";

export const getPayments = async () => {
  const response = await api.get("/api/payments");
  return response.data;
};

export const getPaymentById = async (id) => {
  const response = await api.get(`/api/payments/${id}`);
  return response.data;
};

export const createPayment = async (payload) => {
  const response = await api.post("/api/payments", payload);
  return response.data;
};

export const updatePayment = async (id, payload) => {
  const response = await api.put(`/api/payments/${id}`, payload);
  return response.data;
};

export const deletePayment = async (id) => {
  const response = await api.delete(`/api/payments/${id}`);
  return response.data;
};
