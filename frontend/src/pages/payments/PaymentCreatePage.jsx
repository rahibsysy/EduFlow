import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { MONTH_OPTIONS } from "../../config/schoolOptions";
import useI18n from "../../hooks/useI18n";
import { getPaymentMethods } from "../../services/paymentMethodService";
import { createPayment } from "../../services/paymentService";
import { downloadReceiptPdf } from "../../services/receiptService";
import { getStudentById, getStudents } from "../../services/studentService";
import { getAcademicYears } from "../../services/academicYearService";
import { getClassLevels } from "../../services/classLevelService";

const currentDate = new Date();
const initialForm = {
  student_id: "",
  month_label: String(currentDate.getMonth() + 1).padStart(2, "0"),
  year_value: String(currentDate.getFullYear()),
  amount_paid: "",
  payment_date: currentDate.toISOString().slice(0, 10),
  payment_method_id: "",
};

const formatMoney = (value, language) => new Intl.NumberFormat(
  language === "ar" ? "ar-MA" : "fr-MA",
  { style: "currency", currency: "MAD" }
).format(Number(value || 0));

const statusLabel = (value, t) => {
  if (value === "PAID") return t("statuses.paid");
  if (value === "PARTIAL") return t("statuses.partial");
  if (value === "UNPAID") return t("statuses.unpaid");
  return value || "-";
};

const paymentMethodLabel = (method, t) => {
  const code = String(method?.code || "").toUpperCase();
  const keys = { CASH: "methodCash", CARD: "methodCard", BANK_TRANSFER: "methodBankTransfer", CHECK: "methodCheck", MOBILE: "methodMobile" };
  return keys[code] ? t(`payments.${keys[code]}`) : method?.label || code || "-";
};

function LegacyPaymentCreatePage() {
  const { language, t } = useI18n();
  const [searchParams] = useSearchParams();
  const [form, setForm] = useState(initialForm);
  const [methods, setMethods] = useState([]);
  const [students, setStudents] = useState([]);
  const [studentSearch, setStudentSearch] = useState("");
  const [studentSearchApplied, setStudentSearchApplied] = useState(false);
  const [searching, setSearching] = useState(false);
  const [saving, setSaving] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [createdPayment, setCreatedPayment] = useState(null);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    getPaymentMethods()
      .then((data) => {
        const safeMethods = Array.isArray(data) ? data : [];
        setMethods(safeMethods);
        if (safeMethods.length) {
          setForm((previous) => ({ ...previous, payment_method_id: String(safeMethods[0].id) }));
        }
      })
      .catch(() => setError(t("payments.loadError")));
  }, [t]);

  useEffect(() => {
    const studentId = Number(searchParams.get("student_id") || 0);
    if (studentId <= 0) return;

    setSearching(true);
    getStudentById(studentId)
      .then((data) => {
        const student = data?.student;
        if (!student) return;
        setStudents([student]);
        setStudentSearch(`${student.first_name} ${student.last_name}`.trim());
        setStudentSearchApplied(true);
        setForm((previous) => ({ ...previous, student_id: String(student.id) }));
      })
      .catch(() => setError(t("payments.studentSearchError")))
      .finally(() => setSearching(false));
  }, [searchParams, t]);

  const searchStudents = async (event) => {
    event.preventDefault();
    const search = studentSearch.trim();
    setError("");
    setMessage("");

    if (!search) {
      setStudents([]);
      setStudentSearchApplied(false);
      setError(t("payments.studentSearchRequired"));
      return;
    }

    setSearching(true);
    try {
      const data = await getStudents({ search });
      setStudents(Array.isArray(data) ? data : []);
      setStudentSearchApplied(true);
    } catch {
      setStudents([]);
      setStudentSearchApplied(false);
      setError(t("payments.studentSearchError"));
    } finally {
      setSearching(false);
    }
  };

  const submitPayment = async (event) => {
    event.preventDefault();
    setError("");
    setMessage("");
    setCreatedPayment(null);
    setSaving(true);

    try {
      const result = await createPayment({
        student_id: Number(form.student_id),
        month_label: form.month_label,
        year_value: Number(form.year_value),
        amount_paid: Number(form.amount_paid),
        payment_date: form.payment_date,
        payment_method_id: Number(form.payment_method_id),
      });
      setCreatedPayment(result);
      setMessage(t("payments.success", {
        status: statusLabel(result.monthly_fee_status, t),
        remaining: formatMoney(result.monthly_fee_remaining_amount, language),
      }));
      setForm((previous) => ({ ...initialForm, payment_method_id: previous.payment_method_id }));
      setStudents([]);
      setStudentSearch("");
      setStudentSearchApplied(false);
    } catch {
      setError(t("payments.saveError"));
    } finally {
      setSaving(false);
    }
  };

  const downloadCreatedReceipt = async () => {
    if (!createdPayment?.id) return;
    setDownloading(true);
    setError("");
    try {
      await downloadReceiptPdf(createdPayment.id, language);
    } catch {
      setError(t("payments.downloadError"));
    } finally {
      setDownloading(false);
    }
  };

  return (
    <div className="admin-grid">
      <section className="panel hero-modern">
        <p className="brand-kicker">{t("navigation.finance")}</p>
        <h2>{t("payments.newPayment")}</h2>
        <p className="muted">{t("payments.newPaymentDescription")}</p>
      </section>

      <section className="panel">
        <form className="filters-grid" onSubmit={searchStudents}>
          <input
            placeholder={t("payments.searchStudent")}
            value={studentSearch}
            onChange={(event) => setStudentSearch(event.target.value)}
          />
          <button type="submit" disabled={searching}>
            {searching ? t("common.searching") : t("payments.searchStudentAction")}
          </button>
        </form>

        <form className="form-grid payment-create-form" onSubmit={submitPayment}>
          <label>
            <span>{t("common.student")}</span>
            <select
              value={form.student_id}
              onChange={(event) => setForm({ ...form, student_id: event.target.value })}
              required
            >
              <option value="">
                {studentSearchApplied ? t("payments.selectStudent") : t("payments.searchStudentFirst")}
              </option>
              {students.map((student) => (
                <option key={student.id} value={student.id}>
                  {student.first_name} {student.last_name}
                </option>
              ))}
            </select>
          </label>
          <label>
            <span>{t("payments.month")}</span>
            <select value={form.month_label} onChange={(event) => setForm({ ...form, month_label: event.target.value })} required>
              {MONTH_OPTIONS.map((month) => (
                <option key={month.value} value={month.value}>{t(`months.${month.value}`)}</option>
              ))}
            </select>
          </label>
          <label>
            <span>{t("common.year")}</span>
            <input type="number" min="2000" max="2100" value={form.year_value} onChange={(event) => setForm({ ...form, year_value: event.target.value })} required />
          </label>
          <label>
            <span>{t("payments.paidAmount")}</span>
            <input type="number" min="0.01" step="0.01" value={form.amount_paid} onChange={(event) => setForm({ ...form, amount_paid: event.target.value })} required />
          </label>
          <label>
            <span>{t("common.date")}</span>
            <input type="date" value={form.payment_date} onChange={(event) => setForm({ ...form, payment_date: event.target.value })} required />
          </label>
          <label>
            <span>{t("payments.paymentMethod")}</span>
            <select value={form.payment_method_id} onChange={(event) => setForm({ ...form, payment_method_id: event.target.value })} required>
              <option value="">{t("payments.paymentMethod")}</option>
              {methods.map((method) => <option key={method.id} value={method.id}>{paymentMethodLabel(method, t)}</option>)}
            </select>
          </label>
          <div className="form-actions full-field">
            <button type="submit" disabled={saving}>{saving ? t("payments.registering") : t("common.save")}</button>
          </div>
        </form>

        {studentSearchApplied && students.length === 0 && <p className="muted">{t("payments.noStudent")}</p>}
        {message && <p className="success-text">{message}</p>}
        {error && <p className="error-text">{error}</p>}
      </section>

      {createdPayment?.id && (
        <section className="panel payment-created-actions">
          <div>
            <h3>{t("payments.createdActions")}</h3>
            <p className="muted">{t("payments.receiptNumber")} : REC-{String(createdPayment.id).padStart(6, "0")}</p>
          </div>
          <div className="table-actions">
            <Link className="secondary-btn button-link" to={`/finances/payments/${createdPayment.id}`}>{t("payments.viewDetails")}</Link>
            <button type="button" onClick={downloadCreatedReceipt} disabled={downloading}>{t("payments.downloadReceipt")}</button>
            <Link className="secondary-btn button-link" to="/finances/payments/history">{t("payments.backToHistory")}</Link>
          </div>
        </section>
      )}
    </div>
  );
}

const allocationKey = (item) => `${item.source_type}:${item.source_id}`;
const obligationPeriod = (item) => item.period || (item.billing_month && item.year_value ? `${String(item.billing_month).padStart(2, "0")}/${item.year_value}` : "—");

/**
 * New collection flow.  The legacy view above is intentionally retained in
 * source during this transition; this component only submits contracts that
 * the payment service already supports.
 */
export default function PaymentCreatePage() {
  const { language } = useI18n();
  const [years, setYears] = useState([]);
  const [classes, setClasses] = useState([]);
  const [methods, setMethods] = useState([]);
  const [yearId, setYearId] = useState("");
  const [level, setLevel] = useState("");
  const [classId, setClassId] = useState("");
  const [students, setStudents] = useState([]);
  const [studentId, setStudentId] = useState("");
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [amounts, setAmounts] = useState({});
  const [methodId, setMethodId] = useState("");
  const [paymentDate, setPaymentDate] = useState(currentDate.toISOString().slice(0, 10));
  const [paymentKey, setPaymentKey] = useState("");
  const [confirming, setConfirming] = useState(false);
  const [saving, setSaving] = useState(false);
  const [created, setCreated] = useState(null);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [loadingSources, setLoadingSources] = useState(true);
  const [sourceError, setSourceError] = useState("");

  useEffect(() => {
    let active = true;
    Promise.allSettled([getAcademicYears(), getClassLevels(), getPaymentMethods()])
      .then(([yearResult, classResult, methodResult]) => {
        if (!active) return;
        const failures = [];
        if (yearResult.status === "fulfilled") setYears(Array.isArray(yearResult.value) ? yearResult.value : []); else failures.push("années scolaires");
        if (classResult.status === "fulfilled") setClasses(Array.isArray(classResult.value) ? classResult.value : []); else failures.push("classes");
        if (methodResult.status === "fulfilled") {
          const safe = Array.isArray(methodResult.value) ? methodResult.value : [];
          setMethods(safe);
          if (safe[0]) setMethodId(String(safe[0].id));
        } else failures.push("modes de paiement");
        if (failures.length) setSourceError(`Impossible de charger : ${failures.join(", ")}. Vérifiez votre session et l’API.`);
      })
      .finally(() => { if (active) setLoadingSources(false); });
    return () => { active = false; };
  }, []);

  const year = useMemo(() => years.find((item) => String(item.id) === yearId), [years, yearId]);
  const levels = useMemo(() => [...new Set(classes.filter((item) => !year || item.school_year === year.name || item.school_year === year.label).map((item) => item.level_name || item.name).filter(Boolean))].sort(), [classes, year]);
  const classOptions = useMemo(() => classes.filter((item) => (!year || item.school_year === year.name || item.school_year === year.label) && (!level || (item.level_name || item.name) === level)), [classes, year, level]);
  const matchingStudents = useMemo(() => {
    const needle = query.trim().toLocaleLowerCase();
    return students.filter((student) => !needle || `${student.first_name} ${student.last_name} ${student.massar_code || student.cne || ""}`.toLocaleLowerCase().includes(needle));
  }, [students, query]);
  const selected = useMemo(() => items.filter((item) => Number(amounts[allocationKey(item)] || 0) > 0), [items, amounts]);
  const total = useMemo(() => selected.reduce((sum, item) => sum + Number(amounts[allocationKey(item)] || 0), 0), [selected, amounts]);
  const resetStudent = () => { setStudentId(""); setStudents([]); setItems([]); setAmounts({}); setConfirming(false); };
  const selectYear = (value) => { setYearId(value); setLevel(""); setClassId(""); resetStudent(); };
  const selectLevel = (value) => { setLevel(value); setClassId(""); resetStudent(); };
  const selectClass = async (value) => {
    setClassId(value); resetStudent();
    if (!value) return;
    try { setStudents(await getStudents({ class_level_id: Number(value) })); }
    catch { setError("Impossible de charger les élèves de cette classe."); }
  };
  const selectStudent = async (value) => {
    setStudentId(value); setItems([]); setAmounts({}); setConfirming(false); setCreated(null);
    if (!value) return;
    try {
      const data = await getStudentById(value);
      setItems((data?.financial_items || []).filter((item) => Number(item.remaining_amount || 0) > 0));
    } catch { setError("Impossible de charger les obligations financières de cet élève."); }
  };
  const newPaymentKey = () => globalThis.crypto?.randomUUID?.() || `payment-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  const setAllocation = (item, value) => {
    const amount = value === "" ? "" : Math.min(Math.max(0, Number(value)), Number(item.remaining_amount));
    setAmounts((current) => ({ ...current, [allocationKey(item)]: amount }));
    setConfirming(false);
    setPaymentKey("");
  };
  const prepare = (event) => {
    event.preventDefault(); setError(""); setMessage("");
    if (!studentId || !methodId || !selected.length || total <= 0) { setError("Sélectionnez au moins une obligation et un montant positif."); return; }
    setPaymentKey(newPaymentKey());
    setConfirming(true);
  };
  const save = async () => {
    setSaving(true); setError("");
    try {
      const common = { student_id: Number(studentId), amount_paid: Number(total.toFixed(2)), payment_date: paymentDate, payment_method_id: Number(methodId), idempotency_key: paymentKey || newPaymentKey() };
      const result = await createPayment({ ...common, allocations: selected.map((item) => ({
        ...(item.source_type === "LEGACY_MONTHLY_FEE" ? { monthly_fee_id: Number(item.source_id) } : { student_financial_item_id: Number(item.source_id) }),
        amount: Number(Number(amounts[allocationKey(item)]).toFixed(2)),
      })) });
      setCreated(result); setMessage("Paiement enregistré. Le reçu est disponible ci-dessous."); setConfirming(false);
      await selectStudent(studentId);
    } catch (requestError) { setError(requestError?.response?.data?.message || "Paiement impossible. Aucun reçu n’a été créé."); }
    finally { setSaving(false); }
  };
  const totalRemaining = items.reduce((sum, item) => sum + Number(item.remaining_amount || 0), 0);

  return <div className="admin-grid">
    <section className="panel hero-modern"><p className="brand-kicker">Finance</p><h2>Nouveau paiement</h2><p className="muted">Classe, élève, frais réellement dus, puis confirmation.</p></section>
    <section className="panel">{loadingSources && <p className="muted">Chargement des années, classes et modes de paiement…</p>}{sourceError && <p className="error-text">{sourceError}</p>}{!loadingSources && !sourceError && years.length === 0 && <p className="error-text">Aucune année scolaire accessible. Créez une année scolaire ou vérifiez vos autorisations.</p>}<div className="form-grid">
      <label>Année scolaire<select value={yearId} disabled={loadingSources || years.length === 0} onChange={(event) => selectYear(event.target.value)}><option value="">{loadingSources ? "Chargement…" : "Choisir"}</option>{years.map((item) => <option key={item.id} value={item.id}>{item.label || item.name}</option>)}</select></label>
      <label>Niveau<select value={level} disabled={!yearId} onChange={(event) => selectLevel(event.target.value)}><option value="">Choisir</option>{levels.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
      <label>Classe<select value={classId} disabled={!level} onChange={(event) => selectClass(event.target.value)}><option value="">Choisir</option>{classOptions.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label>Recherche nom ou CNE<input value={query} disabled={!classId} onChange={(event) => setQuery(event.target.value)} placeholder={classId ? "Nom ou CNE" : "Sélectionnez d’abord une classe"} /></label>
      <label>Élève<select value={studentId} disabled={!classId} onChange={(event) => selectStudent(event.target.value)}><option value="">Choisir</option>{matchingStudents.map((item) => <option key={item.id} value={item.id}>{item.last_name} {item.first_name}{item.massar_code || item.cne ? ` · ${item.massar_code || item.cne}` : ""}</option>)}</select></label>
    </div></section>
    {studentId && <section className="panel"><h3>Frais dus</h3>{!items.length ? <p className="muted">Aucune obligation impayée pour cet élève.</p> : <form onSubmit={prepare}><div className="table-wrap"><table><thead><tr><th>Catégorie</th><th>Période</th><th>Initial</th><th>Réduction</th><th>Net</th><th>Déjà payé</th><th>Reste</th><th>À affecter</th></tr></thead><tbody>{items.map((item) => <tr key={allocationKey(item)}><td>{item.category_name || item.label}<br /><small>{item.label}</small></td><td>{obligationPeriod(item)}</td><td>{formatMoney(item.initial_amount, language)}</td><td>{formatMoney(item.discount_amount, language)}</td><td>{formatMoney(item.final_amount, language)}</td><td>{formatMoney(item.paid_amount, language)}</td><td>{formatMoney(item.remaining_amount, language)}</td><td><input type="number" min="0" max={item.remaining_amount} step="0.01" value={amounts[allocationKey(item)] || ""} onChange={(event) => setAllocation(item, event.target.value)} /></td></tr>)}</tbody></table></div><div className="form-grid payment-create-form"><label>Date du paiement<input type="date" value={paymentDate} onChange={(event) => setPaymentDate(event.target.value)} required /></label><label>Mode de paiement<select value={methodId} onChange={(event) => setMethodId(event.target.value)} required><option value="">Choisir</option>{methods.map((method) => <option key={method.id} value={method.id}>{method.label || method.code}</option>)}</select></label><p><strong>Total à encaisser : {formatMoney(total, language)}</strong><br /><small>Solde après paiement : {formatMoney(totalRemaining - total, language)}</small></p></div><div className="form-actions"><button type="submit" disabled={saving}>Préparer le paiement</button></div></form>}</section>}
    {confirming && <section className="panel"><h3>Confirmer le paiement</h3><p>{formatMoney(total, language)} sera enregistré pour {selected.length} obligation{selected.length > 1 ? "s" : ""}.</p><div className="form-actions"><button type="button" className="secondary-btn" onClick={() => setConfirming(false)} disabled={saving}>Annuler</button><button type="button" onClick={save} disabled={saving}>{saving ? "Enregistrement…" : "Confirmer le paiement"}</button></div></section>}
    {created?.id && <section className="panel payment-created-actions"><div><h3>Paiement enregistré</h3><p className="muted">Reçu {created.receipt_number || `REC-${String(created.id).padStart(6, "0")}`}</p></div><div className="table-actions"><Link className="secondary-btn button-link" to={`/finances/payments/${created.id}`}>Voir le détail</Link><button type="button" onClick={() => downloadReceiptPdf(created.id, language)}>Télécharger le reçu PDF</button><Link className="secondary-btn button-link" to="/finances/payments/history">Historique</Link></div></section>}
    {message && <p className="success-text">{message}</p>}{error && <p className="error-text">{error}</p>}
  </div>;
}
