import api from "./api";

export const getConsolidatedUnpaidItems = async (params = {}) => {
  const response = await api.get("/api/financial/unpaid", { params });
  return response.data;
};
