import React from "react";
import AlertCard from "./AlertCard";

export default function AlertList({ alerts }) {
  const totalAlerts = (alerts || []).reduce((sum, alert) => sum + Number(alert.count || 0), 0);

  return (
    <section className="panel alerts-panel">
      <div className="panel-header">
        <div>
          <h3>Centre d'Alertes Intelligentes</h3>
          <p className="muted">Priorisez rapidement les situations financières critiques.</p>
        </div>
        <span className="alerts-total">{totalAlerts} signalements</span>
      </div>
      <div className="alerts-grid">
        {(alerts || []).map((alert) => (
          <AlertCard key={alert.code} alert={alert} />
        ))}
        {!(alerts || []).length && (
          <div className="empty-alerts">Aucune alerte urgente pour le moment.</div>
        )}
      </div>
    </section>
  );
}
