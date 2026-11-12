import axios from "axios";

const apiBaseUrl = import.meta.env.VITE_API_URL || "http://127.0.0.1:8080";
let redirectingToAccount = false;

const api = axios.create({
  baseURL: apiBaseUrl,
  headers: { "Content-Type": "application/json" },
});

api.interceptors.request.use((config) => {
  if (typeof FormData !== "undefined" && config.data instanceof FormData) {
    // Le navigateur doit générer lui-même la frontière multipart pour alimenter $_FILES.
    delete config.headers["Content-Type"];
  }
  const token = localStorage.getItem("token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status;
    const requestUrl = String(error?.config?.url || "");
    // The application login endpoint is /api/login.  Do not treat a failed
    // credential submission as an expired authenticated session: LoginPage
    // must be allowed to display its own error message.
    const isLoginRequest = requestUrl.includes("/api/login");

    if (status === 401 && !isLoginRequest && !redirectingToAccount) {
      redirectingToAccount = true;
      localStorage.removeItem("token");
      localStorage.removeItem("user");
      window.location.replace("/");
    }

    if (status === 428 && !redirectingToAccount && window.location.pathname !== "/account/activate") {
      redirectingToAccount = true;
      try {
        const storedUser = JSON.parse(localStorage.getItem("user") || "null");
        if (storedUser) {
          localStorage.setItem("user", JSON.stringify({ ...storedUser, must_change_password: true }));
        }
      } catch {
        localStorage.removeItem("user");
      }
      window.location.replace("/account/activate");
    }

    return Promise.reject(error);
  }
);

export default api;
