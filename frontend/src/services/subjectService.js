import api from "./api";

export const getSubjects = async (params) => {
  const response = await api.get("/api/subjects", { params });
  return response.data;
};

export const updateSubjectWeeklyHours = async (subjectId, classLevelId, weeklyHours) => {
  const response = await api.put(`/api/subjects/${subjectId}/class-levels/${classLevelId}`, {
    weekly_hours: weeklyHours,
  });
  return response.data;
};
