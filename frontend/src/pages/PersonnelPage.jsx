import { useEffect, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { getPersonnel, updatePersonnelStatus } from "../services/personnelService";
import useAuth from "../hooks/useAuth";

const typeLabel = (value) => ({ TEACHER: "Professeur", PROFESSOR: "Professeur", ADMINISTRATIVE_STAFF: "Staff administratif" }[value] || "-");
const statusLabel = (value) => ({ ACTIF: "Actif", EN_CONGE: "En congé", ARCHIVE: "Archivé" }[value] || value);
const specialtyOrDepartment = (member) => ["TEACHER", "PROFESSOR"].includes(member.personnel_type)
  ? member.specialty || "-"
  : member.department || member.administrative_department || member.service_assignment || "-";

export default function PersonnelPage() {
  const navigate = useNavigate();
  const { can } = useAuth();
  const location = useLocation();
  const [personnel, setPersonnel] = useState([]);
  const [filters, setFilters] = useState({ search: "", personnel_type: "", status: "", service_assignment: "" });
  const [loading, setLoading] = useState(true);
  const [archivingId, setArchivingId] = useState(null);
  const [error, setError] = useState("");
  const [message, setMessage] = useState(location.state?.message || "");

  const load = async (nextFilters = filters) => {
    setLoading(true); setError("");
    try {
      const params = Object.fromEntries(Object.entries(nextFilters).filter(([, value]) => String(value).trim() !== ""));
      const result = await getPersonnel(params);
      setPersonnel(Array.isArray(result) ? result : result?.data || []);
    } catch (requestError) {
      setPersonnel([]);
      setError(requestError?.response?.data?.message || "Impossible de charger le personnel.");
    } finally { setLoading(false); }
  };

  useEffect(() => { load(); }, []);

  const submitFilters = (event) => { event.preventDefault(); load(); };
  const resetFilters = () => { const cleared = { search: "", personnel_type: "", status: "", service_assignment: "" }; setFilters(cleared); load(cleared); };
  const archive = async (member) => {
    if (!window.confirm(`Archiver le dossier de ${member.first_name} ${member.last_name} ?`)) return;
    setArchivingId(member.id); setError(""); setMessage("");
    try {
      await updatePersonnelStatus(member.id, "ARCHIVE");
      setMessage("Dossier archivé avec succès.");
      await load();
    } catch (requestError) {
      setError(requestError?.response?.data?.message || "Archivage impossible.");
    } finally { setArchivingId(null); }
  };

  return <div className="admin-grid personnel-page">
    <section className="panel students-page-header">
      <div><h2>Personnel</h2><p className="muted">Professeurs et staff administratif de l’établissement.</p></div>
      {can("personnel.manage") && <Link className="primary-link-btn" to="/personnel/new">+ Ajouter un membre</Link>}
    </section>
    <section className="panel">
      <form className="filters-grid" onSubmit={submitFilters}>
        <label>Rechercher<input value={filters.search} placeholder="Nom, prénom, matricule ou CIN" onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} /></label>
        <label>Type<select value={filters.personnel_type} onChange={(event) => setFilters((current) => ({ ...current, personnel_type: event.target.value }))}><option value="">Tous</option><option value="PROFESSOR">Professeurs</option><option value="ADMINISTRATIVE_STAFF">Staff administratif</option></select></label>
        <label>Statut<select value={filters.status} onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value }))}><option value="">Tous</option><option value="ACTIF">Actif</option><option value="EN_CONGE">En congé</option><option value="ARCHIVE">Archivé</option></select></label>
        <label>Service / affectation<input value={filters.service_assignment} onChange={(event) => setFilters((current) => ({ ...current, service_assignment: event.target.value }))} /></label>
        <div className="filter-actions"><button type="submit" disabled={loading}>Rechercher</button><button type="button" className="secondary-btn" onClick={resetFilters} disabled={loading}>Réinitialiser</button></div>
      </form>
    </section>
    {error && <p className="error-text">{error}</p>}{message && <p className="success-text">{message}</p>}
    <section className="panel table-wrap">
      {loading ? <p>Chargement du personnel…</p> : <table><thead><tr><th>Matricule</th><th>Nom complet</th><th>Type</th><th>Poste ou fonction</th><th>Service / spécialité</th><th>Téléphone</th><th>Statut</th><th>Actions</th></tr></thead><tbody>
        {personnel.map((member) => <tr key={member.id}><td>{member.personnel_number}</td><td>{member.last_name} {member.first_name}</td><td>{typeLabel(member.personnel_type)}</td><td>{member.job_title || member.function_name || "-"}</td><td>{specialtyOrDepartment(member)}</td><td>{member.phone || "-"}</td><td><span className={`status-badge status-${String(member.status || "").toLowerCase()}`}>{statusLabel(member.status)}</span></td><td>{can("personnel.manage") && <div className="table-actions"><button type="button" className="secondary-btn" onClick={() => navigate(`/personnel/${member.id}/edit`)}>Modifier</button>{member.status !== "ARCHIVE" && <button type="button" className="danger-btn" disabled={archivingId === member.id} onClick={() => archive(member)}>{archivingId === member.id ? "Archivage…" : "Archiver"}</button>}</div>}</td></tr>)}
        {!personnel.length && <tr><td colSpan="8" className="empty-cell">Aucun membre du personnel ne correspond à votre recherche.</td></tr>}
      </tbody></table>}
    </section>
  </div>;
}
