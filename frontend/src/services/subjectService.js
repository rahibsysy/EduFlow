import api from "./api";

export const getSubjects = async (params) => {
  const response = await api.get("/api/subjects", { params });
  return response.data;
};
