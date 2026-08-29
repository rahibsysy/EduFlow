import api from "./api";

export const getStudents = async (params) => {
  const response = await api.get("/api/students", { params });
  return response.data;
};

export const createStudent = async (payload) => {
  const response = await api.post("/api/students", payload);
  return response.data;
};

export const updateStudent = async (id, payload) => {
  const response = await api.put(`/api/students/${id}`, payload);
  return response.data;
};

export const deleteStudent = async (id) => {
  const response = await api.delete(`/api/students/${id}`);
  return response.data;
};

export const importStudents = async (payload, onProgress) => {
  const formData = new FormData();
  formData.append("students_file", payload.file);

  if (payload.class_level_id) {
    formData.append("class_level_id", payload.class_level_id);
  }

  if (payload.class_name) {
    formData.append("class_name", payload.class_name);
  }

  if (payload.school_year) {
    formData.append("school_year", payload.school_year);
  }

  if (payload.monthly_amount !== undefined && payload.monthly_amount !== null) {
    formData.append("monthly_amount", payload.monthly_amount);
  }

  if (payload.discount_percent !== undefined && payload.discount_percent !== null) {
    formData.append("discount_percent", payload.discount_percent);
  }

  if (payload.school_id) {
    formData.append("school_id", payload.school_id);
  }

  const response = await api.post("/api/students/import", formData, {
    headers: { "Content-Type": "multipart/form-data" },
    onUploadProgress: (event) => {
      if (!onProgress || !event.total) {
        return;
      }

      onProgress(Math.round((event.loaded * 100) / event.total));
    },
  });

  return response.data;
};
