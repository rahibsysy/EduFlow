import { useEffect, useMemo, useState } from "react";
import { DEFAULT_LEVEL_OPTIONS } from "../config/schoolOptions";
import { getClassLevels } from "../services/classLevelService";
import { createStudent, deleteStudent, getStudents, updateStudent } from "../services/studentService";

const formatMoney = (value) =>
  new Intl.NumberFormat("fr-MA", { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

const emptyForm = {
  first_name: "",
  last_name: "",
  date_of_birth: "",
  gender: "",
  class_name: "",
  parent_name: "",
  parent_phone: "",
  address: "",
  monthly_amount: "",
  discount_percent: "0",
  school_year: "",
  class_level_id: "",
  status: "ACTIVE",
};

export default function StudentsPage() {
  const [students, setStudents] = useState([]);
  const [classLevels, setClassLevels] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [filters, setFilters] = useState({
    last_name: "",
    first_name: "",
    class_level: "",
    class_name: "",
  });
  const [editingId, setEditingId] = useState(null);
  const [loading, setLoading] = useState(false);
  const [searchApplied, setSearchApplied] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const loadClassLevels = async () => {
    const levelsData = await getClassLevels();
    setClassLevels(Array.isArray(levelsData) ? levelsData : []);
  };

  useEffect(() => {
    loadClassLevels().catch(() => setError("Impossible de charger les niveaux."));
  }, []);

  const averageMonthlyFee = useMemo(() => {
    if (!students.length) {
      return 0;
    }
    const total = students.reduce((sum, item) => sum + Number(item.monthly_amount || 0), 0);
    return total / students.length;
  }, [students]);

  const levelOptions = useMemo(() => {
    if (classLevels.length) {
      return classLevels.map((item) => ({ id: item.id, name: item.name, fromDatabase: true }));
    }

    return DEFAULT_LEVEL_OPTIONS.map((name) => ({ id: name, name, fromDatabase: false }));
  }, [classLevels]);

  const hasActiveFilters = useMemo(
    () => Object.values(filters).some((value) => String(value || "").trim() !== ""),
    [filters]
  );

  const filteredStudents = students;

  const effectiveAmountPreview = useMemo(() => {
    const amount = Number(form.monthly_amount || 0);
    const discount = Number(form.discount_percent || 0);
    const net = amount * ((100 - discount) / 100);
    return net > 0 ? net : 0;
  }, [form.discount_percent, form.monthly_amount]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setMessage("");
    setLoading(true);

    try {
      const classLevelId = form.class_level_id ? Number(form.class_level_id) : null;
      const selectedFallbackLevel = !classLevels.length ? form.class_level_id : "";
      if (!classLevelId && !selectedFallbackLevel) {
        throw new Error("Veuillez selectionner un niveau scolaire.");
      }

      const payload = {
        first_name: form.first_name.trim(),
        last_name: form.last_name.trim(),
        date_of_birth: form.date_of_birth || null,
        gender: form.gender.trim() || null,
        class_name: form.class_name.trim() || null,
        parent_name: form.parent_name.trim(),
        parent_phone: form.parent_phone.trim(),
        address: form.address.trim() || null,
        monthly_amount: Number(form.monthly_amount || 0),
        discount_percent: Number(form.discount_percent || 0),
        school_year: form.school_year.trim() || null,
        class_level_id: classLevelId || undefined,
        class_level: selectedFallbackLevel || undefined,
        status: form.status,
      };

      if (editingId) {
        await updateStudent(editingId, payload);
        setMessage("Eleve modifie avec succes.");
      } else {
        await createStudent(payload);
        setMessage("Eleve cree avec succes.");
      }
      setForm(emptyForm);
      setEditingId(null);
      if (searchApplied) {
        await applyStudentFilters();
      }
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || "Echec de creation eleve.");
    } finally {
      setLoading(false);
    }
  };

  const handleEdit = (student) => {
    setError("");
    setMessage("");

    const matchedLevel =
      classLevels.find((item) => Number(item.id) === Number(student.class_level_id)) ||
      classLevels.find((item) => item.name === (student.class_level_name || student.class_level)) ||
      null;

    setEditingId(student.id);
    setForm({
      first_name: student.first_name || "",
      last_name: student.last_name || "",
      date_of_birth: student.date_of_birth || "",
      gender: student.gender || "",
      class_name: student.class_name || "",
      parent_name: student.parent_name || "",
      parent_phone: student.parent_phone || student.phone || "",
      address: student.address || "",
      monthly_amount: student.monthly_amount != null ? String(student.monthly_amount) : "",
      discount_percent: student.discount_percent != null ? String(student.discount_percent) : "0",
      school_year: student.school_year || "",
      class_level_id: matchedLevel ? String(matchedLevel.id) : "",
      status: student.status || "ACTIVE",
    });
  };

  const handleDelete = async (student) => {
    const confirmed = window.confirm(`Supprimer l'eleve ${student.first_name} ${student.last_name} ?`);
    if (!confirmed) {
      return;
    }

    setError("");
    setMessage("");
    try {
      await deleteStudent(student.id);
      setMessage("Eleve supprime avec succes.");
      if (editingId === student.id) {
        setEditingId(null);
        setForm(emptyForm);
      }
      if (searchApplied) {
        await applyStudentFilters();
      }
    } catch (err) {
      setError(err?.response?.data?.message || "Echec de suppression eleve.");
    }
  };

  const applyStudentFilters = async (e) => {
    if (e) {
      e.preventDefault();
    }

    setError("");
    setMessage("");

    if (!hasActiveFilters) {
      setStudents([]);
      setSearchApplied(false);
      setError("Veuillez saisir une recherche ou choisir un filtre.");
      return;
    }

    setLoading(true);
    try {
      const data = await getStudents({
        last_name: filters.last_name || undefined,
        first_name: filters.first_name || undefined,
        class_level: filters.class_level || undefined,
        class_name: filters.class_name || undefined,
      });
      setStudents(Array.isArray(data) ? data : []);
      setSearchApplied(true);
    } catch (err) {
      setStudents([]);
      setSearchApplied(false);
      setError(err?.response?.data?.message || "Impossible de charger les eleves.");
    } finally {
      setLoading(false);
    }
  };

  const resetFilters = () => {
    setFilters({ last_name: "", first_name: "", class_level: "", class_name: "" });
    setStudents([]);
    setSearchApplied(false);
    setError("");
    setMessage("");
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>Eleves</h2>
        <p className="muted">
          Creation et suivi des eleves avec mensualite, reduction et classe.
        </p>
      </section>

      <section className="kpi-grid two-col">
        <article className="panel kpi">
          <p className="kpi-label">Eleves inscrits</p>
          <h2>{students.length}</h2>
          <p className="muted">Dossiers actifs dans la plateforme</p>
        </article>
        <article className="panel kpi">
          <p className="kpi-label">Mensualite moyenne</p>
          <h2>{formatMoney(averageMonthlyFee)}</h2>
          <p className="muted">Moyenne des mensualites configurees</p>
        </article>
      </section>

      <section className="panel">
        <h3>{editingId ? "Modifier un eleve" : "Creer un eleve"}</h3>
        <form className="form-grid" onSubmit={handleSubmit}>
          <input
            placeholder="Prenom"
            value={form.first_name}
            onChange={(e) => setForm({ ...form, first_name: e.target.value })}
            required
          />
          <input
            placeholder="Nom"
            value={form.last_name}
            onChange={(e) => setForm({ ...form, last_name: e.target.value })}
            required
          />
          <input
            type="date"
            value={form.date_of_birth}
            onChange={(e) => setForm({ ...form, date_of_birth: e.target.value })}
          />
          <select value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })}>
            <option value="">Sexe</option>
            <option value="F">Fille</option>
            <option value="M">Garcon</option>
          </select>
          <input
            placeholder="Classe"
            value={form.class_name}
            onChange={(e) => setForm({ ...form, class_name: e.target.value })}
          />
          <input
            placeholder="Nom du parent"
            value={form.parent_name}
            onChange={(e) => setForm({ ...form, parent_name: e.target.value })}
            required
          />
          <input
            placeholder="Telephone du parent"
            value={form.parent_phone}
            onChange={(e) => setForm({ ...form, parent_phone: e.target.value })}
            required
          />
          <input
            placeholder="Adresse"
            value={form.address}
            onChange={(e) => setForm({ ...form, address: e.target.value })}
          />
          <input
            type="number"
            step="0.01"
            min="0"
            placeholder="Montant mensuel"
            value={form.monthly_amount}
            onChange={(e) => setForm({ ...form, monthly_amount: e.target.value })}
            required
          />
          <input
            type="number"
            min="0"
            max="100"
            step="0.01"
            placeholder="Reduction (%)"
            value={form.discount_percent}
            onChange={(e) => setForm({ ...form, discount_percent: e.target.value })}
          />
          <input
            placeholder="Annee scolaire (ex: 2025-2026)"
            value={form.school_year}
            onChange={(e) => setForm({ ...form, school_year: e.target.value })}
          />
          <select
            value={form.class_level_id}
            onChange={(e) => setForm({ ...form, class_level_id: e.target.value })}
            required
          >
            <option value="">Choisir un niveau scolaire</option>
            {levelOptions.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </select>
          <p className="muted">Les niveaux de la base sont charges automatiquement; sinon une liste par defaut est utilisee.</p>
          <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
            <option value="ACTIVE">Actif</option>
            <option value="INACTIVE">Inactif</option>
          </select>
          <p className="muted">Mensualite apres reduction: {formatMoney(effectiveAmountPreview)}</p>
          <button type="submit" disabled={loading}>
            {loading ? (editingId ? "Mise a jour..." : "Creation...") : (editingId ? "Mettre a jour" : "Creer l'eleve")}
          </button>
          {editingId && (
            <button
              type="button"
              onClick={() => {
                setEditingId(null);
                setForm(emptyForm);
                setError("");
                setMessage("");
              }}
            >
              Annuler modification
            </button>
          )}
        </form>
        {message && <p className="muted">{message}</p>}
        {error && <p className="error-text">{error}</p>}
      </section>

      <section className="panel">
        <h3>Liste des eleves</h3>
        <form className="filters-grid" onSubmit={applyStudentFilters}>
          <input
            placeholder="Filtrer par nom"
            value={filters.last_name}
            onChange={(e) => setFilters({ ...filters, last_name: e.target.value })}
          />
          <input
            placeholder="Filtrer par prenom"
            value={filters.first_name}
            onChange={(e) => setFilters({ ...filters, first_name: e.target.value })}
          />
          <select
            value={filters.class_level}
            onChange={(e) => setFilters({ ...filters, class_level: e.target.value })}
          >
            <option value="">Tous les niveaux</option>
            {levelOptions.map((item) => (
              <option key={item.id} value={item.name}>{item.name}</option>
            ))}
          </select>
          <input
            placeholder="Filtrer par classe"
            value={filters.class_name}
            onChange={(e) => setFilters({ ...filters, class_name: e.target.value })}
          />
          <button
            type="button"
            className="secondary-btn"
            onClick={resetFilters}
          >
            Réinitialiser les filtres
          </button>
          <button type="submit" disabled={loading}>
            {loading ? "Recherche..." : "Rechercher"}
          </button>
        </form>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Nom</th>
                <th>Prenom</th>
                <th>Niveau</th>
                <th>Classe</th>
                <th>Annee scolaire</th>
                <th>Parent</th>
                <th>Telephone parent</th>
                <th>Mensualite</th>
                <th>Reduction</th>
                <th>Statut</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {filteredStudents.map((student) => (
                <tr key={student.id}>
                  <td>{student.last_name}</td>
                  <td>{student.first_name}</td>
                  <td>{student.class_level_name || student.class_level}</td>
                  <td>{student.class_name || "-"}</td>
                  <td>{student.school_year || "-"}</td>
                  <td>{student.parent_name}</td>
                  <td>{student.parent_phone || student.phone || "-"}</td>
                  <td>{formatMoney(student.monthly_amount)}</td>
                  <td>{Number(student.discount_percent || 0)}%</td>
                  <td>{student.status === "INACTIVE" ? "Inactif" : "Actif"}</td>
                  <td>
                    <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => handleEdit(student)}
                      >
                        Modifier
                      </button>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => handleDelete(student)}
                      >
                        Supprimer
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {filteredStudents.length === 0 && (
                <tr>
                  <td colSpan="11" className="table-empty">
                    {searchApplied ? "Aucun eleve trouve." : "Lancez une recherche ou appliquez un filtre."}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
