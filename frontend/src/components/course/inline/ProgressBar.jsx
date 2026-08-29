export default function ProgressBar({ value = 0 }) {
  const progress = Math.min(100, Math.max(0, Math.round(value)));

  const styles = {
    wrapper: {
      display: "grid",
      gap: 8,
      width: "100%",
    },
    labelRow: {
      display: "flex",
      alignItems: "center",
      justifyContent: "space-between",
      gap: 12,
      color: "#10203a",
      fontSize: 14,
      fontWeight: 700,
    },
    value: {
      color: progress === 100 ? "#0f766e" : "#5f6f8d",
      fontVariantNumeric: "tabular-nums",
    },
    track: {
      width: "100%",
      height: 12,
      overflow: "hidden",
      borderRadius: 999,
      border: "1px solid #d7e1ef",
      background: "#eef4fb",
    },
    fill: {
      width: `${progress}%`,
      height: "100%",
      borderRadius: 999,
      background:
        progress === 100
          ? "linear-gradient(90deg, #10b981, #0f766e)"
          : "linear-gradient(90deg, #0f4aa3, #15957d)",
      transition: "width 220ms ease",
    },
  };

  return (
    <div style={styles.wrapper}>
      <div style={styles.labelRow}>
        <span>Course progress</span>
        <span style={styles.value}>{progress}%</span>
      </div>
      <div
        style={styles.track}
        role="progressbar"
        aria-label="Course progress"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={progress}
      >
        <div style={styles.fill} />
      </div>
    </div>
  );
}
