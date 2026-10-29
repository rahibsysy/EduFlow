import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { completeInitialSetup } from "../services/setupService";

const initialForm = {
  school_name: "",
  school_code: "",
  first_name: "",
  last_name: "",
  email: "",
  password: "",
  password_confirmation: "",
  provisioning_secret: "",
};

export default function SetupPage() {
  const [form, setForm] = useState(initialForm);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const navigate = useNavigate();

  const submit = async (event) => {
    event.preventDefault();
    setError("");
    if (form.password !== form.password_confirmation) {
      setError("Les mots de passe ne correspondent pas.");
      return;
    }

    setLoading(true);
    try {
      await completeInitialSetup({
        school_name: form.school_name,
        school_code: form.school_code,
        first_name: form.first_name,
        last_name: form.last_name,
        email: form.email,
        password: form.password,
        password_confirmation: form.password_confirmation,
      }, form.provisioning_secret);
      setForm(initialForm);
      navigate("/login", { replace: true });
    } catch (requestError) {
      const status = requestError?.response?.status;
      setError(requestError?.response?.data?.message || "Installation impossible. Réessayez.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <section className="login-screen">
      <div className="login-card setup-card">
        <h1>Installation initiale</h1>
        <p className="muted login-subtitle">Créez votre première école et le compte super-administrateur.</p>
        <p className="muted">Cette opération requiert une autorisation temporaire fournie par l’administrateur système.</p>
        <form className="form-grid" onSubmit={submit}>
            <label className="field-label" htmlFor="setup-provisioning-secret">Secret de provisioning</label>
            <input id="setup-provisioning-secret" type="password" value={form.provisioning_secret} onChange={(e) => setForm({ ...form, provisioning_secret: e.target.value })} required autoComplete="off" />
            <label className="field-label" htmlFor="setup-school-name">Nom de l’école</label>
            <input id="setup-school-name" value={form.school_name} onChange={(e) => setForm({ ...form, school_name: e.target.value })} required maxLength="150" autoComplete="organization" />
            <label className="field-label" htmlFor="setup-school-code">Code école</label>
            <input id="setup-school-code" value={form.school_code} onChange={(e) => setForm({ ...form, school_code: e.target.value.toUpperCase() })} required maxLength="50" pattern="[A-Z0-9_-]{2,50}" title="2 à 50 lettres majuscules, chiffres, tirets ou underscores" />
            <label className="field-label" htmlFor="setup-first-name">Prénom</label>
            <input id="setup-first-name" value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required maxLength="100" autoComplete="given-name" />
            <label className="field-label" htmlFor="setup-last-name">Nom</label>
            <input id="setup-last-name" value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required maxLength="100" autoComplete="family-name" />
            <label className="field-label" htmlFor="setup-email">E-mail</label>
            <input id="setup-email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required maxLength="150" autoComplete="email" />
            <label className="field-label" htmlFor="setup-password">Mot de passe</label>
            <input id="setup-password" type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required minLength="10" autoComplete="new-password" />
            <p className="muted">Au moins 10 caractères avec majuscule, minuscule, chiffre et symbole.</p>
            <label className="field-label" htmlFor="setup-password-confirmation">Confirmer le mot de passe</label>
            <input id="setup-password-confirmation" type="password" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} required minLength="10" autoComplete="new-password" />
            <button type="submit" className="login-submit" disabled={loading}>{loading ? "Installation…" : "Créer l’école et le super-admin"}</button>
          </form>
        {error ? <p className="error-text login-error" role="alert">{error}</p> : null}
      </div>
    </section>
  );
}
