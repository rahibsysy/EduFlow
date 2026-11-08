export const CLASS_LEVEL_CYCLES = [
  { id: "preschool", label: "Maternelle" },
  { id: "primary", label: "Primaire" },
  { id: "middle", label: "Collège" },
  { id: "high", label: "Lycée" },
];

const text = (value) => String(value || "").trim();
const compact = (value) => text(value)
  .normalize("NFD")
  .replace(/[\u0300-\u036f]/g, "")
  .toUpperCase()
  .replace(/[^A-Z0-9]/g, "");

export const classLevelLabel = (classLevel) => {
  const level = text(classLevel?.level_name);
  const group = text(classLevel?.group_name);
  const name = text(classLevel?.name);

  if (level && group) return `${level} – ${group}`;
  if (level) return level;
  return name && !/^\d+$/.test(name) ? name : group || "Classe sans appellation";
};

export const classLevelCycle = (classLevel) => {
  const level = compact(classLevel?.level_name || classLevel?.name);

  if (/MATERNELLE/.test(level) || /^(PS|MS|GS)$/.test(level)) return "preschool";
  if (/PRIMAIRE/.test(level) || /^(CP|CE1|CE2|CM1|CM2|6AP)$/.test(level)) return "primary";
  if (/COLLEGE/.test(level) || /^[123]AC$/.test(level)) return "middle";
  if (/LYCEE/.test(level) || /^(TC|TRONCCOMMUN|1BAC|2BAC)$/.test(level)) return "high";

  return null;
};
