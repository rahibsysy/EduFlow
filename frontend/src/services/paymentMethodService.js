import api from "./api";

export const getPaymentMethods = async () => {
  const response = await api.get("/api/payment-methods");
  return response.data;
};

export const getPaymentMethodById = async (id) => {
  const response = await api.get(`/api/payment-methods/${id}`);
  return response.data;
};

export const createPaymentMethod = async (payload) => {
  const response = await api.post("/api/payment-methods", payload);
  return response.data;
};

export const updatePaymentMethod = async (id, payload) => {
  const response = await api.put(`/api/payment-methods/${id}`, payload);
  return response.data;
};

export const deletePaymentMethod = async (id) => {
  const response = await api.delete(`/api/payment-methods/${id}`);
  return response.data;
};
