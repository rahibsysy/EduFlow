import { useEffect, useMemo, useState } from "react";
import useAuth from "../hooks/useAuth";
import { createUser, deleteUser, getUsers, resetUserPassword, updateUser } from "../services/userService";
import { getCurrentSchool, getSchools } from "../services/schoolService";

const EMPTY_FORM = {
  first_name: "",
  last_name: "",
  email_local_part: "",
  password: "",
  role: "user",
  school_id: "",
  status: "ACTIVE",
};

const roleLabel = {
  user: "Utilisateur",
  admin: "Admin",
  super_admin: "Super admin",
};

export default function AdminPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const isAdmin = user?.role === "admin";

  const [users, setUsers] = useState([]);
  const [schools, setSchools] = useState([]);
  const [currentSchool, setCurrentSchool] = useState(null);
  const [form, setForm] = useState(EMPTY_FORM);
  const [editingId, setEditingId] = useState(null);
  const [message, setMessage] = useState("");
  const [resetMessage, setResetMessage] = useState("");
  const [error, setError] = useState("");

  const isEditing = editingId !== null;

  const selectedSchool = useMemo(
    () => schools.find((item) => String(item.id) === String(form.school_id)) || null,
    [schools, form.school_id]
  );

  const emailDomain = useMemo(() => {
    if (isSuperAdmin) {
      return selectedSchool?.email_domain || "school-domain.com";
    }
    return currentSchool?.email_domain || "school-domain.com";
  }, [currentSchool?.email_domain, isSuperAdmin, selectedSchool?.email_domain]);

  const load = async () => {
    const [usersData, currentData] = await Promise.all([
      getUsers(),
      isAdmin ? getCurrentSchool() : Promise.resolve(null),
    ]);

    setUsers(Array.isArray(usersData) ? usersData : []);
    setCurrentSchool(currentData);

    if (isSuperAdmin) {
      const schoolsData = await getSchools();
      const safeSchools = Array.isArray(schoolsData) ? schoolsData : [];
      setSchools(safeSchools);
      if (safeSchools.length && !form.school_id) {
        setForm((prev) => ({ ...prev, school_id: String(safeSchools[0].id) }));
      }
    }
  };

  useEffect(() => {
    load().catch(() => setError("Impossible de charger les donnees d'administration."));
  }, [isAdmin, isSuperAdmin]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setMessage("");

    try {
      const payload = {
        first_name: form.first_name,
        last_name: form.last_name,
        status: form.status,
      };

      if (!isEditing) {
        payload.email_local_part = form.email_local_part;
        payload.password = form.password;
      } else if (form.password) {
        payload.password = form.password;
      }

      if (isSuperAdmin) {
        payload.role = form.role;
        if (form.role !== "super_admin") {
          payload.school_id = Number(form.school_id);
        }
      } else {
        payload.role = form.role;
      }

      if (isEditing) {
        await updateUser(editingId, payload);
        setMessage("Utilisateur modifie avec succes.");
      } else {
        const created = await createUser(payload);
        setMessage(`Utilisateur cree: ${created.email}`);
      }

      setEditingId(null);
      setForm((prev) => ({ ...EMPTY_FORM, school_id: prev.school_id || form.school_id }));
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || "Echec de sauvegarde utilisateur.");
    }
  };

  const handleEdit = (targetUser) => {
    setEditingId(targetUser.id);
    setForm({
      first_name: targetUser.first_name || "",
      last_name: targetUser.last_name || "",
      email_local_part: "",
      password: "",
      role: targetUser.role || "user",
      school_id: targetUser.school_id ? String(targetUser.school_id) : "",
      status: targetUser.status || "ACTIVE",
    });
    setMessage("");
    setResetMessage("");
    setError("");
  };

  const cancelEdit = () => {
    setEditingId(null);
    setForm((prev) => ({ ...EMPTY_FORM, school_id: prev.school_id || form.school_id }));
  };

  const handleDelete = async (targetUser) => {
    if (!window.confirm(`Supprimer le compte ${targetUser.email} ?`)) {
      return;
    }

    setError("");
    setMessage("");
    setResetMessage("");

    try {
      await deleteUser(targetUser.id);
      setMessage("Utilisateur supprime avec succes.");
      if (editingId === targetUser.id) {
        cancelEdit();
      }
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || "Echec de suppression utilisateur.");
    }
  };

  const handleResetPassword = async (targetUser) => {
    setError("");
    setMessage("");
    setResetMessage("");

    try {
      const result = await resetUserPassword(targetUser.id);
      setResetMessage(`Mot de passe reinitialise pour ${result.email}: ${result.default_password}`);
    } catch (err) {
      setError(err?.response?.data?.message || "Echec de reinitialisation du mot de passe.");
    }
  };

  if (!isSuperAdmin && !isAdmin) {
    return (
      <section className="panel">
        <h2>Administration</h2>
        <p className="muted">Acces reserve aux administrateurs autorises.</p>
      </section>
    );
  }

  return (
    <div className="admin-grid">
      <section className="panel hero-panel">
        <p className="brand-kicker">Administration</p>
        <h2>{isSuperAdmin ? "Annuaire global des utilisateurs" : "Annuaire de votre ecole"}</h2>
        <p className="muted">
          {isSuperAdmin
            ? "Creation et gestion des comptes multi-ecoles avec controle des roles."
            : "Gestion des comptes utilisateurs de votre ecole uniquement."}
        </p>
      </section>

      <section className="panel">
        <h3>{isEditing ? "Modifier un utilisateur" : "Creer un utilisateur"}</h3>
        <form className="form-grid" onSubmit={handleSubmit}>
          <input placeholder="Prenom" value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
          <input placeholder="Nom" value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required />

          {isSuperAdmin && (
            <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
              <option value="user">Utilisateur</option>
              <option value="admin">Admin</option>
              <option value="super_admin">Super admin</option>
            </select>
          )}

          {!isSuperAdmin && (
            <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
              <option value="user">Utilisateur</option>
            </select>
          )}

          {isSuperAdmin && form.role !== "super_admin" && (
            <select value={form.school_id} onChange={(e) => setForm({ ...form, school_id: e.target.value })} required>
              {schools.map((school) => (
                <option key={school.id} value={school.id}>
                  {school.name} ({school.email_domain})
                </option>
              ))}
            </select>
          )}

          {!isEditing && (
            <input
              placeholder="Prefixe email (ex: salma.alaoui)"
              value={form.email_local_part}
              onChange={(e) => setForm({ ...form, email_local_part: e.target.value })}
            />
          )}
          {!isEditing && form.role !== "super_admin" && (
            <p className="muted">Apercu email: {(form.email_local_part || "user") + "@" + emailDomain}</p>
          )}

          <input
            type="password"
            placeholder={isEditing ? "Nouveau mot de passe (optionnel)" : "Mot de passe"}
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            required={!isEditing}
          />
          <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
            <option value="ACTIVE">Actif</option>
            <option value="INACTIVE">Inactif</option>
          </select>
          <div className="form-actions">
            <button type="submit">{isEditing ? "Enregistrer" : "Creer l'utilisateur"}</button>
            {isEditing && (
              <button type="button" className="secondary-btn" onClick={cancelEdit}>
                Annuler
              </button>
            )}
          </div>
        </form>
        {message && <p className="muted">{message}</p>}
        {resetMessage && <p className="muted">{resetMessage}</p>}
        {error && <p className="error-text">{error}</p>}
      </section>

      <section className="panel">
        <h3>Utilisateurs ({users.length})</h3>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Nom complet</th>
                <th>Email</th>
                <th>Role</th>
                <th>Statut</th>
                <th>Ecole</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {users.map((item) => {
                const canEdit = isSuperAdmin || (isAdmin && item.role === "user");
                const canDelete = isSuperAdmin && item.id !== user?.id;

                return (
                  <tr key={item.id}>
                    <td>{item.id}</td>
                    <td>{item.first_name} {item.last_name}</td>
                    <td>{item.email}</td>
                    <td>{roleLabel[item.role] || item.role}</td>
                    <td>{item.status === "ACTIVE" ? "Actif" : "Inactif"}</td>
                    <td>{item.school_name || "-"}</td>
                    <td>
                      <div className="table-actions">
                        <button
                          type="button"
                          className="secondary-btn"
                          onClick={() => handleEdit(item)}
                          disabled={!canEdit}
                        >
                          Modifier
                        </button>
                        {isSuperAdmin && (
                          <button
                            type="button"
                            onClick={() => handleResetPassword(item)}
                            disabled={item.role === "super_admin"}
                          >
                            Reset MDP
                          </button>
                        )}
                        {isSuperAdmin && (
                          <button
                            type="button"
                            className="danger-btn"
                            onClick={() => handleDelete(item)}
                            disabled={!canDelete}
                          >
                            Supprimer
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
              {!users.length && (
                <tr>
                  <td colSpan="7" className="table-empty">Aucun utilisateur trouve.</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
