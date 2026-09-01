import { useState } from "react";
import { useNavigate } from "react-router-dom";
import useAuth from "../hooks/useAuth";
import { loginRequest } from "../services/authService";
import { BRAND_NAME, SCHOOL_NAME } from "../config/brand";

export default function LoginPage() {
  const [form, setForm] = useState({ email: "", password: "" });
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const { login } = useAuth();
  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");
    setLoading(true);

    try {
      const data = await loginRequest(form);
      login(data);
      if (data?.user?.role === "super_admin") {
        navigate("/super-admin/dashboard");
      } else {
        navigate("/dashboard");
      }
    } catch {
      setError("Identifiants invalides ou API indisponible.");
    } finally {
      setLoading(false);
    }
  };

  return (
    <section className="login-screen">
      <div className="login-hero">
        <p className="brand-kicker">{BRAND_NAME}</p>
        <h1>Gestion financiere Pour Votre Etablissement</h1>
        <p>
          Connectez-vous pour suivre les encaissements, les impayes et la gestion
          operationnelle de votre etablissement sur une seule interface.
        </p>
        <div className="login-features">
          <span className="login-feature">Paiements & impayes</span>
          <span className="login-feature">Eleves & classes</span>
          <span className="login-feature">Tableaux de bord</span>
        </div>
      </div>

      <div className="login-card">
        <h2>Connexion</h2>
        <form className="form-grid" onSubmit={handleSubmit}>
          <input
            type="email"
            placeholder="Email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            required
          />
          <input
            type="password"
            placeholder="Mot de passe"
            value={form.password}
            onChange={(e) => setForm({ ...form, password: e.target.value })}
            required
          />
          <button type="submit" className="login-submit" disabled={loading}>
            {loading ? "Connexion..." : "Se connecter"}
          </button>
        </form>
        {error && <p className="error-text">{error}</p>}
      </div>
    </section>
  );
}
