import { useEffect, useMemo, useState } from "react";
import { MONTH_OPTIONS, normalizeSearch } from "../config/schoolOptions";
import { getPaymentMethods } from "../services/paymentMethodService";
import { createPayment, getPayments } from "../services/paymentService";
import { getStudents } from "../services/studentService";

const formatMoney = (value) =>
  new Intl.NumberFormat("fr-MA", { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

const currentDate = new Date();

const emptyForm = {
  student_id: "",
  month_label: String(currentDate.getMonth() + 1).padStart(2, "0"),
  year_value: String(currentDate.getFullYear()),
  amount_paid: "",
  payment_date: currentDate.toISOString().slice(0, 10),
  payment_method_id: "",
};

const statusLabel = (value) => {
  if (value === "PAID") return "Paye";
  if (value === "PARTIAL") return "Partiel";
  if (value === "UNPAID") return "Impaye";
  return value || "-";
};

export default function PaymentsPage() {
  const [payments, setPayments] = useState([]);
  const [students, setStudents] = useState([]);
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [studentSearch, setStudentSearch] = useState("");
  const [studentSearchApplied, setStudentSearchApplied] = useState(false);
  const [filters, setFilters] = useState({
    last_name: "",
    first_name: "",
    class_level: "",
    class_name: "",
    month_label: "",
    status: "",
  });
  const [loading, setLoading] = useState(false);
  const [studentLoading, setStudentLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const loadData = async () => {
    const [paymentsData, methodsData] = await Promise.all([
      getPayments(),
      getPaymentMethods(),
    ]);

    const safePayments = Array.isArray(paymentsData) ? paymentsData : [];
    const safeMethods = Array.isArray(methodsData) ? methodsData : [];

    setPayments(safePayments);
    setPaymentMethods(safeMethods);

    if (!form.payment_method_id && safeMethods.length) {
      setForm((prev) => ({ ...prev, payment_method_id: String(safeMethods[0].id) }));
    }
  };

  useEffect(() => {
    loadData().catch(() => setError("Impossible de charger les paiements."));
  }, []);

  const totalPaid = useMemo(
    () => payments.reduce((sum, item) => sum + Number(item.amount_paid || 0), 0),
    [payments]
  );

  const levelOptions = useMemo(() => {
    const unique = new Set(
      payments
        .map((payment) => payment.class_level_name)
        .filter(Boolean)
    );
    return Array.from(unique).sort((a, b) => a.localeCompare(b));
  }, [payments]);

  const filteredPayments = useMemo(() => {
    const matchesText = (value, search) =>
      !search || normalizeSearch(value).includes(normalizeSearch(search));

    return payments.filter((payment) => (
      matchesText(payment.last_name, filters.last_name) &&
      matchesText(payment.first_name, filters.first_name) &&
      matchesText(payment.class_level_name, filters.class_level) &&
      matchesText(payment.class_name, filters.class_name) &&
      (!filters.month_label || String(payment.month_label).padStart(2, "0") === filters.month_label) &&
      (!filters.status || payment.payment_status === filters.status)
    ));
  }, [filters, payments]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setMessage("");
    setLoading(true);

    try {
      const payload = {
        student_id: Number(form.student_id),
        month_label: form.month_label,
        year_value: Number(form.year_value),
        amount_paid: Number(form.amount_paid),
        payment_date: form.payment_date,
        payment_method_id: Number(form.payment_method_id),
      };

      const result = await createPayment(payload);
      setMessage(
        `Paiement enregistre. Statut mensualite: ${statusLabel(result.monthly_fee_status)}, reste: ${formatMoney(
          result.monthly_fee_remaining_amount
        )}`
      );
      setForm((prev) => ({
        ...emptyForm,
        payment_method_id: prev.payment_method_id,
      }));
      await loadData();
    } catch (err) {
      setError(err?.response?.data?.message || "Echec d'enregistrement du paiement.");
    } finally {
      setLoading(false);
    }
  };

  const searchStudents = async (e) => {
    e.preventDefault();
    const search = studentSearch.trim();
    setError("");
    setMessage("");

    if (!search) {
      setStudents([]);
      setStudentSearchApplied(false);
      setError("Saisissez un nom ou un prenom pour chercher un eleve.");
      return;
    }

    setStudentLoading(true);
    try {
      const data = await getStudents({ search });
      setStudents(Array.isArray(data) ? data : []);
      setStudentSearchApplied(true);
    } catch (err) {
      setStudents([]);
      setStudentSearchApplied(false);
      setError(err?.response?.data?.message || "Impossible de rechercher les eleves.");
    } finally {
      setStudentLoading(false);
    }
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>Paiements</h2>
        <p className="muted">Saisie et tracabilite des encaissements mensuels.</p>
      </section>

      <section className="kpi-grid two-col">
        <article className="panel kpi">
          <p className="kpi-label">Operations enregistrees</p>
          <h2>{payments.length}</h2>
          <p className="muted">Nombre total de paiements saisis</p>
        </article>
        <article className="panel kpi">
          <p className="kpi-label">Total encaisse</p>
          <h2>{formatMoney(totalPaid)}</h2>
          <p className="muted">Somme des transactions en base</p>
        </article>
      </section>

      <section className="panel">
        <h3>Enregistrer un paiement</h3>
        <form className="filters-grid" onSubmit={searchStudents}>
          <input
            placeholder="Rechercher un eleve par nom ou prenom"
            value={studentSearch}
            onChange={(e) => setStudentSearch(e.target.value)}
          />
          <button type="submit" disabled={studentLoading}>
            {studentLoading ? "Recherche..." : "Rechercher l'eleve"}
          </button>
        </form>
        <form className="form-grid" onSubmit={handleSubmit}>
          <select
            value={form.student_id}
            onChange={(e) => setForm({ ...form, student_id: e.target.value })}
            required
          >
            <option value="">
              {studentSearchApplied ? "Selectionner un eleve" : "Cherchez d'abord un eleve"}
            </option>
            {students.map((student) => (
              <option key={student.id} value={student.id}>
                {student.first_name} {student.last_name}
              </option>
            ))}
          </select>
          {studentSearchApplied && students.length === 0 && (
            <p className="muted">Aucun eleve trouve pour cette recherche.</p>
          )}
          <select
            value={form.month_label}
            onChange={(e) => setForm({ ...form, month_label: e.target.value })}
            required
          >
            {MONTH_OPTIONS.map((month) => (
              <option key={month.value} value={month.value}>{month.label}</option>
            ))}
          </select>
          <input
            type="number"
            min="2000"
            max="2100"
            placeholder="Annee"
            value={form.year_value}
            onChange={(e) => setForm({ ...form, year_value: e.target.value })}
            required
          />
          <input
            type="number"
            min="0"
            step="0.01"
            placeholder="Montant paye"
            value={form.amount_paid}
            onChange={(e) => setForm({ ...form, amount_paid: e.target.value })}
            required
          />
          <input
            type="date"
            value={form.payment_date}
            onChange={(e) => setForm({ ...form, payment_date: e.target.value })}
            required
          />
          <select
            value={form.payment_method_id}
            onChange={(e) => setForm({ ...form, payment_method_id: e.target.value })}
            required
          >
            <option value="">Mode de paiement</option>
            {paymentMethods.map((method) => (
              <option key={method.id} value={method.id}>
                {method.label}
              </option>
            ))}
          </select>
          <button type="submit" disabled={loading}>
            {loading ? "Enregistrement..." : "Enregistrer"}
          </button>
        </form>
        {message && <p className="muted">{message}</p>}
        {error && <p className="error-text">{error}</p>}
      </section>

      <section className="panel">
        <h3>Filtres</h3>
        <div className="filters-grid">
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
            {levelOptions.map((level) => (
              <option key={level} value={level}>{level}</option>
            ))}
          </select>
          <input
            placeholder="Filtrer par classe"
            value={filters.class_name}
            onChange={(e) => setFilters({ ...filters, class_name: e.target.value })}
          />
          <select
            value={filters.month_label}
            onChange={(e) => setFilters({ ...filters, month_label: e.target.value })}
          >
            <option value="">Tous les mois</option>
            {MONTH_OPTIONS.map((month) => (
              <option key={month.value} value={month.value}>{month.label}</option>
            ))}
          </select>
          <select
            value={filters.status}
            onChange={(e) => setFilters({ ...filters, status: e.target.value })}
          >
            <option value="">Tous les statuts</option>
            <option value="PAID">Paye</option>
            <option value="PARTIAL">Partiel</option>
            <option value="UNPAID">Impaye</option>
          </select>
          <button
            type="button"
            className="secondary-btn"
            onClick={() => setFilters({ last_name: "", first_name: "", class_level: "", class_name: "", month_label: "", status: "" })}
          >
            Réinitialiser les filtres
          </button>
        </div>
      </section>

      <section className="panel">
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Eleve</th>
                <th>Niveau</th>
                <th>Classe</th>
                <th>Mois</th>
                <th>Annee</th>
                <th>Montant</th>
                <th>Date</th>
                <th>Mode</th>
                <th>Statut</th>
              </tr>
            </thead>
            <tbody>
              {filteredPayments.map((payment) => (
                <tr key={payment.id}>
                  <td>{payment.id}</td>
                  <td>
                    {payment.first_name} {payment.last_name}
                  </td>
                  <td>{payment.class_level_name || "-"}</td>
                  <td>{payment.class_name || "-"}</td>
                  <td>{payment.month_label}</td>
                  <td>{payment.year_value}</td>
                  <td>{formatMoney(payment.amount_paid)}</td>
                  <td>{payment.payment_date}</td>
                  <td>{payment.payment_method_label || payment.payment_method}</td>
                  <td>{statusLabel(payment.payment_status)}</td>
                </tr>
              ))}
              {filteredPayments.length === 0 && (
                <tr>
                  <td colSpan="10" className="table-empty">
                    Aucun paiement trouve.
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
