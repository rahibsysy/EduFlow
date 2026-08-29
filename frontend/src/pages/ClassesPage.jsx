import { useEffect, useState } from "react";
import {
  createClassLevel,
  deleteClassLevel,
  getClassLevels,
  updateClassLevel,
} from "../services/classLevelService";
import { getSchools } from "../services/schoolService";
import { getStudents, importStudents } from "../services/studentService";
import useAuth from "../hooks/useAuth";

const emptyForm = {
  school_id: "",
  level_name: "",
  group_name: "",
  school_year: "",
};

const emptyImportForm = {
  school_id: "",
};

const isValidImportFile = (file) => /\.(xlsx|csv)$/i.test(file?.name || "");

export default function ClassesPage() {
  const { user } = useAuth();
  const isSuperAdmin = user?.role === "super_admin";
  const [items, setItems] = useState([]);
  const [schools, setSchools] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [importForm, setImportForm] = useState(emptyImportForm);
  const [importFile, setImportFile] = useState(null);
  const [importProgress, setImportProgress] = useState(0);
  const [selectedClass, setSelectedClass] = useState(null);
  const [classStudents, setClassStudents] = useState([]);
  const [editingId, setEditingId] = useState(null);
  const [saving, setSaving] = useState(false);
  const [importLoading, setImportLoading] = useState(false);
  const [classStudentsLoading, setClassStudentsLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const load = async () => {
    const [levelsData, schoolsData] = await Promise.all([
      getClassLevels(),
      isSuperAdmin ? getSchools() : Promise.resolve([]),
    ]);
    const safeLevels = Array.isArray(levelsData) ? levelsData : [];
    const safeSchools = Array.isArray(schoolsData) ? schoolsData : [];

    setItems(safeLevels);
    setSchools(safeSchools);

    if (isSuperAdmin && safeSchools.length) {
      setImportForm((prev) => ({
        ...prev,
        school_id: prev.school_id || String(safeSchools[0].id),
      }));
      setForm((prev) => ({
        ...prev,
        school_id: prev.school_id || String(safeSchools[0].id),
      }));
    }
  };

  useEffect(() => {
    load().catch(() => setError("Impossible de charger les classes."));
  }, [isSuperAdmin]);

  const submit = async (e) => {
    e.preventDefault();
    setMessage("");
    setError("");

    try {
      setSaving(true);
      const levelName = form.level_name.trim();
      const groupName = form.group_name.trim();
      const payload = {
        name: groupName ? `${levelName} ${groupName}` : levelName,
        level_name: levelName,
        group_name: groupName || null,
        school_year: form.school_year.trim() || null,
      };

      if (isSuperAdmin) {
        payload.school_id = Number(form.school_id);
      }

      if (editingId) {
        await updateClassLevel(editingId, payload);
        setMessage("Classe modifiee avec succes.");
      } else {
        await createClassLevel(payload);
        setMessage("Classe creee avec succes.");
      }

      setEditingId(null);
      setForm((prev) => ({ ...emptyForm, school_id: prev.school_id }));
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || "Echec d'enregistrement de classe.");
    } finally {
      setSaving(false);
    }
  };

  const editClass = (item) => {
    setEditingId(item.id);
    setForm({
      school_id: item.school_id ? String(item.school_id) : form.school_id,
      level_name: item.level_name || item.name || "",
      group_name: item.group_name || "",
      school_year: item.school_year || "",
    });
    setMessage("");
    setError("");
  };

  const cancelEdit = () => {
    setEditingId(null);
    setForm((prev) => ({ ...emptyForm, school_id: prev.school_id }));
    setMessage("");
    setError("");
  };

  const deleteClass = async (item) => {
    const confirmed = window.confirm(`Supprimer la classe ${item.name} ?`);
    if (!confirmed) {
      return;
    }

    setMessage("");
    setError("");
    try {
      await deleteClassLevel(item.id);
      if (editingId === item.id) {
        setEditingId(null);
        setForm((prev) => ({ ...emptyForm, school_id: prev.school_id }));
      }
      if (selectedClass?.id === item.id) {
        setSelectedClass(null);
        setClassStudents([]);
      }
      setMessage("Classe supprimee avec succes.");
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || "Echec de suppression de classe.");
    }
  };

  const showClass = async (item) => {
    setSelectedClass(item);
    setClassStudents([]);
    setError("");
    setClassStudentsLoading(true);

    try {
      const data = await getStudents({
        class_level_id: item.id,
        school_id: isSuperAdmin ? item.school_id : undefined,
      });
      setClassStudents(Array.isArray(data) ? data : []);
    } catch (err) {
      setError(err?.response?.data?.message || "Impossible de charger les eleves de cette classe.");
    } finally {
      setClassStudentsLoading(false);
    }
  };

  const closeClass = () => {
    setSelectedClass(null);
    setClassStudents([]);
  };

  const handleImportFile = (file) => {
    setError("");
    if (!file) {
      setImportFile(null);
      return;
    }

    if (!isValidImportFile(file)) {
      setImportFile(null);
      setError("Format incorrect. Formats acceptes: .xlsx ou .csv.");
      return;
    }

    setImportFile(file);
  };

  const handleImportSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setMessage("");
    setImportProgress(0);

    if (!importFile) {
      setError("Veuillez selectionner un fichier .xlsx ou .csv.");
      return;
    }

    try {
      setImportLoading(true);
      const result = await importStudents(
        {
          ...importForm,
          school_id: isSuperAdmin ? importForm.school_id : undefined,
          file: importFile,
        },
        setImportProgress
      );

      setMessage(result.message || "Classe importee avec succes.");
      setImportForm((prev) => ({
        ...emptyImportForm,
        school_id: prev.school_id,
      }));
      setImportFile(null);
      setImportProgress(100);
      await load();
      if (result.class_level_id) {
        const importedClass = {
          id: result.class_level_id,
          name: result.class_name || "Classe importee",
          school_id: isSuperAdmin ? importForm.school_id : undefined,
          level_name: result.level_name || "",
          school_year: result.school_year || "",
        };
        await showClass(importedClass);
      }
    } catch (err) {
      const details = err?.response?.data?.details || [];
      const suffix = Array.isArray(details) && details.length ? ` ${details.join(" ")}` : "";
      setError((err?.response?.data?.message || "Echec de l'import de la classe.") + suffix);
    } finally {
      setImportLoading(false);
    }
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>Classes</h2>
        <p className="muted">Creer les niveaux, puis ajouter un groupe quand il existe plusieurs classes pour le même niveau.</p>
      </section>

      <section className="panel">
        <h3>{editingId ? "Modifier la classe" : "Nouvelle classe"}</h3>
        <form className="form-grid" onSubmit={submit}>
          {isSuperAdmin && (
            <select
              value={form.school_id}
              onChange={(e) => setForm({ ...form, school_id: e.target.value })}
              required
              disabled={Boolean(editingId)}
            >
              <option value="">Choisir une ecole</option>
              {schools.map((school) => (
                <option key={school.id} value={school.id}>
                  {school.name}
                </option>
              ))}
            </select>
          )}
          <input
            placeholder="Niveau (ex: 1ere annee)"
            value={form.level_name}
            onChange={(e) => setForm({ ...form, level_name: e.target.value })}
            required
          />
          <input
            placeholder="Classe/Groupe (ex: A, B, C)"
            value={form.group_name}
            onChange={(e) => setForm({ ...form, group_name: e.target.value })}
          />
          <input
            placeholder="Annee scolaire (ex: 2026-2027)"
            value={form.school_year}
            onChange={(e) => setForm({ ...form, school_year: e.target.value })}
          />
          <button type="submit" disabled={saving}>
            {saving ? "Enregistrement..." : editingId ? "Mettre a jour" : "Creer la classe"}
          </button>
          {editingId && (
            <button type="button" className="secondary-btn" onClick={cancelEdit}>
              Annuler
            </button>
          )}
        </form>
        {message && <p className="muted">{message}</p>}
        {error && <p className="error-text">{error}</p>}
      </section>

      <section className="panel">
        <h3>Importer les eleves d'une classe</h3>
        <form className="form-grid" onSubmit={handleImportSubmit}>
          {isSuperAdmin && (
            <select
              value={importForm.school_id}
              onChange={(e) => setImportForm({ ...importForm, school_id: e.target.value })}
              required
            >
              <option value="">Choisir une ecole</option>
              {schools.map((school) => (
                <option key={school.id} value={school.id}>
                  {school.name}
                </option>
              ))}
            </select>
          )}
          <div className="upload-dropzone">
            <div>
              <p className="kpi-label">Fichier des eleves</p>
              <p className="muted">Liste officielle avec Classe, Niveau, Annee scolaire, Nom et Prenom.</p>
              {importFile && <p className="muted">Fichier choisi: {importFile.name}</p>}
            </div>
            <input
              type="file"
              accept=".xlsx,.csv"
              onChange={(e) => handleImportFile(e.target.files?.[0] || null)}
              required
            />
          </div>
          {importLoading && importProgress > 0 && (
            <div className="progress-wrap">
              <div className="progress-bar" style={{ width: `${importProgress}%` }} />
            </div>
          )}
          <button type="submit" disabled={importLoading}>
            {importLoading ? "Import..." : "Importer les eleves"}
          </button>
        </form>
      </section>

      <section className="panel">
        <h3>Liste des classes</h3>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Classe</th>
                <th>Niveau</th>
                <th>Classe/Groupe</th>
                <th>Annee scolaire</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{item.level_name || item.name}</td>
                  <td>{item.group_name || "-"}</td>
                  <td>{item.school_year || "-"}</td>
                  <td>
                    <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => showClass(item)}
                      >
                        Voir
                      </button>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => editClass(item)}
                      >
                        Modifier
                      </button>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => deleteClass(item)}
                      >
                        Supprimer
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan="4" className="table-empty">
                    Aucune classe trouvee.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </section>

      {selectedClass && (
        <section className="panel">
          <div style={{ display: "flex", justifyContent: "space-between", gap: "12px", flexWrap: "wrap" }}>
            <div>
              <h3>Eleves de {selectedClass.name}</h3>
              <p className="muted">
                {selectedClass.level_name || "Classe"}{selectedClass.school_year ? ` - ${selectedClass.school_year}` : ""}
              </p>
            </div>
            <button type="button" className="secondary-btn" style={{ width: "auto" }} onClick={closeClass}>
              Fermer
            </button>
          </div>

          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Nom</th>
                  <th>Prenom</th>
                  <th>Genre</th>
                  <th>Date naissance</th>
                </tr>
              </thead>
              <tbody>
                {classStudents.map((student) => (
                  <tr key={student.id}>
                    <td>{student.last_name}</td>
                    <td>{student.first_name}</td>
                    <td>{student.gender || "-"}</td>
                    <td>{student.date_of_birth || "-"}</td>
                  </tr>
                ))}
                {!classStudentsLoading && classStudents.length === 0 && (
                  <tr>
                    <td colSpan="4" className="table-empty">
                      Aucun eleve trouve dans cette classe.
                    </td>
                  </tr>
                )}
                {classStudentsLoading && (
                  <tr>
                    <td colSpan="4" className="table-empty">
                      Chargement...
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      )}
    </div>
  );
}
