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
    setError("");
    setImportPreview(null);
    if (!file) {
      setImportFile(null);
      return;
    }

    if (!isValidImportFile(file)) {
      setImportFile(null);
      setError(t("classes.invalidFile"));
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
    setError("");
    setMessage("");
    if (!importFile || !importForm.class_level_id || !importForm.school_year) {
      setError("Sélectionnez l’année, le niveau, la classe et le fichier avant la prévisualisation.");
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
      setError(importErrorMessage(err, "Prévisualisation impossible."));
    } finally {
      setImportLoading(false);
    }
  };

  const handleImportSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setMessage("");
    setImportProgress(0);

    if (!importFile) {
      setError(t("classes.fileRequired"));
      return;
    }
    if (!importPreview) {
      setError("Prévisualisez le fichier puis confirmez l’importation.");
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

      setMessage(t("classes.imported"));
      setImportForm((prev) => ({
        ...emptyImportForm,
        school_id: prev.school_id,
      }));
      setImportFile(null);
      setImportPreview(null);
      setImportProgress(100);
    } catch (err) {
      setError(importErrorMessage(err, t("classes.importError")));
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
      setError(t("classes.detailsError"));
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
          <button type="button" className="secondary-btn" onClick={previewImport} disabled={importLoading}>
            Prévisualiser
          </button>
          {importPreview && (
            <p className="muted">
              Prévisualisation : {importPreview.report?.ready || 0} importable(s), {importPreview.report?.ignored || 0} ignoré(s), {importPreview.report?.invalid || 0} invalide(s). Confirmez pour écrire en base.
            </p>
          )}
          <button type="submit" disabled={importLoading}>
            {importLoading ? t("classes.importing") : t("classes.import")}
          </button>
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
