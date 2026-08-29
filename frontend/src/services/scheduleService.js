import api from "./api";

export const getSchedules = async (params) => {
  const response = await api.get("/api/schedules", { params });
  return response.data;
};

export const getScheduleById = async (id) => {
  const response = await api.get(`/api/schedules/${id}`);
  return response.data;
};

export const createSchedule = async (payload) => {
  const response = await api.post("/api/schedules", payload);
  return response.data;
};

export const updateSchedule = async (id, payload) => {
  const response = await api.put(`/api/schedules/${id}`, payload);
  return response.data;
};

export const deleteSchedule = async (id) => {
  const response = await api.delete(`/api/schedules/${id}`);
  return response.data;
};
