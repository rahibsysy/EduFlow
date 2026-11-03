import { useEffect, useMemo, useState } from "react";
import { MONTH_OPTIONS, normalizeSearch } from "../config/schoolOptions";
import { getPaymentMethods } from "../services/paymentMethodService";
import { createPayment, getPayments } from "../services/paymentService";
import { getStudents } from "../services/studentService";
import useI18n from "../hooks/useI18n";

const formatMoney = (value, language) =>
  new Intl.NumberFormat(language === "ar" ? "ar-MA" : "fr-MA", { style: "currency", currency: "MAD" }).format(
    Number(value || 0)
  );

const formatDate = (value, language) =>
  value ? new Intl.DateTimeFormat(language === "ar" ? "ar-MA" : "fr-MA").format(new Date(value)) : "-";

const currentDate = new Date();

const emptyForm = {
  student_id: "",
  month_label: String(currentDate.getMonth() + 1).padStart(2, "0"),
  year_value: String(currentDate.getFullYear()),
  amount_paid: "",
  payment_date: currentDate.toISOString().slice(0, 10),
  payment_method_id: "",
};

const statusLabel = (value, t) => {
  if (value === "CANCELLED") return "Annulé";
  if (value === "PAID") return t("statuses.paid");
  if (value === "PARTIAL") return t("statuses.partial");
  if (value === "UNPAID") return t("statuses.unpaid");
  return value || "-";
};

export default function PaymentsPage() {
  const { language, t } = useI18n();
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
    loadData().catch(() => setError(t("payments.loadError")));
  }, []);

  const totalPaid = useMemo(
    () => payments.filter((item) => item.payment_status !== "CANCELLED").reduce((sum, item) => sum + Number(item.amount_paid || 0), 0),
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
      (!filters.status || payment.monthly_fee_status === filters.status || payment.payment_status === filters.status)
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
        t("payments.success", {
          status: statusLabel(result.monthly_fee_status, t),
          remaining: formatMoney(result.monthly_fee_remaining_amount, language),
        })
      );
      setForm((prev) => ({
        ...emptyForm,
        payment_method_id: prev.payment_method_id,
      }));
      await loadData();
    } catch (err) {
      setError(t("payments.saveError"));
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
      setError(t("payments.studentSearchRequired"));
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
      setError(t("payments.studentSearchError"));
    } finally {
      setStudentLoading(false);
    }
  };

  return (
    <div className="admin-grid">
      <section className="panel">
        <h2>{t("payments.title")}</h2>
        <p className="muted">{t("payments.description")}</p>
      </section>

      <section className="kpi-grid two-col">
        <article className="panel kpi">
          <p className="kpi-label">{t("payments.recordedOperations")}</p>
          <h2>{payments.length}</h2>
          <p className="muted">{t("payments.recordedOperationsHelp")}</p>
        </article>
        <article className="panel kpi">
          <p className="kpi-label">{t("payments.totalCollected")}</p>
          <h2>{formatMoney(totalPaid, language)}</h2>
          <p className="muted">{t("payments.totalCollectedHelp")}</p>
        </article>
      </section>

      <section className="panel">
        <h3>{t("payments.register")}</h3>
        <form className="filters-grid" onSubmit={searchStudents}>
          <input
            placeholder={t("payments.searchStudent")}
            value={studentSearch}
            onChange={(e) => setStudentSearch(e.target.value)}
          />
          <button type="submit" disabled={studentLoading}>
            {studentLoading ? t("common.searching") : t("payments.searchStudentAction")}
          </button>
        </form>
        <form className="form-grid" onSubmit={handleSubmit}>
          <select
            value={form.student_id}
            onChange={(e) => setForm({ ...form, student_id: e.target.value })}
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
          {studentSearchApplied && students.length === 0 && (
            <p className="muted">{t("payments.noStudent")}</p>
          )}
          <select
            value={form.month_label}
            onChange={(e) => setForm({ ...form, month_label: e.target.value })}
            required
          >
            {MONTH_OPTIONS.map((month) => (
              <option key={month.value} value={month.value}>{t(`months.${month.value}`)}</option>
            ))}
          </select>
          <input
            type="number"
            min="2000"
            max="2100"
            placeholder={t("common.year")}
            value={form.year_value}
            onChange={(e) => setForm({ ...form, year_value: e.target.value })}
            required
          />
          <input
            type="number"
            min="0"
            step="0.01"
            placeholder={t("payments.paidAmount")}
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
            <option value="">{t("payments.paymentMethod")}</option>
            {paymentMethods.map((method) => (
              <option key={method.id} value={method.id}>
                {method.label}
              </option>
            ))}
          </select>
          <button type="submit" disabled={loading}>
            {loading ? t("payments.registering") : t("common.save")}
          </button>
        </form>
        {message && <p className="muted">{message}</p>}
        {error && <p className="error-text">{error}</p>}
      </section>

      <section className="panel">
        <h3>{t("monthlyFees.filters")}</h3>
        <div className="filters-grid">
          <input
            placeholder={t("payments.filterLastName")}
            value={filters.last_name}
            onChange={(e) => setFilters({ ...filters, last_name: e.target.value })}
          />
          <input
            placeholder={t("payments.filterFirstName")}
            value={filters.first_name}
            onChange={(e) => setFilters({ ...filters, first_name: e.target.value })}
          />
          <select
            value={filters.class_level}
            onChange={(e) => setFilters({ ...filters, class_level: e.target.value })}
          >
            <option value="">{t("payments.allLevels")}</option>
            {levelOptions.map((level) => (
              <option key={level} value={level}>{level}</option>
            ))}
          </select>
          <input
            placeholder={t("payments.filterClass")}
            value={filters.class_name}
            onChange={(e) => setFilters({ ...filters, class_name: e.target.value })}
          />
          <select
            value={filters.month_label}
            onChange={(e) => setFilters({ ...filters, month_label: e.target.value })}
          >
            <option value="">{t("payments.allMonths")}</option>
            {MONTH_OPTIONS.map((month) => (
              <option key={month.value} value={month.value}>{t(`months.${month.value}`)}</option>
            ))}
          </select>
          <select
            value={filters.status}
            onChange={(e) => setFilters({ ...filters, status: e.target.value })}
          >
            <option value="">{t("payments.allStatuses")}</option>
            <option value="PAID">{t("statuses.paid")}</option>
            <option value="PARTIAL">{t("statuses.partial")}</option>
            <option value="UNPAID">{t("statuses.unpaid")}</option>
          </select>
          <button
            type="button"
            className="secondary-btn"
            onClick={() => setFilters({ last_name: "", first_name: "", class_level: "", class_name: "", month_label: "", status: "" })}
          >
            {t("common.resetFilters")}
          </button>
        </div>
      </section>

      <section className="panel">
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{t("common.id")}</th>
                <th>{t("common.student")}</th>
                <th>{t("common.level")}</th>
                <th>{t("common.class")}</th>
                <th>{t("payments.month")}</th>
                <th>{t("common.year")}</th>
                <th>{t("common.amount")}</th>
                <th>{t("common.date")}</th>
                <th>{t("payments.method")}</th>
                <th>{t("common.status")}</th>
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
                  <td>{t(`months.${String(payment.month_label).padStart(2, "0")}`)}</td>
                  <td>{payment.year_value}</td>
                  <td>{formatMoney(payment.amount_paid, language)}</td>
                  <td>{formatDate(payment.payment_date, language)}</td>
                  <td>{payment.payment_method_label || payment.payment_method}</td>
                  <td>{statusLabel(payment.payment_status, t)}</td>
                </tr>
              ))}
              {filteredPayments.length === 0 && (
                <tr>
                  <td colSpan="10" className="table-empty">
                    {t("payments.empty")}
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
