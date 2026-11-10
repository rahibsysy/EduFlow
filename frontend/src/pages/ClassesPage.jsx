import { useEffect, useState } from "react";
import {
  createClassLevel,
  deleteClassLevel,
  getClassLevels,
  updateClassLevel,
} from "../services/classLevelService";
import { getSchools } from "../services/schoolService";
import { getStudents, importStudents, previewStudentImport } from "../services/studentService";
import { getSubjects, updateSubjectWeeklyHours } from "../services/subjectService";
import useAuth from "../hooks/useAuth";
import useI18n from "../hooks/useI18n";

const defaultSchoolYear = "2026/2027";
const schoolYearOptions = Array.from({ length: 8 }, (_, index) => {
  const start = 2024 + index;
  return `${start}/${start + 1}`;
});

const emptyForm = {
  school_id: "",
  level_name: "",
  group_name: "",
  school_year: defaultSchoolYear,
};

const emptyImportForm = {
  school_id: "",
  school_year: defaultSchoolYear,
  level_name: "",
  class_level_id: "",
};

const isValidImportFile = (file) => /\.(xlsx|csv)$/i.test(file?.name || "");

const normalizeSchoolYear = (value) => String(value || defaultSchoolYear).replace("-", "/");

const importErrorMessage = (error, fallback) => {
  const data = error?.response?.data;
  const message = typeof data?.message === "string" ? data.message : "";
  const details = Array.isArray(data?.details)
    ? data.details.filter((detail) => typeof detail === "string" && detail.trim())
    : [];

  if (!message) {
    return fallback;
  }

  return details.length ? `${message} ${details.join(" ")}` : message;
};

const classGroupLabel = (item) => {
  const group = String(item.group_name || "").trim();
  if (group) {
    return group;
  }

  const level = String(item.level_name || "").trim();
  const name = String(item.name || "").trim();
  return name && name !== level ? name : "-";
};

export default function ClassesPage() {
  const { user } = useAuth();
  const { t } = useI18n();
  const isSuperAdmin = user?.role === "super_admin";
  const [items, setItems] = useState([]);
  const [schools, setSchools] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [importForm, setImportForm] = useState(emptyImportForm);
  const [importFile, setImportFile] = useState(null);
  const [importProgress, setImportProgress] = useState(0);
  const [importPreview, setImportPreview] = useState(null);
  const [importReport, setImportReport] = useState(null);
  const [selectedClass, setSelectedClass] = useState(null);
  const [classStudents, setClassStudents] = useState([]);
  const [classSubjects, setClassSubjects] = useState([]);
  const [subjectSavingId, setSubjectSavingId] = useState(null);
  const [editingId, setEditingId] = useState(null);
  const [saving, setSaving] = useState(false);
  const [importLoading, setImportLoading] = useState(false);
  const [classStudentsLoading, setClassStudentsLoading] = useState(false);
  const [classSubjectsLoading, setClassSubjectsLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [importMessage, setImportMessage] = useState("");
  const [importError, setImportError] = useState("");

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
    load().catch(() => setError(t("classes.loadError")));
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
        school_year: normalizeSchoolYear(form.school_year),
      };

      if (isSuperAdmin) {
        payload.school_id = Number(form.school_id);
      }

      if (editingId) {
        await updateClassLevel(editingId, payload);
        setMessage(t("classes.updated"));
      } else {
        await createClassLevel(payload);
        setMessage(t("classes.created"));
      }

      setEditingId(null);
      setForm((prev) => ({ ...emptyForm, school_id: prev.school_id }));
      await load();
    } catch (err) {
      setError(t("classes.saveError"));
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
      school_year: normalizeSchoolYear(item.school_year),
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
    const confirmed = window.confirm(t("classes.confirmDelete", { name: item.name }));
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
      setMessage(t("classes.deleted"));
      await load();
    } catch (err) {
      setError(t("classes.deleteError"));
    }
  };

  const showClass = async (item) => {
    setSelectedClass(item);
    setClassStudents([]);
    setClassSubjects([]);
    setError("");
    setClassStudentsLoading(true);
    setClassSubjectsLoading(true);

    try {
      const [studentsData, subjectsData] = await Promise.all([
        getStudents({
          class_level_id: item.id,
          school_id: isSuperAdmin ? item.school_id : undefined,
        }),
        getSubjects({ class_level_id: item.id }),
      ]);
      setClassStudents(Array.isArray(studentsData) ? studentsData : []);
      setClassSubjects(Array.isArray(subjectsData) ? subjectsData : []);
    } catch (err) {
      setError(t("classes.detailsError"));
    } finally {
      setClassStudentsLoading(false);
      setClassSubjectsLoading(false);
    }
  };

  const closeClass = () => {
    setSelectedClass(null);
    setClassStudents([]);
    setClassSubjects([]);
  };

  const updateSubjectHours = async (subject) => {
    if (!selectedClass?.id || !subject?.id) {
      return;
    }

    setMessage("");
    setError("");
    setSubjectSavingId(subject.id);

    try {
      const weeklyHours = Number(subject.weekly_hours || 0);
      const result = await updateSubjectWeeklyHours(subject.id, selectedClass.id, weeklyHours);
      setClassSubjects((prev) =>
        prev.map((item) =>
          item.id === subject.id ? { ...item, weekly_hours: result.weekly_hours } : item
        )
      );
      setMessage(t("classes.hoursSaved"));
    } catch (err) {
      setError(t("classes.hoursError"));
    } finally {
      setSubjectSavingId(null);
    }
  };

  const handleImportFile = (file) => {
    setImportError("");
    setImportMessage("");
    setImportPreview(null);
    setImportReport(null);
    if (!file) {
      setImportFile(null);
      return;
    }

    if (!isValidImportFile(file)) {
      setImportFile(null);
      setImportError(t("classes.invalidFile"));
      return;
    }

    setImportFile(file);
  };

  const importClasses = items.filter((item) => {
    const sameSchool = !isSuperAdmin || String(item.school_id) === String(importForm.school_id);
    return sameSchool && normalizeSchoolYear(item.school_year) === normalizeSchoolYear(importForm.school_year);
  });
  const importLevels = [...new Set(importClasses.map((item) => item.level_name || item.name).filter(Boolean))];
  const selectableImportClasses = importClasses.filter((item) => (item.level_name || item.name) === importForm.level_name);

  const previewImport = async () => {
    setImportError("");
    setImportMessage("");
    setImportReport(null);
    if (!importFile || !importForm.class_level_id || !importForm.school_year) {
      setImportError("Sélectionnez l’année, le niveau, la classe et le fichier avant la prévisualisation.");
      return;
    }
    try {
      setImportLoading(true);
      const result = await previewStudentImport({
        ...importForm,
        school_id: isSuperAdmin ? importForm.school_id : undefined,
        file: importFile,
      });
      setImportPreview(result);
    } catch (err) {
      setImportError(importErrorMessage(err, "Prévisualisation impossible."));
    } finally {
      setImportLoading(false);
    }
  };

  const handleImportSubmit = async (e) => {
    e.preventDefault();
    setImportError("");
    setImportMessage("");
    setImportProgress(0);

    if (!importFile) {
      setImportError(t("classes.fileRequired"));
      return;
    }
    if (!importPreview) {
      setImportError("Prévisualisez le fichier puis confirmez l’importation.");
      return;
    }

    let result;
    try {
      setImportLoading(true);
      result = await importStudents(
        {
          ...importForm,
          school_id: isSuperAdmin ? importForm.school_id : undefined,
          file: importFile,
          confirm: true,
        },
        setImportProgress
      );

      const created = Number(result?.created_count || 0);
      const ignored = Number(result?.ignored_count || 0);
      const duplicates = Number(result?.duplicate_count || 0);
      const errorCount = Number(result?.error_count || 0);
      const importErrors = Array.isArray(result?.errors) ? result.errors.filter(Boolean) : [];
      const summary = `${created} élève(s) créé(s), ${ignored} ignoré(s), ${duplicates} doublon(s), ${errorCount} erreur(s).`;

      setImportReport({ created, ignored, duplicates, errorCount, errors: importErrors });
      if (created === 0 || importErrors.length) {
        setImportError([created === 0 ? `Aucun élève n’a été créé. ${summary}` : "", ...importErrors].filter(Boolean).join(" "));
      } else {
        setImportMessage(summary);
      }
      setImportForm((prev) => ({
        ...emptyImportForm,
        school_id: prev.school_id,
      }));
      setImportFile(null);
      setImportPreview(null);
      setImportProgress(100);
    } catch (err) {
      setImportError(importErrorMessage(err, t("classes.importError")));
      return;
    } finally {
      setImportLoading(false);
    }

    try {
      await load();
      if (result.class_level_id) {
        const importedClass = {
          id: result.class_level_id,
          name: result.class_name || t("classes.importedClass"),
          school_id: isSuperAdmin ? importForm.school_id : undefined,
          level_name: result.level_name || "",
          school_year: result.school_year || "",
        };
        await showClass(importedClass);
      }
    } catch (err) {
      // L'import est terminé ; seul l'affichage des détails a échoué.
      setImportError(t("classes.detailsError"));
    }
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>{t("classes.title")}</h2>
        <p className="muted">{t("classes.description")}</p>
      </section>

      <section className="panel">
        <h3>{editingId ? t("classes.edit") : t("classes.new")}</h3>
        <form className="form-grid" onSubmit={submit}>
          {isSuperAdmin && (
            <select
              value={form.school_id}
              onChange={(e) => setForm({ ...form, school_id: e.target.value })}
              required
              disabled={Boolean(editingId)}
            >
              <option value="">{t("classes.chooseSchool")}</option>
              {schools.map((school) => (
                <option key={school.id} value={school.id}>
                  {school.name}
                </option>
              ))}
            </select>
          )}
          <input
            placeholder={t("classes.levelPlaceholder")}
            value={form.level_name}
            onChange={(e) => setForm({ ...form, level_name: e.target.value })}
            required
          />
          <input
            placeholder={t("classes.groupPlaceholder")}
            value={form.group_name}
            onChange={(e) => setForm({ ...form, group_name: e.target.value })}
          />
          <select
            value={form.school_year}
            onChange={(e) => setForm({ ...form, school_year: e.target.value })}
            required
          >
            {schoolYearOptions.map((year) => (
              <option key={year} value={year}>
                {year}
              </option>
            ))}
          </select>
          <button type="submit" disabled={saving}>
            {saving ? t("common.saving") : editingId ? t("common.save") : t("classes.create")}
          </button>
          {editingId && (
            <button type="button" className="secondary-btn" onClick={cancelEdit}>
              {t("common.cancel")}
            </button>
          )}
        </form>
        {message && <p className="muted">{message}</p>}
        {error && <p className="error-text">{error}</p>}
      </section>

      <section className="panel">
        <h3>{t("classes.importTitle")}</h3>
        <form className="form-grid" onSubmit={handleImportSubmit}>
          {isSuperAdmin && (
            <select
              value={importForm.school_id}
              onChange={(e) => setImportForm({ ...importForm, school_id: e.target.value })}
              required
            >
              <option value="">{t("classes.chooseSchool")}</option>
              {schools.map((school) => (
                <option key={school.id} value={school.id}>
                  {school.name}
                </option>
              ))}
            </select>
          )}
          <select
            value={importForm.school_year}
            onChange={(e) => setImportForm((prev) => ({ ...prev, school_year: e.target.value, level_name: "", class_level_id: "" }))}
            required
          >
            {schoolYearOptions.map((year) => <option key={year} value={year}>{year}</option>)}
          </select>
          <select
            value={importForm.level_name}
            onChange={(e) => setImportForm((prev) => ({ ...prev, level_name: e.target.value, class_level_id: "" }))}
            required
          >
            <option value="">Sélectionnez le niveau</option>
            {importLevels.map((level) => <option key={level} value={level}>{level}</option>)}
          </select>
          <select
            value={importForm.class_level_id}
            onChange={(e) => setImportForm((prev) => ({ ...prev, class_level_id: e.target.value }))}
            required
          >
            <option value="">Sélectionnez la classe</option>
            {selectableImportClasses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
          <div className="upload-dropzone">
            <div>
              <p className="kpi-label">{t("classes.studentFile")}</p>
              <p className="muted">{t("classes.fileHelp")}</p>
              {importFile && <p className="muted">{t("classes.selectedFile", { name: importFile.name })}</p>}
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
          {!importPreview && <button type="button" className="secondary-btn" onClick={previewImport} disabled={importLoading}>
            Prévisualiser le fichier
          </button>}
          {importPreview && <section className="panel" aria-live="polite">
            <h4>Prévisualisation avant importation</h4>
            <p className="muted">Classe sélectionnée : {importPreview.level_name || importForm.level_name} — {importPreview.class_name}. Année : {importPreview.school_year}.</p>
            <p className="muted">{importPreview.report?.ready || 0} importable(s), {importPreview.report?.ignored || 0} ignoré(s), {importPreview.report?.duplicates || 0} doublon(s), {importPreview.report?.invalid || 0} invalide(s).</p>
            <div className="table-wrap"><table><thead><tr><th>Ligne</th><th>Nom</th><th>Prénom</th><th>CNE</th><th>Statut</th><th>Motif</th></tr></thead><tbody>{(importPreview.rows || []).map((row) => <tr key={row.line}><td>{row.line}</td><td>{row.last_name}</td><td>{row.first_name}</td><td>{row.massar_code || "—"}</td><td>{row.status}</td><td>{row.reason || "—"}</td></tr>)}</tbody></table></div>
            <div className="form-actions"><button type="submit" disabled={importLoading}>{importLoading ? t("classes.importing") : "Confirmer l’importation"}</button><button type="button" className="secondary-btn" disabled={importLoading} onClick={() => setImportPreview(null)}>Modifier la sélection</button></div>
          </section>}
          {importReport && (
            <div className={importReport.created > 0 ? "muted" : "error-text"} role="status">
              <p>Résultat : {importReport.created} créé(s), {importReport.ignored} ignoré(s), {importReport.duplicates} doublon(s), {importReport.errorCount} erreur(s).</p>
              {importReport.errors.length > 0 && <ul>{importReport.errors.map((item, index) => <li key={`${item}-${index}`}>{item}</li>)}</ul>}
            </div>
          )}
          {importMessage && <p className="muted" role="status">{importMessage}</p>}
          {importError && <p className="error-text" role="alert">{importError}</p>}
        </form>
      </section>

      <section className="panel">
        <h3>{t("classes.list")}</h3>
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{t("common.level")}</th>
                <th>{t("common.class")}</th>
                <th>{t("common.schoolYear")}</th>
                <th>{t("common.actions")}</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id}>
                  <td>{item.level_name || item.name}</td>
                  <td>{classGroupLabel(item)}</td>
                  <td>{item.school_year || "-"}</td>
                  <td>
                    <div style={{ display: "flex", gap: "8px", flexWrap: "wrap" }}>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => showClass(item)}
                      >
                        {t("common.view")}
                      </button>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => editClass(item)}
                      >
                        {t("common.edit")}
                      </button>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => deleteClass(item)}
                      >
                        {t("common.delete")}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {items.length === 0 && (
                <tr>
                  <td colSpan="4" className="table-empty">
                    {t("classes.empty")}
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
              <h3>{t("classes.studentsOf", { name: selectedClass.name })}</h3>
              <p className="muted">
                {selectedClass.level_name || t("common.class")}{selectedClass.school_year ? ` - ${selectedClass.school_year}` : ""}
              </p>
            </div>
            <button type="button" className="secondary-btn" style={{ width: "auto" }} onClick={closeClass}>
              {t("common.close")}
            </button>
          </div>

          <div className="table-wrap">
            <h4>{t("classes.subjectHours")}</h4>
            <table>
              <thead>
                <tr>
                  <th>{t("common.subject")}</th>
                  <th>{t("classes.weeklyHours")}</th>
                  <th>{t("common.action")}</th>
                </tr>
              </thead>
              <tbody>
                {classSubjects.map((subject) => (
                  <tr key={subject.id}>
                    <td>{subject.code || subject.name}</td>
                    <td>
                      <input
                        type="number"
                        min="0"
                        max="40"
                        value={subject.weekly_hours ?? 0}
                        onChange={(e) =>
                          setClassSubjects((prev) =>
                            prev.map((item) =>
                              item.id === subject.id ? { ...item, weekly_hours: e.target.value } : item
                            )
                          )
                        }
                      />
                    </td>
                    <td>
                      <button
                        type="button"
                        style={{ width: "auto", padding: "6px 10px" }}
                        onClick={() => updateSubjectHours(subject)}
                        disabled={subjectSavingId === subject.id}
                      >
                        {subjectSavingId === subject.id ? "…" : t("common.save")}
                      </button>
                    </td>
                  </tr>
                ))}
                {!classSubjectsLoading && classSubjects.length === 0 && (
                  <tr>
                    <td colSpan="3" className="table-empty">
                      {t("classes.noSubjects")}
                    </td>
                  </tr>
                )}
                {classSubjectsLoading && (
                  <tr>
                    <td colSpan="3" className="table-empty">
                      {t("common.loading")}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="table-wrap">
            <h4>{t("classes.studentsList")}</h4>
            <table>
              <thead>
                <tr>
                  <th>{t("common.lastName")}</th>
                  <th>{t("common.firstName")}</th>
                  <th>{t("classes.gender")}</th>
                  <th>{t("classes.birthDate")}</th>
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
                      {t("classes.emptyStudents")}
                    </td>
                  </tr>
                )}
                {classStudentsLoading && (
                  <tr>
                    <td colSpan="4" className="table-empty">
                      {t("common.loading")}
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
