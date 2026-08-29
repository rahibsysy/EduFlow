import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { getSchoolById, updateSchool, uploadSchoolLogo, importSchoolData } from "../services/schoolService";

const TEMPLATE_HEADERS = [
  "Nom",
  "Prénom",
  "Date de naissance",
  "Sexe",
  "Classe",
  "Niveau scolaire",
  "Nom du parent",
  "Téléphone",
  "Adresse",
  "Montant de la mensualité",
];

const templateHref = `data:text/csv;charset=utf-8,${encodeURIComponent(`${TEMPLATE_HEADERS.join(";")}\n`)}`;
const isValidImportFile = (file) => /\.(xlsx|xls|csv)$/i.test(file?.name || "");

export default function SchoolDetailsPage() {
  const { id } = useParams();
  const [school, setSchool] = useState(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [isEditing, setIsEditing] = useState(false);
  const [formData, setFormData] = useState({});
  const [logoFile, setLogoFile] = useState(null);
  const [schoolDataFile, setSchoolDataFile] = useState(null);
  const [importProgress, setImportProgress] = useState(0);
  const [isImporting, setIsImporting] = useState(false);
  const [isDragging, setIsDragging] = useState(false);

  const load = async () => {
    try {
      const data = await getSchoolById(id);
      setSchool(data);
      setFormData(data || {});
      setError("");
    } catch (err) {
      setError(err?.response?.data?.message || err?.message || "Impossible de charger l'ecole");
      setSchool(null);
    }
  };

  useEffect(() => {
    load();
  }, [id]);

  const handleToggle = async () => {
    if (!school) return;
    const nextStatus = school.status === "ACTIVE" ? "INACTIVE" : "ACTIVE";
    try {
      await updateSchool(id, { status: nextStatus });
      setMessage(`Statut de l'ecole mis a jour: ${nextStatus === "ACTIVE" ? "Actif" : "Inactif"}`);
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || "Echec de mise a jour du statut");
    }
  };

  const handleEditSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setMessage("");

    try {
      let logo_path = formData.logo_path || school.logo_path;
      
      if (logoFile) {
        const upload = await uploadSchoolLogo(logoFile);
        logo_path = upload.logo_path || logo_path;
      }

      const updatePayload = {
        name: formData.name,
        code: formData.code,
        slug: formData.slug || undefined,
        email_domain: formData.email_domain,
        logo_path,
        phone: formData.phone || undefined,
        address: formData.address || undefined,
        city: formData.city || undefined,
        country: formData.country || undefined,
        primary_color: formData.primary_color,
        secondary_color: formData.secondary_color,
        currency: formData.currency,
        status: formData.status,
      };

      await updateSchool(id, updatePayload);
      setMessage("Ecole mise a jour avec succes.");
      setIsEditing(false);
      setLogoFile(null);
      await load();
    } catch (err) {
      setError(err?.response?.data?.message || "Echec de mise a jour de l'ecole");
    }
  };

  const handleSchoolDataFile = (file) => {
    setError("");
    if (!file) {
      setSchoolDataFile(null);
      return;
    }

    if (!isValidImportFile(file)) {
      setSchoolDataFile(null);
      setError("Format incorrect. Formats acceptés: .xlsx, .xls, .csv.");
      return;
    }

    setSchoolDataFile(file);
  };

  const handleImportSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setMessage("");
    setImportProgress(0);

    if (!schoolDataFile) {
      setError("Le fichier d'import des élèves est obligatoire.");
      return;
    }

    try {
      setIsImporting(true);
      const imported = await importSchoolData(id, schoolDataFile, setImportProgress);
      setMessage(`Import termine avec succes. ${imported.message || ""}`);
      setSchoolDataFile(null);
      setImportProgress(100);
    } catch (err) {
      const details = err?.response?.data?.details || [];
      const suffix = Array.isArray(details) && details.length ? ` ${details.join(" ")}` : "";
      setError((err?.response?.data?.message || "Echec de l'import de donnees.") + suffix);
    } finally {
      setIsImporting(false);
    }
  };

  if (!school) {
    return <section className="panel">Chargement...</section>;
  }

  return (
    <div className="admin-grid">
      <section className="panel hero-panel">
        <p className="brand-kicker">Details ecole</p>
        <h2>{school.name}</h2>
        <p className="muted">Code: {school.code} | Domaine: {school.email_domain}</p>
      </section>

      {!isEditing && (
        <section className="panel">
          <button type="button" onClick={() => setIsEditing(true)}>Modifier</button>
          <button type="button" onClick={handleToggle} style={{ marginLeft: 12 }}>Activer / desactiver</button>
          <Link to={`/super-admin/schools/${school.id}/admin`} style={{ marginLeft: 12 }}>Creer l'admin principal</Link>
          {message && <p className="muted">{message}</p>}
          {error && <p className="error-text">{error}</p>}
        </section>
      )}

      {isEditing && (
        <section className="panel">
          <h3>Modifier l'ecole</h3>
          <form className="form-grid" onSubmit={handleEditSubmit}>
            <input
              placeholder="Nom de l'ecole"
              value={formData.name || ""}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              required
            />
            <input
              placeholder="Code ecole"
              value={formData.code || ""}
              onChange={(e) => setFormData({ ...formData, code: e.target.value })}
              required
            />
            <input
              placeholder="Slug (optionnel)"
              value={formData.slug || ""}
              onChange={(e) => setFormData({ ...formData, slug: e.target.value })}
            />
            <input
              placeholder="Domaine email"
              value={formData.email_domain || ""}
              onChange={(e) => setFormData({ ...formData, email_domain: e.target.value })}
              required
            />
            <input
              placeholder="Telephone"
              value={formData.phone || ""}
              onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
            />
            <input
              placeholder="Adresse"
              value={formData.address || ""}
              onChange={(e) => setFormData({ ...formData, address: e.target.value })}
            />
            <input
              placeholder="Ville"
              value={formData.city || ""}
              onChange={(e) => setFormData({ ...formData, city: e.target.value })}
            />
            <input
              placeholder="Pays"
              value={formData.country || ""}
              onChange={(e) => setFormData({ ...formData, country: e.target.value })}
            />
            <div>
              <label>Couleur primaire</label>
              <input
                type="color"
                value={formData.primary_color || "#1E3A8A"}
                onChange={(e) => setFormData({ ...formData, primary_color: e.target.value })}
              />
            </div>
            <div>
              <label>Couleur secondaire</label>
              <input
                type="color"
                value={formData.secondary_color || "#22C55E"}
                onChange={(e) => setFormData({ ...formData, secondary_color: e.target.value })}
              />
            </div>
            <input
              placeholder="Devise (ex: MAD)"
              value={formData.currency || "MAD"}
              onChange={(e) => setFormData({ ...formData, currency: e.target.value.toUpperCase() })}
            />
            <select
              value={formData.status || "ACTIVE"}
              onChange={(e) => setFormData({ ...formData, status: e.target.value })}
            >
              <option value="ACTIVE">Actif</option>
              <option value="INACTIVE">Inactif</option>
            </select>
            <input
              type="file"
              accept=".png,.jpg,.jpeg,.svg,.webp"
              onChange={(e) => setLogoFile(e.target.files?.[0] || null)}
            />
            {logoFile && <p className="muted">Logo choisi: {logoFile.name}</p>}
            
            <div style={{ display: "flex", gap: "12px", gridColumn: "1/-1" }}>
              <button type="submit">Enregistrer les modifications</button>
              <button
                type="button"
                onClick={() => {
                  setIsEditing(false);
                  setFormData(school);
                  setLogoFile(null);
                  setError("");
                }}
              >
                Annuler
              </button>
            </div>
            
            {message && <p className="muted" style={{ gridColumn: "1/-1" }}>{message}</p>}
            {error && <p className="error-text" style={{ gridColumn: "1/-1" }}>{error}</p>}
          </form>
        </section>
      )}

      {!isEditing && (
        <section className="panel">
          <h3>Importer des eleves</h3>
          <form className="form-grid" onSubmit={handleImportSubmit}>
            <div
              className={`upload-dropzone ${isDragging ? "upload-dropzone-active" : ""}`}
              onDragOver={(e) => {
                e.preventDefault();
                setIsDragging(true);
              }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={(e) => {
                e.preventDefault();
                setIsDragging(false);
                handleSchoolDataFile(e.dataTransfer.files?.[0] || null);
              }}
            >
              <div>
                <p className="kpi-label">Importer les donnees des eleves *</p>
                <p className="muted">Glissez un fichier .xlsx, .xls ou .csv, ou sélectionnez-le.</p>
                {schoolDataFile && <p className="muted">Fichier choisi: {schoolDataFile.name}</p>}
              </div>
              <input
                type="file"
                accept=".xlsx,.xls,.csv"
                onChange={(e) => handleSchoolDataFile(e.target.files?.[0] || null)}
              />
            </div>
            <a className="text-link" href={templateHref} download="modele-import-eleves.csv">
              Telecharger le modele CSV
            </a>
            {isImporting && (
              <div className="progress-wrap">
                <div className="progress-bar" style={{ width: `${importProgress || 12}%` }} />
              </div>
            )}
            <button type="submit" disabled={isImporting} style={{ gridColumn: "1/-1" }}>
              {isImporting ? "Import en cours..." : "Importer les eleves"}
            </button>
            {message && <p className="muted" style={{ gridColumn: "1/-1" }}>{message}</p>}
            {error && <p className="error-text" style={{ gridColumn: "1/-1" }}>{error}</p>}
          </form>
        </section>
      )}

      {!isEditing && (
        <section className="panel">
          <h3>Lien vers les eleves</h3>
          <p className="muted">Une fois les eleves importes, vous pouvez les consulter et les gerer :</p>
          <Link to="/students" style={{ marginTop: "12px", display: "inline-block" }}>
            Voir tous les eleves
          </Link>
        </section>
      )}

      {!isEditing && (
        <section className="panel">
          <h3>Informations de l'ecole</h3>
          <div style={{ display: "grid", gap: "12px" }}>
            <div><strong>Code:</strong> {school.code}</div>
            <div><strong>Slug:</strong> {school.slug || "-"}</div>
            <div><strong>Domaine email:</strong> {school.email_domain}</div>
            <div><strong>Telephone:</strong> {school.phone || "-"}</div>
            <div><strong>Adresse:</strong> {school.address || "-"}</div>
            <div><strong>Ville:</strong> {school.city || "-"}</div>
            <div><strong>Pays:</strong> {school.country || "-"}</div>
            <div><strong>Devise:</strong> {school.currency}</div>
            <div><strong>Couleur primaire:</strong> <span style={{ display: "inline-block", width: "20px", height: "20px", backgroundColor: school.primary_color, border: "1px solid #ccc", verticalAlign: "middle", marginLeft: "8px" }} /></div>
            <div><strong>Couleur secondaire:</strong> <span style={{ display: "inline-block", width: "20px", height: "20px", backgroundColor: school.secondary_color, border: "1px solid #ccc", verticalAlign: "middle", marginLeft: "8px" }} /></div>
            <div><strong>Statut:</strong> {school.status === "ACTIVE" ? "Actif" : "Inactif"}</div>
            <div><strong>Creee le:</strong> {new Date(school.created_at).toLocaleDateString("fr-FR")}</div>
          </div>
        </section>
      )}
    </div>
  );
}
