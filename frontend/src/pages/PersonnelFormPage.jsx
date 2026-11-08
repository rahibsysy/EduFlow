import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { getClassLevels } from "../services/classLevelService";
import { createPersonnelMember, getNextPersonnelNumber, getPersonnelMember, updatePersonnelMember } from "../services/personnelService";
import { getSubjects } from "../services/subjectService";
import { getActiveAcademicYear } from "../services/schoolService";
import { CLASS_LEVEL_CYCLES, classLevelCycle, classLevelLabel } from "../utils/classLevelDisplay";

const emptyForm = { personnel_type: "", first_name: "", last_name: "", gender: "", cin: "", phone: "", email: "", entry_date: "", job_title: "", contract_type: "", specialty: "", subject_ids: [], class_level_ids: [], department: "", main_mission: "", assignment: "", first_name_ar: "", last_name_ar: "", birth_date: "", birth_place: "", nationality: "", address: "", city: "", emergency_contact_name: "", emergency_contact_phone: "", main_diploma: "", work_time: "", observation: "" };
const required = ["personnel_type", "first_name", "last_name", "phone", "job_title"];
const labels = { personnel_type: "Type de personnel", first_name: "Prénom", last_name: "Nom", phone: "Téléphone", job_title: "Poste ou fonction" };

const asIds = (values) => (Array.isArray(values) ? values.map((value) => String(typeof value === "object" ? value.id : value)) : []);
const apiError = (error, fallback) => {
  const data = error?.response?.data;
  if (data?.errors && typeof data.errors === "object") return Object.values(data.errors).flat().filter(Boolean).join(" ");
  return data?.message || error?.message || fallback;
};
const isProfessor = (type) => type === "PROFESSOR" || type === "TEACHER";

export default function PersonnelFormPage() {
  const { id } = useParams(); const editing = Boolean(id); const navigate = useNavigate();
  const [form, setForm] = useState(emptyForm); const [number, setNumber] = useState(""); const [subjects, setSubjects] = useState([]); const [levels, setLevels] = useState([]);
  const [loading, setLoading] = useState(true); const [saving, setSaving] = useState(false); const [errors, setErrors] = useState({}); const [error, setError] = useState("");
  useEffect(() => {
    const load = async () => {
      setLoading(true); setError("");
      try {
        const requests = [getClassLevels(), getActiveAcademicYear()];
        if (editing) requests.push(getPersonnelMember(id)); else requests.push(getNextPersonnelNumber());
        const [levelData, activeYearData, detail] = await Promise.all(requests);
        const activeYear = activeYearData?.active_academic_year;
        const activeYearLabel = String(activeYear?.name || activeYear?.label || "").replace("-", "/");
        const visibleLevels = (Array.isArray(levelData) ? levelData : []).filter((level) =>
          level.status === "ACTIVE" && (!activeYearLabel || !level.school_year || String(level.school_year).replace("-", "/") === activeYearLabel)
        );
        setSubjects([]); setLevels(visibleLevels);
        if (editing) {
          setForm({ ...emptyForm, ...detail, subject_ids: asIds(detail.subject_ids || detail.subjects), class_level_ids: asIds(detail.class_level_ids || detail.class_levels), department: detail.department || detail.administrative_department || "", main_mission: detail.main_mission || detail.primary_mission || "", assignment: detail.assignment || detail.service_assignment || "", birth_date: detail.birth_date || detail.date_of_birth || "", main_diploma: detail.main_diploma || detail.main_degree || "", observation: detail.observation || detail.notes || "" });
          setNumber(detail.personnel_number || "");
        } else setNumber(detail.personnel_number || "");
      } catch (requestError) { setError(apiError(requestError, "Impossible de charger le dossier Personnel.")); }
      finally { setLoading(false); }
    }; load();
  }, [editing, id]);
  useEffect(() => {
    const classLevelIds = form.class_level_ids.map(Number).filter(Number.isInteger);
    if (!classLevelIds.length) { setSubjects([]); return; }
    let cancelled = false;
    Promise.all(classLevelIds.map((classLevelId) => getSubjects({ class_level_id: classLevelId })))
      .then((responses) => {
        if (cancelled) return;
        const available = Array.from(new Map(responses.flat().map((subject) => [String(subject.id), subject])).values());
        const availableIds = new Set(available.map((subject) => String(subject.id)));
        setSubjects(available);
        setForm((current) => ({ ...current, subject_ids: current.subject_ids.filter((subjectId) => availableIds.has(String(subjectId))) }));
      })
      .catch(() => { if (!cancelled) { setSubjects([]); setError("Impossible de charger les matières correspondant aux classes sélectionnées."); } });
    return () => { cancelled = true; };
  }, [form.class_level_ids.join(",")]);
  const set = (key, value) => { setForm((current) => ({ ...current, [key]: value })); setErrors((current) => ({ ...current, [key]: "" })); };
  const toggle = (key, value) => set(key, form[key].includes(String(value)) ? form[key].filter((item) => item !== String(value)) : [...form[key], String(value)]);
  const classesByCycle = CLASS_LEVEL_CYCLES.map((cycle) => ({ ...cycle, classes: levels.filter((level) => classLevelCycle(level) === cycle.id) }));
  const changeType = (nextType) => {
    const hasTeacherData = form.specialty || form.subject_ids.length || form.class_level_ids.length;
    const hasStaffData = form.department || form.main_mission || form.assignment;
    if (form.personnel_type && form.personnel_type !== nextType && ((isProfessor(form.personnel_type) && hasTeacherData) || (form.personnel_type === "ADMINISTRATIVE_STAFF" && hasStaffData)) && !window.confirm("Changer le type effacera les informations spécifiques déjà saisies. Continuer ?")) return;
    setForm((current) => ({ ...current, personnel_type: nextType, specialty: "", subject_ids: [], class_level_ids: [], department: "", main_mission: "", assignment: "" })); setErrors((current) => ({ ...current, personnel_type: "" }));
  };
  const validate = () => {
    const next = {}; required.forEach((key) => { if (!String(form[key] || "").trim()) next[key] = `${labels[key]} est obligatoire.`; });
    if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) next.email = "Adresse e-mail invalide.";
    if (isProfessor(form.personnel_type) && Boolean(form.class_level_ids.length) !== Boolean(form.subject_ids.length)) next.class_level_ids = "Sélectionnez à la fois les matières et les classes, ou laissez les affectations vides.";
    setErrors(next); return !Object.keys(next).length;
  };
  const payload = () => {
    const base = { personnel_type: form.personnel_type, first_name: form.first_name.trim(), last_name: form.last_name.trim(), gender: form.gender || null, cin: form.cin.trim() || null, phone: form.phone.trim(), email: form.email.trim() || null, entry_date: form.entry_date || null, job_title: form.job_title.trim(), contract_type: form.contract_type || null, first_name_ar: form.first_name_ar.trim() || null, last_name_ar: form.last_name_ar.trim() || null, birth_date: form.birth_date || null, birth_place: form.birth_place.trim() || null, nationality: form.nationality.trim() || null, address: form.address.trim() || null, city: form.city.trim() || null, emergency_contact_name: form.emergency_contact_name.trim() || null, emergency_contact_phone: form.emergency_contact_phone.trim() || null, main_diploma: form.main_diploma.trim() || null, work_time: form.work_time || null, observation: form.observation.trim() || null };
    return isProfessor(form.personnel_type) ? { ...base, personnel_type: "PROFESSOR", specialty: form.specialty.trim() || null, subject_ids: form.subject_ids.map(Number), class_level_ids: form.class_level_ids.map(Number) } : { ...base, department: form.department.trim() || null, main_mission: form.main_mission.trim() || null, assignment: form.assignment.trim() || null };
  };
  const submit = async (event) => { event.preventDefault(); setError(""); if (!validate() || saving) return; setSaving(true); try { if (editing) await updatePersonnelMember(id, payload()); else await createPersonnelMember(payload()); navigate("/personnel", { state: { message: editing ? "Dossier modifié avec succès." : "Membre ajouté avec succès." } }); } catch (requestError) { setError(apiError(requestError, "Enregistrement impossible.")); } finally { setSaving(false); } };
  if (loading) return <section className="panel">Chargement du dossier Personnel et des classes…</section>;
  return <form className="admin-grid personnel-form" onSubmit={submit}>
    <section className="panel students-page-header"><div><h2>{editing ? "Modifier un dossier Personnel" : "Ajouter un membre"}</h2><p className="muted">Les champs marqués d’un astérisque sont obligatoires.</p></div><div className="personnel-number">Matricule : <strong>{number || "Génération…"}</strong></div></section>
    {error && <p className="error-text">{error}</p>}
    <section className="panel"><h3>Informations principales</h3><div className="personnel-grid">
      <label>Type de personnel *<select value={form.personnel_type} onChange={(event) => changeType(event.target.value)}><option value="">Choisir un type</option><option value="PROFESSOR">Professeur</option><option value="ADMINISTRATIVE_STAFF">Staff administratif</option></select>{errors.personnel_type && <small className="error-text">{errors.personnel_type}</small>}</label>
      <label>Nom *<input value={form.last_name} onChange={(event) => set("last_name", event.target.value)} />{errors.last_name && <small className="error-text">{errors.last_name}</small>}</label>
      <label>Prénom *<input value={form.first_name} onChange={(event) => set("first_name", event.target.value)} />{errors.first_name && <small className="error-text">{errors.first_name}</small>}</label>
      <label>Sexe<select value={form.gender} onChange={(event) => set("gender", event.target.value)}><option value="">Non renseigné</option><option value="MALE">Masculin</option><option value="FEMALE">Féminin</option></select></label>
      <label>CIN<input value={form.cin} onChange={(event) => set("cin", event.target.value)} /></label><label>Téléphone *<input value={form.phone} onChange={(event) => set("phone", event.target.value)} />{errors.phone && <small className="error-text">{errors.phone}</small>}</label>
      <label>E-mail<input type="email" value={form.email} onChange={(event) => set("email", event.target.value)} />{errors.email && <small className="error-text">{errors.email}</small>}</label><label>Date d’entrée<input type="date" value={form.entry_date || ""} onChange={(event) => set("entry_date", event.target.value)} /></label>
      <label>Poste ou fonction *<input value={form.job_title} onChange={(event) => set("job_title", event.target.value)} />{errors.job_title && <small className="error-text">{errors.job_title}</small>}</label><label>Type de contrat<select value={form.contract_type} onChange={(event) => set("contract_type", event.target.value)}><option value="">Non renseigné</option><option value="CDI">CDI</option><option value="CDD">CDD</option><option value="VACATAIRE">Vacataire</option><option value="STAGE">Stage</option><option value="AUTRE">Autre</option></select></label>
    </div></section>
    {isProfessor(form.personnel_type) && <section className="panel"><h3>Informations professeur</h3><p className="muted">Les affectations pédagogiques sont facultatives à la création. Lorsqu’elles sont renseignées, choisissez au moins une classe et une matière.</p><div className="personnel-grid"><label>Spécialité<input value={form.specialty} onChange={(event) => set("specialty", event.target.value)} /></label><fieldset><legend>Matières</legend>{!form.class_level_ids.length ? <p className="muted">Sélectionnez une ou plusieurs classes pour afficher les matières compatibles.</p> : <div className="check-list">{subjects.map((subject) => <label key={subject.id}><input type="checkbox" checked={form.subject_ids.includes(String(subject.id))} onChange={() => toggle("subject_ids", subject.id)} />{subject.name}</label>)}{!subjects.length && <p className="muted">Aucune matière disponible pour les classes sélectionnées.</p>}</div>}{errors.subject_ids && <small className="error-text">{errors.subject_ids}</small>}</fieldset><fieldset className="wide personnel-class-cycles"><legend>Classes enseignées</legend>{levels.length ? <>{classesByCycle.map((cycle) => <details className="personnel-class-cycle" key={cycle.id} open><summary>{cycle.label}</summary>{cycle.classes.length ? <div className="check-list">{cycle.classes.map((level) => <label key={level.id}><input type="checkbox" checked={form.class_level_ids.includes(String(level.id))} onChange={() => toggle("class_level_ids", level.id)} />{classLevelLabel(level)}</label>)}</div> : <p className="muted">Aucune classe créée dans ce cycle.</p>}</details>)}{form.class_level_ids.length > 0 && <small className="muted">Sélection : {levels.filter((level) => form.class_level_ids.includes(String(level.id))).map(classLevelLabel).join(", ")}</small>}</> : <span className="muted">Aucune classe ou aucun niveau n’existe encore pour votre établissement.</span>}{errors.class_level_ids && <small className="error-text">{errors.class_level_ids}</small>}</fieldset></div></section>}
    {form.personnel_type === "ADMINISTRATIVE_STAFF" && <section className="panel"><h3>Informations staff administratif</h3><div className="personnel-grid"><label>Service ou département<input value={form.department} onChange={(event) => set("department", event.target.value)} /></label><label>Affectation<input value={form.assignment} onChange={(event) => set("assignment", event.target.value)} /></label><label className="wide">Mission principale<textarea value={form.main_mission} onChange={(event) => set("main_mission", event.target.value)} /></label></div></section>}
    <details className="panel"><summary>Informations complémentaires</summary><div className="personnel-grid details-grid"><label>Nom en arabe<input value={form.last_name_ar} onChange={(event) => set("last_name_ar", event.target.value)} /></label><label>Prénom en arabe<input value={form.first_name_ar} onChange={(event) => set("first_name_ar", event.target.value)} /></label><label>Date de naissance<input type="date" value={form.birth_date || ""} onChange={(event) => set("birth_date", event.target.value)} /></label><label>Lieu de naissance<input value={form.birth_place} onChange={(event) => set("birth_place", event.target.value)} /></label><label>Nationalité<input value={form.nationality} onChange={(event) => set("nationality", event.target.value)} /></label><label>Ville<input value={form.city} onChange={(event) => set("city", event.target.value)} /></label><label className="wide">Adresse<input value={form.address} onChange={(event) => set("address", event.target.value)} /></label><label>Contact d’urgence<input value={form.emergency_contact_name} onChange={(event) => set("emergency_contact_name", event.target.value)} /></label><label>Téléphone du contact<input value={form.emergency_contact_phone} onChange={(event) => set("emergency_contact_phone", event.target.value)} /></label><label>Diplôme principal<input value={form.main_diploma} onChange={(event) => set("main_diploma", event.target.value)} /></label><label>Temps de travail<select value={form.work_time} onChange={(event) => set("work_time", event.target.value)}><option value="">Non renseigné</option><option value="TEMPS_PLEIN">Temps plein</option><option value="TEMPS_PARTIEL">Temps partiel</option></select></label><label className="wide">Observation<textarea value={form.observation} onChange={(event) => set("observation", event.target.value)} /></label></div></details>
    <div className="form-actions personnel-actions"><button type="button" className="secondary-btn" onClick={() => navigate("/personnel")} disabled={saving}>Annuler</button><button disabled={saving}>{saving ? "Enregistrement…" : "Enregistrer"}</button></div>
  </form>;
}
