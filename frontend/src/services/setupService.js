import api from "./api";

const setupHeaders = (secret) => ({ headers: { "X-Setup-Secret": secret } });

export const getInitialSetupStatus = async (secret) => (await api.get("/api/setup/status", setupHeaders(secret))).data;
export const completeInitialSetup = async (payload, secret) => (await api.post("/api/setup", payload, setupHeaders(secret))).data;
