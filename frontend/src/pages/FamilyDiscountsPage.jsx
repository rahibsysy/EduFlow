import { useState } from "react";
import useAuth from "../hooks/useAuth";
import { findFamilyCandidates } from "../services/familyService";
import { cancelFamilyDiscount, createFamilyDiscount, getFamilyDiscountHistory, getFamilyDiscounts, previewFamilyDiscount, updateFamilyDiscount } from "../services/familyDiscountService";
import { getChargeCategories } from "../services/studentFinanceService";

const money = (value) => new Intl.NumberFormat("fr-MA", { style: "currency", currency: "MAD" }).format(Number(value || 0));
const emptyForm = { family_id: "", discount_type: "PERCENTAGE", discount_value: "", reason: "", starts_on: "", ends_on: "", category_ids: [] };

export default function FamilyDiscountsPage() {
  const { user } = useAuth();
  const canManage = ["admin", "super_admin"].includes(user?.role);
  const [form, setForm] = useState(emptyForm);
  const [familySearch, setFamilySearch] = useState("");
  const [families, setFamilies] = useState([]);
  const [selectedFamily, setSelectedFamily] = useState(null);
  const [categories, setCategories] = useState([]);
  const [discounts, setDiscounts] = useState([]);
  const [preview, setPreview] = useState(null);
  const [selectedChargeIds, setSelectedChargeIds] = useState([]);
  const [editingId, setEditingId] = useState(null);
  const [history, setHistory] = useState(null);
  const [loading, setLoading] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  const load = async () => {
    setLoading(true); setError("");
    try { const [rows, cats] = await Promise.all([getFamilyDiscounts(), getChargeCategories()]); setDiscounts(Array.isArray(rows) ? rows : []); setCategories(Array.isArray(cats) ? cats : []); setLoaded(true); }
    catch (e) { setError(e?.response?.data?.message || "Impossible de charger les remises familiales."); }
    finally { setLoading(false); }
  };
  const searchFamilies = async (event) => {
    event.preventDefault(); setError("");
    if (!familySearch.trim()) { setFamilies([]); setError("Saisissez un nom, une référence ou un responsable."); return; }
    setLoading(true);
    try { const result = await findFamilyCandidates(familySearch.trim()); setFamilies(result?.families || []); }
    catch { setError("Recherche de famille impossible."); }
    finally { setLoading(false); }
  };
  const chooseFamily = (family) => { setSelectedFamily(family); setForm((p) => ({ ...p, family_id: String(family.id) })); setFamilies([]); setPreview(null); setSelectedChargeIds([]); };
  const payload = () => ({ ...form, family_id: Number(form.family_id), discount_value: Number(form.discount_value), category_ids: form.category_ids.map(Number), charge_ids: selectedChargeIds.map(Number) });
  const showPreview = async (event) => {
    event.preventDefault(); setError(""); setMessage(""); setPreview(null); setLoading(true);
    try { const result = await previewFamilyDiscount(payload()); setPreview(result); setSelectedChargeIds(result.charges.map((line) => line.charge_id)); }
    catch (e) { setError(e?.response?.data?.message || "Aperçu impossible."); }
    finally { setLoading(false); }
  };
  const save = async () => {
    setLoading(true); setError("");
    try { const result = editingId ? await updateFamilyDiscount(editingId, payload()) : await createFamilyDiscount(payload()); setMessage(editingId ? `Remise modifiée sur ${result.impact_count} charge(s).` : `Remise appliquée à ${result.impact_count} charge(s).`); setForm(emptyForm); setSelectedFamily(null); setPreview(null); setSelectedChargeIds([]); setEditingId(null); await load(); }
    catch (e) { setError(e?.response?.data?.message || (editingId ? "Modification impossible." : "Création impossible.")); }
    finally { setLoading(false); }
  };
  const cancel = async (row) => {
    const reason = window.prompt("Motif d’annulation (facultatif) :"); if (reason === null) return;
    setLoading(true); setError("");
    try { await cancelFamilyDiscount(row.id, { reason }); setMessage("Remise annulée ; les montants dus ont été rétablis sans modifier les paiements."); await load(); }
    catch (e) { setError(e?.response?.data?.message || "Annulation impossible."); }
    finally { setLoading(false); }
  };
  const showHistory = async (id) => { setLoading(true); setError(""); try { setHistory(await getFamilyDiscountHistory(id)); } catch { setError("Historique indisponible."); } finally { setLoading(false); } };
  const edit = (row) => { setEditingId(row.id); setSelectedFamily({ id: row.family_id, family_name: row.family_name, family_reference: row.family_reference }); setForm({ family_id: String(row.family_id), discount_type: row.discount_type, discount_value: String(row.discount_value), reason: row.reason || "", starts_on: row.starts_on || "", ends_on: row.ends_on || "", category_ids: [] }); setPreview(null); setMessage("Modifiez les paramètres puis enregistrez. Les impacts existants seront recalculés de façon transactionnelle."); window.scrollTo({ top: 0, behavior: "smooth" }); };

  return <div className="admin-grid">
    <section className="panel"><h2>Remises familiales</h2><p className="muted">Une remise est appliquée aux charges flexibles impayées des enfants d’une même famille. Les paiements et reçus existants ne sont jamais modifiés.</p></section>
    {!loaded && <section className="panel"><button type="button" onClick={load} disabled={loading}>{loading ? "Chargement…" : "Charger les remises"}</button></section>}
    {canManage && <section className="panel"><h3>{editingId ? "Modifier une remise familiale" : "Nouvelle remise familiale"}</h3>{!editingId && <form className="filters-grid" onSubmit={searchFamilies}><input value={familySearch} onChange={(e) => setFamilySearch(e.target.value)} placeholder="Nom, référence ou responsable"/><button disabled={loading}>Rechercher une famille</button></form>}{families.length > 0 && <div className="chips">{families.map((family) => <button type="button" key={family.id} className="secondary-btn" onClick={() => chooseFamily(family)}>{family.family_name} · {family.family_reference}</button>)}</div>}{selectedFamily && <p><strong>Famille :</strong> {selectedFamily.family_name} ({selectedFamily.family_reference})</p>}<form className="form-grid" onSubmit={showPreview}><label>Type<select value={form.discount_type} onChange={(e) => setForm({ ...form, discount_type: e.target.value })}><option value="PERCENTAGE">Pourcentage</option><option value="FIXED">Montant fixe par charge</option></select></label><label>Valeur<input required type="number" min="0" max={form.discount_type === "PERCENTAGE" ? 100 : undefined} step="0.01" value={form.discount_value} onChange={(e) => setForm({ ...form, discount_value: e.target.value })}/></label><label>Motif<input required value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })}/></label><label>Début<input type="date" value={form.starts_on} onChange={(e) => setForm({ ...form, starts_on: e.target.value })}/></label><label>Fin<input type="date" value={form.ends_on} onChange={(e) => setForm({ ...form, ends_on: e.target.value })}/></label><label>Catégories (aucune = toutes)<select multiple value={form.category_ids} onChange={(e) => setForm({ ...form, category_ids: Array.from(e.target.selectedOptions, (o) => o.value) })}>{categories.filter((c) => c.status === "ACTIVE").map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}</select></label>{editingId ? <><button type="button" onClick={save} disabled={loading || !form.family_id}>Enregistrer la modification</button><button type="button" className="secondary-btn" onClick={() => { setEditingId(null); setForm(emptyForm); setSelectedFamily(null); }}>Annuler la modification</button></> : <button disabled={loading || !form.family_id}>Prévisualiser l’impact</button>}</form>{preview && <div className="table-wrap"><h4>Impact prévu : -{money(preview.total_discount)}</h4>{preview.charges.length === 0 ? <p className="muted">Aucune charge éligible.</p> : <><table><thead><tr><th>Enfant</th><th>Charge</th><th>Avant</th><th>Remise</th><th>Après</th></tr></thead><tbody>{preview.charges.map((line) => <tr key={line.charge_id}><td>{line.student_name}</td><td>{line.category_label}</td><td>{money(line.before_amount)}</td><td>-{money(line.discount_amount)}</td><td>{money(line.final_amount)}</td></tr>)}</tbody></table><button type="button" onClick={save} disabled={loading}>Confirmer la remise</button></>}</div>}</section>}
    {error && <p className="error-text">{error}</p>}{message && <p className="success-text">{message}</p>}
    <section className="panel"><div className="form-actions"><h3>Historique des remises</h3><button type="button" className="secondary-btn" onClick={load} disabled={loading}>Actualiser</button></div>{loading && loaded && <p className="muted">Chargement…</p>}{loaded && discounts.length === 0 && <p className="muted">Aucune remise familiale enregistrée.</p>}{discounts.length > 0 && <div className="table-wrap"><table><thead><tr><th>Famille</th><th>Remise</th><th>Impact</th><th>Dates</th><th>État</th><th>Actions</th></tr></thead><tbody>{discounts.map((row) => <tr key={row.id}><td>{row.family_name}<br/><small>{row.family_reference}</small></td><td>{row.discount_type === "PERCENTAGE" ? `${row.discount_value}%` : money(row.discount_value)}<br/><small>{row.reason}</small></td><td>{row.impact_count} charge(s)<br/>-{money(row.total_discount)}</td><td>{row.starts_on || "—"} → {row.ends_on || "—"}</td><td>{row.status}</td><td><button type="button" className="secondary-btn" onClick={() => showHistory(row.id)} disabled={loading}>Historique</button>{canManage && row.status === "ACTIVE" && <><button type="button" className="secondary-btn" onClick={() => edit(row)} disabled={loading}>Modifier</button><button type="button" className="danger-btn" onClick={() => cancel(row)} disabled={loading}>Annuler</button></>}</td></tr>)}</tbody></table></div>}</section>
    {history && <section className="panel"><h3>Journal de remise</h3>{history.history?.length ? <div className="table-wrap"><table><thead><tr><th>Date</th><th>Action</th><th>Auteur</th><th>Motif</th></tr></thead><tbody>{history.history.map((item) => <tr key={item.id}><td>{item.created_at}</td><td>{item.action}</td><td>{[item.first_name, item.last_name].filter(Boolean).join(" ") || item.email || "—"}</td><td>{item.reason || "—"}</td></tr>)}</tbody></table></div> : <p className="muted">Aucun événement.</p>}</section>}
  </div>;
}
