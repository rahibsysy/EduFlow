import React from "react";

const priorityStyles = {
  critical: { symbol: "!", label: "Rouge" },
  high: { symbol: ">", label: "Orange" },
  medium: { symbol: "~", label: "Jaune" },
  info: { symbol: "i", label: "Bleu" },
};

export default function AlertCard({ alert }) {
  const style = priorityStyles[alert.level] || priorityStyles.info;

  return (
    <article className={`alert-card alert-card-${alert.level || "info"}`}>
      <div className="alert-card-icon" aria-hidden="true">{style.symbol}</div>
      <div className="alert-card-content">
        <p className="kpi-label">{alert.title}</p>
        <h3>{alert.count}</h3>
        <p className="muted">{alert.description}</p>
      </div>
      <span className="alert-card-badge" title={`Priorite ${style.label}`}>
        {alert.priority}
      </span>
    </article>
  );
}
