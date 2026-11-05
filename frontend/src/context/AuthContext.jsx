import { createContext, useMemo, useState } from "react";

import { useEffect } from "react";
import { getMyPermissions } from "../services/permissionService";

export const AuthContext = createContext(null);

const readStoredUser = () => {
  const stored = localStorage.getItem("user");

  if (!stored || stored === "undefined" || stored === "null") {
    localStorage.removeItem("user");
    return null;
  }

  try {
    return JSON.parse(stored);
  } catch {
    localStorage.removeItem("user");
    return null;
  }
};

export function AuthProvider({ children }) {
  const [user, setUser] = useState(readStoredUser);
  const [permissions, setPermissions] = useState([]);
  const [permissionsLoading, setPermissionsLoading] = useState(Boolean(user));

  useEffect(() => {
    if (!user) {
      setPermissions([]);
      setPermissionsLoading(false);
      return;
    }

    setPermissionsLoading(true);
    getMyPermissions()
      .then((data) => setPermissions(Array.isArray(data?.permissions) ? data.permissions : []))
      .catch(() => setPermissions([]))
      .finally(() => setPermissionsLoading(false));
  }, [user?.id, user?.role]);

  const login = (data) => {
    if (!data?.token || !data?.user) {
      throw new Error("Invalid login response");
    }

    localStorage.setItem("token", data.token);
    localStorage.setItem("user", JSON.stringify(data.user));
    setUser(data.user);
  };

  const logout = () => {
    localStorage.removeItem("token");
    localStorage.removeItem("user");
    setUser(null);
    setPermissions([]);
  };

  const updateSession = (data) => {
    if (data?.token) localStorage.setItem("token", data.token);
    if (data?.user) {
      localStorage.setItem("user", JSON.stringify(data.user));
      setUser(data.user);
    }
  };

  const can = (permission) => user?.role === "super_admin" || permissions.includes(permission);
  const value = useMemo(
    () => ({ user, login, logout, updateSession, permissions, permissionsLoading, can }),
    [user, permissions, permissionsLoading]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
