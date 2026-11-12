import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { getClassLevelFees, getClassLevels } from "../services/classLevelService";
import {
  createStudent,
  getStudentById,
  updateStudent,
} from "../services/studentService";
import { getChargeCategories } from "../services/studentFinanceService";
import { SCHOOL_YEAR_MONTH_OPTIONS } from "../config/schoolOptions";

const guardian = () => ({
  full_name: "",
  phone_primary: "",
  phone_secondary: "",
  email: "",
  relationship_type: "Responsable",
});
const charge = () => ({
  charge_category_id: "",
  label: "",
  original_amount: "",
  discount_type: "",
  discount_value: "",
  discount_reason: "",
  billing_month: "",
  year_value: "",
  due_date: "",
});
const blank = {
  first_name: "",
  last_name: "",
  first_name_ar: "",
  last_name_ar: "",
  date_of_birth: "",
  gender: "",
  address: "",
  class_level_id: "",
  status: "REGISTERED",
  internal_number: "",
  massar_code: "",
  parent_name: "Responsable",
  parent_phone: "N/A",
  tuition_enabled: true,
  monthly_discount_type: "",
  monthly_discount_value: "",
  monthly_discount_reason: "",
  guardians: [guardian()],
  charges: [],
};
const total = (c) => {
  const a = Number(c.original_amount || 0),
    v = Number(c.discount_value || 0),
    d =
      c.discount_type === "PERCENTAGE"
        ? (a * v) / 100
        : c.discount_type === "FIXED"
          ? v
          : 0;
  return { a, d, f: Math.max(0, a - d) };
};
const money = (n) =>
  `${new Intl.NumberFormat("fr-MA", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n || 0))} DH`;

export default function StudentFormPage() {
  const { id } = useParams(),
    editing = Boolean(id),
    navigate = useNavigate();
  const [form, setForm] = useState(blank),
    [levels, setLevels] = useState([]),
    [categories, setCategories] = useState([]),
    [tariff, setTariff] = useState(null),
    [schoolYear, setSchoolYear] = useState(""),
    [levelName, setLevelName] = useState(""),
    [error, setError] = useState(""),
    [saving, setSaving] = useState(false);
  useEffect(() => {
    let ok = true;
    (async () => {
      try {
        const [l, c, d] = await Promise.all([
          getClassLevels(),
          getChargeCategories(),
          editing ? getStudentById(id) : Promise.resolve(null),
        ]);
        if (!ok) return;
        setLevels(l || []);
        setCategories((c || []).filter((x) => x.status === "ACTIVE" && ["TRANSPORT", "DAYCARE", "SMOCK", "PAPER", "REGISTRATION_INSURANCE"].includes(x.code)));
        if (d) {
          const s = d.student || d;
          const selected = (l || []).find((item) => String(item.id) === String(s.class_level_id));
          if (selected) { setSchoolYear(selected.school_year || ""); setLevelName(selected.level_name || selected.name || ""); }
          setForm((f) => ({
            ...f,
            ...s,
            massar_code: s.massar_code || s.cne || "",
            class_level_id: String(s.class_level_id || ""),
            tuition_enabled: Number(s.monthly_amount || 0) > 0,
            monthly_discount_type: s.monthly_discount_type || (Number(s.discount_percent || 0) ? "PERCENTAGE" : ""),
            monthly_discount_value: s.monthly_discount_value ?? s.discount_percent ?? "",
            monthly_discount_reason: s.monthly_discount_reason || "",
            parent_phone: d.parent?.phone || s.phone || "N/A",
            guardians: d.guardians?.length
              ? d.guardians.map((x) => ({ ...guardian(), ...x }))
              : f.guardians,
            charges: (d.charges || []).map((x) => ({
              ...x,
              charge_category_id: String(x.charge_category_id || ""),
              original_amount: x.original_amount || x.total_amount,
              discount_value: x.discount_value || "",
              discount_reason: x.notes || "",
            })),
          }));
        }
      } catch {
        setError("Impossible de charger le dossier.");
      }
    })();
    return () => {
      ok = false;
    };
  }, [id, editing]);
  const selectedClass = useMemo(() => levels.find((item) => String(item.id) === String(form.class_level_id)), [levels, form.class_level_id]);
  const years = useMemo(() => [...new Set(levels.map((item) => item.school_year).filter(Boolean))].sort(), [levels]);
  const levelOptions = useMemo(() => [...new Set(levels.filter((item) => !schoolYear || item.school_year === schoolYear).map((item) => item.level_name || item.name))].sort(), [levels, schoolYear]);
  const classes = useMemo(() => levels.filter((item) => (!schoolYear || item.school_year === schoolYear) && (!levelName || (item.level_name || item.name) === levelName)), [levels, schoolYear, levelName]);
  useEffect(() => { if (!selectedClass) { setTariff(null); return; } let live = true; setTariff(null); getClassLevelFees(selectedClass.id).then((data) => { if (live) setTariff(data); }).catch(() => { if (live) setTariff({ configured: false }); }); return () => { live = false; }; }, [selectedClass?.id]);
  const set = (k, v) => setForm((x) => ({ ...x, [k]: v }));
  const setGuardian = (i, k, v) =>
    setForm((x) => ({
      ...x,
      guardians: x.guardians.map((g, n) => (n === i ? { ...g, [k]: v } : g)),
    }));
  const setCharge = (i, k, v) =>
    setForm((x) => ({
      ...x,
      charges: x.charges.map((c, n) => (n === i ? { ...c, [k]: v } : c)),
    }));
  const schoolYearStart = useMemo(() => {
    const match = String(selectedClass?.school_year || schoolYear).match(/\d{4}/);
    return match ? Number(match[0]) : null;
  }, [selectedClass?.school_year, schoolYear]);
  const addOptionalMonthlyCharge = (code) => {
    const category = categories.find((item) => item.code === code);
    if (!category) return;
    setForm((current) => {
      if (current.charges.some((item) => String(item.charge_category_id) === String(category.id) && !item.id)) {
        return current;
      }
      return {
        ...current,
        charges: [
          ...current.charges,
          {
            ...charge(),
            charge_category_id: String(category.id),
            label: category.label,
            original_amount: String(category.default_amount || ""),
          },
        ],
      };
    });
  };
  const summary = useMemo(
    () =>
      form.charges.reduce(
        (r, c) => {
          const x = total(c);
          return { a: r.a + x.a, d: r.d + x.d, f: r.f + x.f };
        },
        { a: 0, d: 0, f: 0 },
      ),
    [form.charges],
  );
  const monthlyLine = total({ original_amount: tariff?.monthly_amount || 0, discount_type: form.monthly_discount_type, discount_value: form.monthly_discount_value });
  const submit = async (e) => {
    e.preventDefault();
    setError("");
    if (!form.class_level_id) {
      setError("Sélectionnez une classe valide.");
      return;
    }
    if (form.tuition_enabled && (!tariff?.configured || Number(tariff.monthly_amount) <= 0)) { setError("Tarif non configuré pour cette classe."); return; }
    if (form.monthly_discount_type && !form.monthly_discount_reason.trim()) { setError("Le motif de réduction de mensualité est obligatoire."); return; }
    for (const c of form.charges) {
      const x = total(c);
      const category = categories.find((item) => String(item.id) === String(c.charge_category_id));
      if (
        !c.charge_category_id ||
        x.a < 0 ||
        x.d < 0 ||
        x.f < 0 ||
        (c.discount_type === "PERCENTAGE" && Number(c.discount_value) > 100)
        || (c.discount_type && !c.discount_reason?.trim())
        || (category?.periodicity === "MONTHLY" && (!c.billing_month || !c.year_value))
      ) {
        setError("Vérifiez les frais et réductions.");
        return;
      }
    }
    setSaving(true);
    try {
      const guardians = form.guardians.filter((g) => g.full_name.trim());
      const main = guardians[0];
      const { school_year, ...studentData } = form;
      const payload = {
        ...studentData,
        modern_profile: true,
        parent_name: main?.full_name || form.parent_name,
        parent_phone: main?.phone_primary || form.parent_phone,
        guardians,
        charges: form.charges
          .filter((c) => !c.id)
          .map((c) => ({
            ...c,
            original_amount: Number(c.original_amount || 0),
            discount_value: Number(c.discount_value || 0),
            billing_month: c.billing_month ? Number(c.billing_month) : null,
            year_value: c.year_value ? Number(c.year_value) : null,
          })),
      };
      const r = editing
        ? await updateStudent(id, payload)
        : await createStudent(payload);
      navigate(`/students/${r.id || id}`);
    } catch (e) {
      setError(e.response?.data?.message || "Enregistrement impossible.");
    } finally {
      setSaving(false);
    }
  };
  return (
    <div className="admin-grid student-form-page">
      <section className="panel panel-header">
        <div>
          <h2>{editing ? "Modifier l’élève" : "Ajouter un élève"}</h2>
          <p className="muted">Dossier scolaire, responsables et frais.</p>
        </div>
        <Link className="secondary-btn button-link" to="/students">
          Retour
        </Link>
      </section>
      <form className="admin-grid" onSubmit={submit}>
        <section className="panel student-form-section">
          <h3>1. Informations élève</h3>
          <div className="student-form-grid">
            {[
              ["first_name", "Prénom"],
              ["last_name", "Nom"],
              ["first_name_ar", "Prénom arabe"],
              ["last_name_ar", "Nom arabe"],
              ["internal_number", "Matricule"],
              ["massar_code", "CNE"],
            ].map(([k, l]) => (
              <label key={k}>
                {l}
                <input
                  required={k === "first_name" || k === "last_name"}
                  dir={k.endsWith("_ar") ? "rtl" : undefined}
                  value={form[k] || ""}
                  onChange={(e) => set(k, e.target.value)}
                />
              </label>
            ))}
            <label>
              Date de naissance
              <input
                type="date"
                value={form.date_of_birth || ""}
                onChange={(e) => set("date_of_birth", e.target.value)}
              />
            </label>
            <label>
              Sexe
              <select
                value={form.gender || ""}
                onChange={(e) => set("gender", e.target.value)}
              >
                <option value="">—</option>
                <option value="F">Féminin</option>
                <option value="M">Masculin</option>
              </select>
            </label>
            <label>
              Statut
              <select
                value={form.status}
                onChange={(e) => set("status", e.target.value)}
              >
                {[
                  "PRE_REGISTERED",
                  "REGISTERED",
                  "WAITING_LIST",
                  "CANCELLED",
                  "ARCHIVED",
                ].map((x) => (
                  <option key={x}>{x}</option>
                ))}
              </select>
            </label>
            <label className="student-form-full-field">
              Adresse
              <textarea
                value={form.address || ""}
                onChange={(e) => set("address", e.target.value)}
              />
            </label>
          </div>
        </section>
        <section className="panel student-form-section">
          <h3>2. Scolarité</h3>
          <div className="student-form-grid">
            <label>
              Année scolaire
              <select
                required
                value={schoolYear}
                onChange={(e) => { setSchoolYear(e.target.value); setLevelName(""); set("class_level_id", ""); }}
              >
                <option value="">Choisir</option>
                {years.map((year) => <option key={year} value={year}>{year}</option>)}
              </select>
            </label>
            <label>
              Niveau
              <select required disabled={!schoolYear} value={levelName} onChange={(e) => { setLevelName(e.target.value); set("class_level_id", ""); }}><option value="">Choisir</option>{levelOptions.map((level) => <option key={level} value={level}>{level}</option>)}</select>
            </label>
            <label>
              Classe
              <select required disabled={!levelName} value={form.class_level_id} onChange={(e) => { const item = levels.find((row) => String(row.id) === e.target.value); set("class_level_id", e.target.value); if (item) { setSchoolYear(item.school_year || ""); setLevelName(item.level_name || item.name || ""); } }}><option value="">Choisir</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
            </label>
          </div>
        </section>
        <section className="panel student-form-section">
          <h3>3. Responsable familial</h3>
          {form.guardians.map((g, i) => (
            <div className="student-form-grid" key={i}>
              <label>
                Nom complet
                <input
                  required={i === 0}
                  value={g.full_name}
                  onChange={(e) => setGuardian(i, "full_name", e.target.value)}
                />
              </label>
              <label>
                Téléphone
                <input
                  required={i === 0}
                  value={g.phone_primary}
                  onChange={(e) =>
                    setGuardian(i, "phone_primary", e.target.value)
                  }
                />
              </label>
              <label>
                Téléphone secondaire
                <input
                  value={g.phone_secondary}
                  onChange={(e) =>
                    setGuardian(i, "phone_secondary", e.target.value)
                  }
                />
              </label>
              <label>
                Email
                <input
                  type="email"
                  value={g.email}
                  onChange={(e) => setGuardian(i, "email", e.target.value)}
                />
              </label>
              <label>
                Relation
                <input
                  value={g.relationship_type}
                  onChange={(e) =>
                    setGuardian(i, "relationship_type", e.target.value)
                  }
                />
              </label>
              {i > 0 && (
                <button
                  type="button"
                  className="secondary-btn"
                  onClick={() =>
                    setForm((x) => ({
                      ...x,
                      guardians: x.guardians.filter((_, n) => n !== i),
                    }))
                  }
                >
                  Retirer
                </button>
              )}
            </div>
          ))}
          {form.guardians.length < 3 && (
            <button
              type="button"
              className="secondary-btn"
              onClick={() =>
                setForm((x) => ({
                  ...x,
                  guardians: [...x.guardians, guardian()],
                }))
              }
            >
              + Ajouter un responsable
            </button>
          )}
        </section>
        <section className="panel student-form-section">
          <h3>4. Mensualité</h3>
          <label><input type="checkbox" checked={!!form.tuition_enabled} onChange={(e) => set("tuition_enabled", e.target.checked)} /> Mensualité scolaire</label>
          {form.tuition_enabled && <div className="student-form-grid"><p>{tariff?.configured ? <><strong>Tarif : {money(tariff.monthly_amount)}</strong><br /><small>{selectedClass?.school_year} · {selectedClass?.level_name || selectedClass?.name}</small></> : <span className="error-text">Tarif non configuré</span>}</p><label>Réduction<select value={form.monthly_discount_type || ""} onChange={(e) => set("monthly_discount_type", e.target.value)}><option value="">Aucune</option><option value="FIXED">Montant</option><option value="PERCENTAGE">Pourcentage</option></select></label><label>Valeur<input type="number" min="0" max={form.monthly_discount_type === "PERCENTAGE" ? 100 : undefined} disabled={!form.monthly_discount_type} value={form.monthly_discount_value || ""} onChange={(e) => set("monthly_discount_value", e.target.value)} /></label><label>Motif<input disabled={!form.monthly_discount_type} value={form.monthly_discount_reason || ""} onChange={(e) => set("monthly_discount_reason", e.target.value)} /></label><p><strong>Après réduction : {money(monthlyLine.f)}</strong></p></div>}
          <p className="muted">Transport et garde sont facultatifs. Aucun service n’est facturé sans sélection explicite.</p>
          <div className="table-actions">
            <button type="button" className="secondary-btn" onClick={() => addOptionalMonthlyCharge("DAYCARE")}>+ Garde</button>
            <button type="button" className="secondary-btn" onClick={() => addOptionalMonthlyCharge("TRANSPORT")}>+ Transport</button>
          </div>
        </section>
        <section className="panel student-form-section">
          <h3>5. Frais et réductions</h3>
          {form.charges.map((c, i) => {
            const x = total(c);
            const selectedCategory = categories.find((item) => String(item.id) === String(c.charge_category_id));
            const recurring = selectedCategory?.periodicity === "MONTHLY";
            return (
              <div className="student-form-grid" key={i}>
                <label>
                  Catégorie
                  <select
                    value={c.charge_category_id}
                    onChange={(e) => {
                      const category = categories.find((item) => String(item.id) === e.target.value);
                      setForm((current) => ({
                        ...current,
                        charges: current.charges.map((item, index) => index === i ? {
                          ...item,
                          charge_category_id: e.target.value,
                          label: item.label || category?.label || "",
                          original_amount: item.original_amount || String(category?.default_amount || ""),
                          billing_month: category?.periodicity === "MONTHLY" ? item.billing_month : "",
                          year_value: category?.periodicity === "MONTHLY" ? item.year_value : "",
                        } : item),
                      }));
                    }}
                  >
                    <option value="">Choisir</option>
                    {categories.map((z) => (
                      <option key={z.id} value={z.id}>
                        {z.label}
                      </option>
                    ))}
                  </select>
                </label>
                {recurring && (
                  <>
                    <label>
                      Mois de début
                      <select
                        value={c.billing_month || ""}
                        onChange={(e) => setForm((current) => ({
                          ...current,
                          charges: current.charges.map((item, index) => index === i ? {
                            ...item,
                            billing_month: e.target.value,
                            year_value: e.target.value ? String(schoolYearStart || "") : "",
                          } : item),
                        }))}
                        required
                      >
                        <option value="">Choisir un mois</option>
                        {SCHOOL_YEAR_MONTH_OPTIONS.map((month) => (
                          <option key={month.value} value={month.value}>{month.value}/{schoolYearStart || "…"}</option>
                        ))}
                      </select>
                    </label>
                    <p className="muted">Une seule période sera créée : {c.billing_month ? `${c.billing_month}/${c.year_value}` : "choisissez le mois de début"}. Les mois suivants ne sont pas générés automatiquement.</p>
                  </>
                )}
                <label>
                  Libellé
                  <input
                    value={c.label || ""}
                    onChange={(e) => setCharge(i, "label", e.target.value)}
                  />
                </label>
                <label>
                  Montant
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={c.original_amount}
                    onChange={(e) =>
                      setCharge(i, "original_amount", e.target.value)
                    }
                  />
                </label>
                <label>
                  Réduction
                  <select
                    value={c.discount_type || ""}
                    onChange={(e) =>
                      setCharge(i, "discount_type", e.target.value)
                    }
                  >
                    <option value="">Aucune</option>
                    <option value="FIXED">Montant</option>
                    <option value="PERCENTAGE">Pourcentage</option>
                  </select>
                </label>
                <label>
                  Valeur
                  <input
                    type="number"
                    min="0"
                    max={c.discount_type === "PERCENTAGE" ? 100 : undefined}
                    disabled={!c.discount_type}
                    value={c.discount_value || ""}
                    onChange={(e) =>
                      setCharge(i, "discount_value", e.target.value)
                    }
                  />
                </label>
                <label>
                  Motif de réduction
                  <input disabled={!c.discount_type} value={c.discount_reason || ""} onChange={(e) => setCharge(i, "discount_reason", e.target.value)} />
                </label>
                <label>
                  Échéance
                  <input
                    type="date"
                    value={c.due_date || ""}
                    onChange={(e) => setCharge(i, "due_date", e.target.value)}
                  />
                </label>
                <p>
                  <strong>Final : {money(x.f)}</strong>
                  {c.id && (
                    <small>
                      {" "}
                      Payé {money(c.paid_amount)} · Reste{" "}
                      {money(c.remaining_amount)} · {c.status}
                    </small>
                  )}
                </p>
                {!c.id && (
                  <button
                    type="button"
                    className="secondary-btn"
                    onClick={() =>
                      setForm((x) => ({
                        ...x,
                        charges: x.charges.filter((_, n) => n !== i),
                      }))
                    }
                  >
                    Supprimer
                  </button>
                )}
              </div>
            );
          })}
          <button
            type="button"
            className="secondary-btn"
            onClick={() =>
              setForm((x) => ({ ...x, charges: [...x.charges, charge()] }))
            }
          >
            + Ajouter un frais
          </button>
          <div className="student-net-fee">
            <strong>Résumé</strong>
            <p>Total initial : {money(summary.a)}</p>
            <p>Réductions : -{money(summary.d)}</p>
            <p>Total à payer : {money(summary.f)}</p>
          </div>
        </section>
        <section className="panel student-form-footer">
          {error && <p className="error-text">{error}</p>}
          <button disabled={saving}>
            {saving ? "Enregistrement…" : "Enregistrer"}
          </button>
        </section>
      </form>
    </div>
  );
}
