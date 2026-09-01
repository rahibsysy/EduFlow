import api from "./api";

export const getTeachers = async (params) => {
  const response = await api.get("/api/teachers", { params });
  return response.data;
};

export const createTeacher = async (payload) => {
  const response = await api.post("/api/teachers", payload);
  return response.data;
};

export const updateTeacher = async (id, payload) => {
  const response = await api.put(`/api/teachers/${id}`, payload);
  return response.data;
};

export const deleteTeacher = async (id) => {
  const response = await api.delete(`/api/teachers/${id}`);
  return response.data;
};
