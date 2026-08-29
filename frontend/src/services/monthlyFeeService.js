import api from "./api";

export const getMonthlyFees = async (params) => {
  const response = await api.get("/api/monthly-fees", { params });
  return response.data;
};

export const getMonthlyFeeById = async (id) => {
  const response = await api.get(`/api/monthly-fees/${id}`);
  return response.data;
};

export const getUnpaidMonthlyFees = async (params) => {
  const response = await api.get("/api/monthly-fees/unpaid", { params });
  return response.data;
};

export const createMonthlyFee = async (payload) => {
  const response = await api.post("/api/monthly-fees", payload);
  return response.data;
};

export const updateMonthlyFee = async (id, payload) => {
  const response = await api.put(`/api/monthly-fees/${id}`, payload);
  return response.data;
};

export const deleteMonthlyFee = async (id) => {
  const response = await api.delete(`/api/monthly-fees/${id}`);
  return response.data;
};
