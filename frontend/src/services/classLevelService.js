import api from "./api";

export const getClassLevels = async (schoolId) => {
  const params = schoolId ? { school_id: schoolId } : undefined;
  const response = await api.get("/api/class-levels", { params });
  return response.data;
};

export const getClassLevelById = async (id) => {
  const response = await api.get(`/api/class-levels/${id}`);
  return response.data;
};

export const createClassLevel = async (payload) => {
  const response = await api.post("/api/class-levels", payload);
  return response.data;
};

export const updateClassLevel = async (id, payload) => {
  const response = await api.put(`/api/class-levels/${id}`, payload);
  return response.data;
};

export const deleteClassLevel = async (id) => {
  const response = await api.delete(`/api/class-levels/${id}`);
  return response.data;
};
