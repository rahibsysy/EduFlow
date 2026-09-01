const A6_LANDSCAPE = {
  width: 419.53,
  height: 297.64,
};

const palette = {
  text: "#10203a",
  muted: "#52637d",
  line: "#d7e1ef",
  primary: "#0f4aa3",
  secondary: "#15957d",
  surface: "#f8fbff",
  header: "#edf5ff",
};

const courseColors = [
  { bg: "#eff6ff", border: "#bfdbfe", text: "#0f4aa3" },
  { bg: "#f0fdf4", border: "#bbf7d0", text: "#166534" },
  { bg: "#fffbeb", border: "#fde68a", text: "#92400e" },
  { bg: "#fff1f2", border: "#fecdd3", text: "#b4233a" },
  { bg: "#f5f3ff", border: "#ddd6fe", text: "#5b21b6" },
  { bg: "#ecfeff", border: "#a5f3fc", text: "#155e75" },
];

const textEncoder = new TextEncoder();

const roundedRect = (ctx, x, y, width, height, radius) => {
  const safeRadius = Math.min(radius, width / 2, height / 2);
  ctx.beginPath();
  ctx.moveTo(x + safeRadius, y);
  ctx.arcTo(x + width, y, x + width, y + height, safeRadius);
  ctx.arcTo(x + width, y + height, x, y + height, safeRadius);
  ctx.arcTo(x, y + height, x, y, safeRadius);
  ctx.arcTo(x, y, x + width, y, safeRadius);
  ctx.closePath();
};

const fillTextCentered = (ctx, text, x, y, width, height, maxFontSize, minFontSize = 7) => {
  let fontSize = maxFontSize;
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";

  while (fontSize > minFontSize) {
    ctx.font = `800 ${fontSize}px Arial, sans-serif`;
    if (ctx.measureText(text).width <= width - 4) {
      break;
    }
    fontSize -= 1;
  }

  ctx.fillText(text, x + width / 2, y + height / 2);
};

const subjectColor = (code) => {
  const index = String(code || "")
    .split("")
    .reduce((total, char) => total + char.charCodeAt(0), 0);
  return courseColors[index % courseColors.length];
};

const isExternalSchedule = (schedule) => Number(schedule?.is_external) === 1 || schedule?.is_external === true;

const scheduleSubjectCode = (schedule) =>
  isExternalSchedule(schedule) ? "AILLEURS" : schedule?.subject_code || schedule?.subject_abbreviation || schedule?.subject || "";

const scheduleSessionLabel = (schedule) => {
  if (isExternalSchedule(schedule)) {
    return "";
  }

  const current = Number(schedule?.subject_session_number || 0);
  const total = Number(schedule?.subject_weekly_hours || 0);
  return current > 0 && total > 0 ? `${current}/${total}` : "";
};

const abbreviateClassName = (schedule) => {
  const directCode = String(schedule?.class_code || schedule?.code || "").trim();
  const rawName = String(schedule?.class_level_name || schedule?.name || "").trim();
  const group = String(schedule?.class_group_name || schedule?.group_name || "").trim();
  const level = String(schedule?.level_name || "").trim();
  const usableCode = directCode && !/^\d+$/.test(directCode) ? directCode : "";
  const normalized = (usableCode || rawName)
    .toUpperCase()
    .replace(/\s+/g, "")
    .replace(/APIC/g, "AC")
    .replace(/-/g, "");

  if (/^\dAC\d?$/i.test(normalized) || /^TC\d?$/i.test(normalized) || /^\dBAC\d?$/i.test(normalized)) {
    return normalized + (group && !normalized.endsWith(group) ? group.replace(/\s+/g, "") : "");
  }

  const normalizedLevel = level.toLowerCase();
  const levelNumber = level.match(/\d+/)?.[0] || rawName.match(/\d+/)?.[0] || "";
  const groupLabel = group || (/^\d+$/.test(rawName) ? rawName : "");
  if (levelNumber && (normalizedLevel.includes("coll") || /\bac\b/i.test(level))) {
    return `${levelNumber}AC${groupLabel}`;
  }

  if (normalizedLevel.includes("tronc") || normalizedLevel.includes("tc")) {
    return `TC${groupLabel}`;
  }

  if (levelNumber && normalizedLevel.includes("bac")) {
    return `${levelNumber}BAC${groupLabel}`;
  }

  return normalized && !/^\d+$/.test(normalized) ? normalized : rawName || "";
};

const compactClassName = (schedule) => {
  if (!schedule || isExternalSchedule(schedule)) {
    return "Autre établissement";
  }

  const abbreviated = abbreviateClassName(schedule);
  if (abbreviated) {
    return abbreviated;
  }

  const level = String(schedule.level_name || "").trim();
  const className = String(schedule.class_level_name || "").trim();
  const group = className && level && className !== level ? className : "";
  const compactLevel = level.replace(/\s+/g, "").toUpperCase();
  const compactGroup = group.replace(/\s+/g, "");

  if (compactLevel && compactGroup) {
    return `${compactLevel}-${compactGroup}`;
  }

  return compactLevel || compactGroup || className || "";
};

const slotStart = (slot) => (typeof slot === "string" ? slot : slot.start);
const slotLabel = (slot) => (typeof slot === "string" ? slot : slot.label || slot.start);
const slotIsPause = (slot) => Boolean(typeof slot === "object" && slot?.pause);

const loadLogo = (logoUrl) =>
  new Promise((resolve) => {
    if (!logoUrl) {
      resolve(null);
      return;
    }

    const image = new Image();
    image.crossOrigin = "anonymous";
    image.referrerPolicy = "no-referrer";
    image.onload = () => resolve(image);
    image.onerror = () => resolve(null);
    image.src = logoUrl;
  });

const drawLogo = (ctx, image, schoolName, x, y, size) => {
  roundedRect(ctx, x, y, size, size, 7);
  ctx.fillStyle = "#ffffff";
  ctx.fill();
  ctx.strokeStyle = palette.line;
  ctx.stroke();

  if (image) {
    const ratio = Math.min((size - 8) / image.width, (size - 8) / image.height);
    const drawWidth = image.width * ratio;
    const drawHeight = image.height * ratio;
    ctx.drawImage(image, x + (size - drawWidth) / 2, y + (size - drawHeight) / 2, drawWidth, drawHeight);
    return;
  }

  const initials = String(schoolName || "E")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");

  ctx.fillStyle = palette.primary;
  fillTextCentered(ctx, initials || "E", x, y, size, size, 13, 9);
};

const dataUrlToBytes = (dataUrl) => {
  const base64 = dataUrl.split(",")[1] || "";
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index);
  }
  return bytes;
};

const concatBytes = (parts) => {
  const normalized = parts.map((part) => (typeof part === "string" ? textEncoder.encode(part) : part));
  const totalLength = normalized.reduce((total, part) => total + part.length, 0);
  const output = new Uint8Array(totalLength);
  let offset = 0;

  normalized.forEach((part) => {
    output.set(part, offset);
    offset += part.length;
  });

  return output;
};

const buildImageOnlyPdf = ({ imageBytes, imageWidth, imageHeight }) => {
  const content = `q\n${A6_LANDSCAPE.width.toFixed(2)} 0 0 ${A6_LANDSCAPE.height.toFixed(2)} 0 0 cm\n/Im0 Do\nQ`;
  const objects = [
    ["1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"],
    ["2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"],
    [
      `3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${A6_LANDSCAPE.width.toFixed(2)} ${A6_LANDSCAPE.height.toFixed(
        2
      )}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>\nendobj\n`,
    ],
    [
      `4 0 obj\n<< /Type /XObject /Subtype /Image /Width ${imageWidth} /Height ${imageHeight} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${imageBytes.length} >>\nstream\n`,
      imageBytes,
      "\nendstream\nendobj\n",
    ],
    [`5 0 obj\n<< /Length ${content.length} >>\nstream\n${content}\nendstream\nendobj\n`],
  ];

  const chunks = ["%PDF-1.4\n"];
  const offsets = [];
  let length = textEncoder.encode(chunks[0]).length;

  objects.forEach((objectParts) => {
    offsets.push(length);
    const bytes = concatBytes(objectParts);
    chunks.push(bytes);
    length += bytes.length;
  });

  const xrefOffset = length;
  const xrefRows = offsets.map((offset) => `${String(offset).padStart(10, "0")} 00000 n \n`).join("");
  chunks.push(
    `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n${xrefRows}trailer\n<< /Size ${
      objects.length + 1
    } /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`
  );

  return new Blob(chunks, { type: "application/pdf" });
};

export const downloadBlob = (blob, filename) => {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
};

const safeFilePart = (value) =>
  String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/gi, "-")
    .replace(/^-+|-+$/g, "")
    .toLowerCase();

const drawScheduleGrid = ({ ctx, schedules, days, timeSlots, mode }) => {
  const x = 14;
  const y = 86;
  const width = A6_LANDSCAPE.width - 28;
  const height = A6_LANDSCAPE.height - y - 14;
  const timeColumnWidth = 43;
  const dayColumnWidth = (width - timeColumnWidth) / days.length;
  const headerHeight = 17;
  const rowHeight = (height - headerHeight) / timeSlots.length;

  ctx.lineWidth = 0.8;
  ctx.strokeStyle = palette.line;
  ctx.fillStyle = palette.header;
  roundedRect(ctx, x, y, width, height, 5);
  ctx.fill();
  ctx.stroke();

  ctx.fillStyle = "#ffffff";
  ctx.fillRect(x, y + headerHeight, width, height - headerHeight - 1);

  ctx.fillStyle = palette.header;
  ctx.fillRect(x, y, width, headerHeight);

  ctx.strokeStyle = palette.line;
  ctx.beginPath();
  ctx.moveTo(x, y + headerHeight);
  ctx.lineTo(x + width, y + headerHeight);
  ctx.stroke();

  ctx.fillStyle = palette.primary;
  ctx.font = "800 7.5px Arial, sans-serif";
  ctx.textAlign = "center";
  ctx.textBaseline = "middle";
  ctx.fillText("Heure", x + timeColumnWidth / 2, y + headerHeight / 2);

  days.forEach((day, dayIndex) => {
    const columnX = x + timeColumnWidth + dayIndex * dayColumnWidth;
    ctx.fillText(day.label, columnX + dayColumnWidth / 2, y + headerHeight / 2);
  });

  for (let column = 0; column <= days.length; column += 1) {
    const lineX = x + timeColumnWidth + column * dayColumnWidth;
    ctx.beginPath();
    ctx.moveTo(lineX, y);
    ctx.lineTo(lineX, y + height);
    ctx.stroke();
  }

  timeSlots.forEach((slot, rowIndex) => {
    const start = slotStart(slot);
    const label = slotLabel(slot);
    const isPause = slotIsPause(slot);
    const rowY = y + headerHeight + rowIndex * rowHeight;
    if (isPause) {
      ctx.fillStyle = "#edf5ff";
      ctx.fillRect(x + timeColumnWidth + 0.5, rowY + 0.5, width - timeColumnWidth - 1, rowHeight - 1);
    }

    ctx.strokeStyle = palette.line;
    ctx.beginPath();
    ctx.moveTo(x, rowY);
    ctx.lineTo(x + width, rowY);
    ctx.stroke();

    ctx.fillStyle = palette.muted;
    ctx.font = "800 7px Arial, sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    const timeParts = label.split(" - ");
    ctx.fillText(timeParts[0] || label, x + timeColumnWidth / 2, rowY + rowHeight / 2 - 4);
    ctx.fillText(timeParts[1] || "", x + timeColumnWidth / 2, rowY + rowHeight / 2 + 4);

    if (isPause) {
      ctx.fillStyle = palette.primary;
      ctx.font = "800 8px Arial, sans-serif";
      ctx.fillText("Pause", x + timeColumnWidth + (width - timeColumnWidth) / 2, rowY + rowHeight / 2);
      return;
    }

    days.forEach((day, dayIndex) => {
      const items = schedules.filter(
        (schedule) => schedule.day_of_week === day.value && String(schedule.start_time || "").slice(0, 5) === start
      );

      items.forEach((schedule, itemIndex) => {
        const code = scheduleSubjectCode(schedule);
        const color = isExternalSchedule(schedule)
          ? { bg: "#f5f3ff", border: "#c4b5fd", text: "#5b21b6" }
          : subjectColor(code);
        const gap = 2;
        const maxVisibleItems = Math.min(items.length, 3);
        const itemHeight = (rowHeight - gap * (maxVisibleItems + 1)) / maxVisibleItems;
        const courseX = x + timeColumnWidth + dayIndex * dayColumnWidth + 4;
        const courseY = rowY + gap + itemIndex * (itemHeight + gap);
        const courseW = dayColumnWidth - 8;
        const courseH = Math.max(12, itemHeight);

        if (itemIndex >= 3) {
          return;
        }

        ctx.fillStyle = color.bg;
        ctx.strokeStyle = color.border;
        roundedRect(ctx, courseX, courseY, courseW, courseH, 4);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = color.text;
        const sessionLabel = scheduleSessionLabel(schedule);
        const title = sessionLabel ? `${code} ${sessionLabel}` : code;
        if (mode === "teacher") {
          ctx.textAlign = "center";
          ctx.textBaseline = "middle";
          ctx.font = `800 ${isExternalSchedule(schedule) ? 8 : 9}px Arial, sans-serif`;
          ctx.fillText(title, courseX + courseW / 2, courseY + courseH / 2 - (isExternalSchedule(schedule) ? 0 : 4));
          if (isExternalSchedule(schedule)) {
            return;
          }
          ctx.font = "700 6.2px Arial, sans-serif";
          ctx.fillStyle = palette.muted;
          ctx.fillText(compactClassName(schedule), courseX + courseW / 2, courseY + courseH / 2 + 5);
        } else {
          fillTextCentered(ctx, title, courseX, courseY, courseW, courseH, 10.5, 6.5);
        }
      });
    });
  });
};

export const buildSchedulePdf = async ({
  mode,
  school,
  logoUrl,
  className,
  teacherName,
  weekLabel,
  yearLabel,
  schedules,
  days,
  timeSlots,
}) => {
  const scale = 4;
  const canvas = document.createElement("canvas");
  canvas.width = Math.round(A6_LANDSCAPE.width * scale);
  canvas.height = Math.round(A6_LANDSCAPE.height * scale);
  const ctx = canvas.getContext("2d");
  const schoolName = school?.name || "École";
  const logo = await loadLogo(logoUrl);

  ctx.scale(scale, scale);
  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, A6_LANDSCAPE.width, A6_LANDSCAPE.height);

  ctx.fillStyle = palette.surface;
  roundedRect(ctx, 10, 10, A6_LANDSCAPE.width - 20, A6_LANDSCAPE.height - 20, 8);
  ctx.fill();

  drawLogo(ctx, logo, schoolName, 16, 16, 28);

  ctx.fillStyle = palette.text;
  ctx.font = "800 10px Arial, sans-serif";
  ctx.textAlign = "left";
  ctx.textBaseline = "top";
  ctx.fillText(schoolName, 50, 18);

  ctx.fillStyle = palette.primary;
  ctx.font = "900 14px Arial, sans-serif";
  const title =
    mode === "class"
      ? `Emploi du temps - ${className || "Classe"}`.trim()
      : `Emploi du temps du professeur ${teacherName || ""}`.trim();
  ctx.fillText(title, 50, 34);

  ctx.fillStyle = palette.muted;
  ctx.font = "700 8.5px Arial, sans-serif";
  ctx.fillText(`Semaine ${weekLabel} - Année scolaire ${yearLabel}`, 50, 53);

  drawScheduleGrid({ ctx, schedules, days, timeSlots, mode });

  let jpegDataUrl;
  try {
    jpegDataUrl = canvas.toDataURL("image/jpeg", 0.95);
  } catch {
    const fallbackCanvas = document.createElement("canvas");
    fallbackCanvas.width = canvas.width;
    fallbackCanvas.height = canvas.height;
    const fallbackCtx = fallbackCanvas.getContext("2d");
    fallbackCtx.scale(scale, scale);
    fallbackCtx.fillStyle = "#ffffff";
    fallbackCtx.fillRect(0, 0, A6_LANDSCAPE.width, A6_LANDSCAPE.height);
    fallbackCtx.fillStyle = palette.surface;
    roundedRect(fallbackCtx, 10, 10, A6_LANDSCAPE.width - 20, A6_LANDSCAPE.height - 20, 8);
    fallbackCtx.fill();
    drawLogo(fallbackCtx, null, schoolName, 16, 16, 28);
    fallbackCtx.fillStyle = palette.text;
    fallbackCtx.font = "800 10px Arial, sans-serif";
    fallbackCtx.fillText(schoolName, 50, 18);
    fallbackCtx.fillStyle = palette.primary;
    fallbackCtx.font = "900 14px Arial, sans-serif";
    fallbackCtx.fillText(title, 50, 34);
    fallbackCtx.fillStyle = palette.muted;
    fallbackCtx.font = "700 8.5px Arial, sans-serif";
    fallbackCtx.fillText(`Semaine ${weekLabel} - Année scolaire ${yearLabel}`, 50, 53);
    drawScheduleGrid({ ctx: fallbackCtx, schedules, days, timeSlots, mode });
    jpegDataUrl = fallbackCanvas.toDataURL("image/jpeg", 0.95);
  }

  const imageBytes = dataUrlToBytes(jpegDataUrl);
  const pdf = buildImageOnlyPdf({
    imageBytes,
    imageWidth: canvas.width,
    imageHeight: canvas.height,
  });

  const targetPart = mode === "class" ? safeFilePart(className || "classe") : safeFilePart(teacherName || "professeur");
  return {
    blob: pdf,
    filename: `emploi-du-temps-${targetPart}-${safeFilePart(yearLabel)}-semaine-${safeFilePart(weekLabel)}.pdf`,
  };
};

export const exportSchedulePdf = async (options) => {
  const { blob, filename } = await buildSchedulePdf(options);
  downloadBlob(blob, filename);
};
