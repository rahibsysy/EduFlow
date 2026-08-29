import api from "./api";

export const getDashboardAlerts = async () => {
  const response = await api.get("/api/dashboard/alerts");
  return response.data;
};
