import api from "./api";

export const getTeachers = async (params) => {
  const response = await api.get("/api/teachers", { params });
  return response.data;
};
