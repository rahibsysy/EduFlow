import api from "./api";

export const getFamilyDiscounts = (params) => api.get("/api/family-discounts", { params }).then((r) => r.data);
export const previewFamilyDiscount = (payload) => api.post("/api/family-discounts/preview", payload).then((r) => r.data);
export const createFamilyDiscount = (payload) => api.post("/api/family-discounts", payload).then((r) => r.data);
export const updateFamilyDiscount = (id, payload) => api.put(`/api/family-discounts/${id}`, payload).then((r) => r.data);
export const cancelFamilyDiscount = (id, payload) => api.patch(`/api/family-discounts/${id}/cancel`, payload).then((r) => r.data);
export const getFamilyDiscountHistory = (id) => api.get(`/api/family-discounts/${id}/history`).then((r) => r.data);
