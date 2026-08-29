import { useEffect, useMemo, useState } from "react";
import { getClassLevels } from "../services/classLevelService";
import { createSchedule, deleteSchedule, getSchedules, updateSchedule } from "../services/scheduleService";
import { getCurrentSchool, getSchoolById } from "../services/schoolService";
import { getSubjects } from "../services/subjectService";
import { getTeachers } from "../services/teacherService";
import { createUser } from "../services/userService";
import { buildSchedulePdf, downloadBlob } from "../utils/schedulePdfExport";

const API_URL = (import.meta.env.VITE_API_URL || "http://127.0.0.1:8080").replace(/\/+$/, "");

const resolveLogoUrl = (logoPath) => {
  if (!logoPath) {
    return "";
  }

  if (/^(https?:|data:image\/)/i.test(logoPath)) {
    return logoPath;
  }

  const normalized = String(logoPath).replace(/\\/g, "/").replace(/^\/+/, "");
  return `${API_URL}/${normalized}`;
};

const dayOptions = [
  { value: "MONDAY", label: "Lundi" },
  { value: "TUESDAY", label: "Mardi" },
  { value: "WEDNESDAY", label: "Mercredi" },
  { value: "THURSDAY", label: "Jeudi" },
  { value: "FRIDAY", label: "Vendredi" },
  { value: "SATURDAY", label: "Samedi" },
];

const baseTimeSlots = [
  { start: "08:30", end: "09:30", label: "08:30 - 09:30" },
  { start: "09:30", end: "10:30", label: "09:30 - 10:30" },
  { start: "10:30", end: "11:30", label: "10:30 - 11:30" },
  { start: "11:30", end: "12:30", label: "11:30 - 12:30" },
  { start: "12:30", end: "14:30", label: "12:30 - 14:30", pause: true },
  { start: "14:30", end: "15:30", label: "14:30 - 15:30" },
  { start: "15:30", end: "16:30", label: "15:30 - 16:30" },
  { start: "16:30", end: "17:30", label: "16:30 - 17:30" },
  { start: "17:30", end: "18:30", label: "17:30 - 18:30" },
];

const monthLabels = [
  "janvier",
  "février",
  "mars",
  "avril",
  "mai",
  "juin",
  "juillet",
  "août",
  "septembre",
  "octobre",
  "novembre",
  "décembre",
];

const getAcademicYear = (date = new Date()) => (date.getMonth() >= 7 ? date.getFullYear() : date.getFullYear() - 1);

const schoolCalendarStart = (academicYear) => {
  const septemberFirst = new Date(Number(academicYear), 8, 1);
  const startDay = septemberFirst.getDay() || 7;
  const firstSeptemberWeekMonday = new Date(septemberFirst);
  firstSeptemberWeekMonday.setDate(septemberFirst.getDate() - startDay + 1);

  const firstSchoolWeekMonday = new Date(firstSeptemberWeekMonday);
  firstSchoolWeekMonday.setDate(firstSeptemberWeekMonday.getDate() - 7);
  return firstSchoolWeekMonday;
};

const getAcademicWeek = (date = new Date(), academicYear = getAcademicYear(date)) => {
  const firstWeekMonday = schoolCalendarStart(academicYear);
  const target = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  const diffDays = Math.floor((target - firstWeekMonday) / 86400000);
  return Math.max(1, Math.min(53, Math.floor(diffDays / 7) + 1));
};

const currentYear = getAcademicYear();
const currentWeek = getAcademicWeek();
const yearOptions = Array.from({ length: 5 }, (_, index) => currentYear - 2 + index);
const weekOptions = Array.from({ length: 53 }, (_, index) => index + 1);

const academicYearLabel = (year) => `${year}-${Number(year) + 1}`;

const academicWeekStart = (academicYear, weekNumber) => {
  const firstWeekMonday = schoolCalendarStart(academicYear);
  const weekStart = new Date(firstWeekMonday);
  weekStart.setDate(firstWeekMonday.getDate() + (Number(weekNumber) - 1) * 7);
  return weekStart;
};

const formatDayMonth = (date) => `${String(date.getDate()).padStart(2, "0")} ${monthLabels[date.getMonth()]}`;

const academicWeekLabel = (academicYear, weekNumber) => {
  const start = academicWeekStart(academicYear, weekNumber);
  const end = new Date(start);
  end.setDate(start.getDate() + 5);

  if (start.getMonth() === end.getMonth()) {
    return `du ${String(start.getDate()).padStart(2, "0")} au ${formatDayMonth(end)}`;
  }

  return `du ${formatDayMonth(start)} au ${formatDayMonth(end)}`;
};

const emptyForm = {
  class_level_id: "",
  subject_id: "",
  teacher_id: "",
  teacher_name: "",
  notes: "",
  year_value: String(currentYear),
  week_number: String(currentWeek),
  day_of_week: "MONDAY",
  start_time: "08:30",
  end_time: "09:30",
  is_external: false,
};

const formatTime = (value) => String(value || "").slice(0, 5);

const addOneHour = (time) => {
  const [hour = "8", minute = "30"] = String(time || "08:30").split(":");
  const nextHour = Math.min(23, Number(hour) + 1);
  return `${String(nextHour).padStart(2, "0")}:${String(Number(minute)).padStart(2, "0")}`;
};

const normalizeSlot = (slot) => {
  if (typeof slot === "string") {
    return { start: slot, end: addOneHour(slot), label: `${slot} - ${addOneHour(slot)}` };
  }

  return slot;
};

const buildTimeSlots = (scheduleList = []) => {
  const slots = new Map(baseTimeSlots.map((slot) => [slot.start, slot]));

  scheduleList.forEach((schedule) => {
    const start = formatTime(schedule.start_time);
    if (!start || slots.has(start)) {
      return;
    }

    const end = formatTime(schedule.end_time) || addOneHour(start);
    slots.set(start, { start, end, label: `${start} - ${end}` });
  });

  return Array.from(slots.values()).map(normalizeSlot).sort((a, b) => a.start.localeCompare(b.start));
};

const teacherName = (teacher) => teacher?.name || `${teacher?.first_name || ""} ${teacher?.last_name || ""}`.trim();

const scheduleSubjectCode = (schedule) =>
  Number(schedule.is_external) === 1 || schedule.is_external === true
    ? "AILLEURS"
    : schedule.subject_code || schedule.subject_abbreviation || schedule.subject || "-";

const subjectLabel = (subject) => subject?.code || subject?.abbreviation || subject?.name || "Matière";

const classDisplayName = (item) => {
  if (!item) {
    return "Classe";
  }

  const level = item.level_name || "";
  const group = item.group_name || item.name || "";
  return level && group ? `${level} - ${group}` : level || group || "Classe";
};

const compactClassName = (item) => {
  if (!item) {
    return "Classe";
  }

  const code = String(item.code || "").trim();
  const level = String(item.level_name || item.name || "").trim();
  const group = String(item.group_name || "").trim();
  const compactLevel = code && !/^\d+$/.test(code) ? code : level.replace(/\s+/g, "").toUpperCase();

  if (compactLevel && group) {
    return `${compactLevel}-${group.replace(/\s+/g, "")}`;
  }

  return compactLevel || level || group || "Classe";
};

const courseTone = (schedule) => {
  if (Number(schedule.is_external) === 1 || schedule.is_external === true) {
    return "external";
  }

  const code = scheduleSubjectCode(schedule);
  const tones = ["blue", "green", "amber", "rose", "violet", "cyan"];
  const index = String(code)
    .split("")
    .reduce((total, char) => total + char.charCodeAt(0), 0);
  return tones[index % tones.length];
};

const sameSchedule = (first, second) => {
  if (first?.id && second?.id) {
    return String(first.id) === String(second.id);
  }

  return (
    String(first?.class_level_id || "") === String(second?.class_level_id || "") &&
    String(first?.teacher_id || "") === String(second?.teacher_id || "") &&
    String(first?.teacher_name || "").toLowerCase() === String(second?.teacher_name || "").toLowerCase() &&
    String(first?.day_of_week || "") === String(second?.day_of_week || "") &&
    formatTime(first?.start_time) === formatTime(second?.start_time) &&
    formatTime(first?.end_time) === formatTime(second?.end_time)
  );
};

const uniqueSchedules = (...scheduleLists) => {
  const merged = [];
  scheduleLists.flat().filter(Boolean).forEach((schedule) => {
    if (!merged.some((item) => sameSchedule(item, schedule))) {
      merged.push(schedule);
    }
  });
  return merged;
};

const scheduleMatchesTeacher = (schedule, teacher) => {
  if (!teacher) {
    return false;
  }

  if (schedule.teacher_id && teacher.id) {
    return String(schedule.teacher_id) === String(teacher.id);
  }

  return String(schedule.teacher_name || "").trim().toLowerCase() === teacherName(teacher).trim().toLowerCase();
};

const scheduleCountLabel = (count) => `${count} ${count > 1 ? "créneaux" : "créneau"} cette semaine`;

export default function SchedulesPage() {
  const [mode, setMode] = useState("class");
  const [selectedClassId, setSelectedClassId] = useState("");
  const [selectedTeacherId, setSelectedTeacherId] = useState("");
  const [selectedYear, setSelectedYear] = useState(String(currentYear));
  const [selectedWeek, setSelectedWeek] = useState(String(currentWeek));
  const [teacherForm, setTeacherForm] = useState({ full_name: "" });

  const [classes, setClasses] = useState([]);
  const [teachers, setTeachers] = useState([]);
  const [subjects, setSubjects] = useState([]);
  const [school, setSchool] = useState({ id: null, name: null, logo_path: null });
  const [schedules, setSchedules] = useState([]);
  const [weeklyTeacherSchedules, setWeeklyTeacherSchedules] = useState([]);

  const [form, setForm] = useState(emptyForm);
  const [editingId, setEditingId] = useState(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [loading, setLoading] = useState(true);
  const [subjectsLoading, setSubjectsLoading] = useState(false);
  const [pdfLoading, setPdfLoading] = useState(false);
  const [pdfPreview, setPdfPreview] = useState({ open: false, url: "", filename: "", blob: null });
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");

  const selectedClass = useMemo(
    () => classes.find((item) => String(item.id) === String(selectedClassId)),
    [classes, selectedClassId]
  );

  const selectedTeacher = useMemo(
    () => teachers.find((item) => String(item.id) === String(selectedTeacherId)),
    [teachers, selectedTeacherId]
  );

  const timeSlots = useMemo(() => {
    return buildTimeSlots(schedules);
  }, [schedules]);

  const targetLabel = mode === "class" ? classDisplayName(selectedClass) : teacherName(selectedTeacher) || "Professeur";

  const loadReferenceData = async () => {
    setLoading(true);
    setError("");
    try {
      const [classData, teacherData, schoolData] = await Promise.all([
        getClassLevels(),
        getTeachers(),
        getCurrentSchool().catch(() => null),
      ]);
      const safeClasses = Array.isArray(classData) ? classData : [];
      const safeTeachers = Array.isArray(teacherData) ? teacherData : [];

      setClasses(safeClasses);
      setTeachers(safeTeachers);
      if (schoolData) {
        setSchool(schoolData);
      }
      setSelectedClassId((prev) => prev || String(safeClasses[0]?.id || ""));
      setSelectedTeacherId((prev) => prev || String(safeTeachers[0]?.id || ""));
    } catch (err) {
      setError(err?.response?.data?.message || "Impossible de charger les classes et les professeurs.");
    } finally {
      setLoading(false);
    }
  };

  const teacherScheduleQuery = (teacher, teacherId = teacher?.id) => ({
    teacher_id: teacherId,
    teacher_name: teacher ? teacherName(teacher) : undefined,
    status: "ACTIVE",
  });

  const loadGridSchedules = async () => {
    const target = mode === "class" ? selectedClassId : selectedTeacherId;
    if (!target || (mode === "class" && (!selectedYear || !selectedWeek))) {
      setSchedules([]);
      return;
    }

    setError("");
    try {
      const query =
        mode === "class"
          ? {
              class_level_id: selectedClassId,
              year_value: selectedYear,
              week_number: selectedWeek,
              status: "ACTIVE",
            }
          : teacherScheduleQuery(selectedTeacher, selectedTeacherId);
      const data = await getSchedules({
        ...query,
      });
      setSchedules(Array.isArray(data) ? data : []);
    } catch (err) {
      setSchedules([]);
      setError(err?.response?.data?.message || "Impossible de charger les emplois du temps.");
    }
  };

  const loadWeeklyTeacherOverview = async () => {
    try {
      const data = await getSchedules({
        status: "ACTIVE",
      });
      setWeeklyTeacherSchedules(Array.isArray(data) ? data : []);
    } catch {
      setWeeklyTeacherSchedules([]);
    }
  };

  const loadSubjectsForClass = async (classLevelId, selectedSubjectId = "") => {
    setSubjects([]);
    if (!classLevelId) {
      return;
    }

    setSubjectsLoading(true);
    try {
      const data = await getSubjects({ class_level_id: classLevelId });
      const safeSubjects = Array.isArray(data) ? data : [];
      setSubjects(safeSubjects);
      const fallbackSubjectId = safeSubjects[0]?.id ? String(safeSubjects[0].id) : "";
      setForm((prev) => ({
        ...prev,
        subject_id: safeSubjects.some((subject) => String(subject.id) === String(selectedSubjectId))
          ? String(selectedSubjectId)
          : fallbackSubjectId,
      }));
    } catch (err) {
      setError(err?.response?.data?.message || "Impossible de charger les matières de cette classe.");
    } finally {
      setSubjectsLoading(false);
    }
  };

  useEffect(() => {
    loadReferenceData();
  }, []);

  useEffect(() => {
    loadGridSchedules();
  }, [mode, selectedClassId, selectedTeacherId, selectedYear, selectedWeek, teachers]);

  useEffect(() => {
    loadWeeklyTeacherOverview();
  }, [selectedYear, selectedWeek]);

  const closeModal = () => {
    setModalOpen(false);
    setEditingId(null);
    setSubjects([]);
    setForm({
      ...emptyForm,
      year_value: selectedYear,
      week_number: selectedWeek,
    });
  };

  const closePdfPreview = () => {
    if (pdfPreview.url) {
      URL.revokeObjectURL(pdfPreview.url);
    }
    setPdfPreview({ open: false, url: "", filename: "", blob: null });
  };

  const openCreateModal = (day, slot) => {
    const selectedSlot = normalizeSlot(slot);
    const nextForm = {
      ...emptyForm,
      class_level_id: mode === "class" ? String(selectedClassId) : "",
      teacher_id: mode === "teacher" ? String(selectedTeacherId) : "",
      teacher_name: mode === "teacher" ? teacherName(selectedTeacher) : "",
      year_value: selectedYear,
      week_number: selectedWeek,
      day_of_week: day,
      start_time: selectedSlot.start,
      end_time: selectedSlot.end || addOneHour(selectedSlot.start),
      is_external: false,
    };

    setMessage("");
    setError("");
    setEditingId(null);
    setForm(nextForm);
    setModalOpen(true);
    if (nextForm.class_level_id) {
      loadSubjectsForClass(nextForm.class_level_id);
    }
  };

  const openEditModal = (schedule) => {
    const scheduleTeacherId =
      schedule.teacher_id ||
      teachers.find((teacher) => teacherName(teacher).toLowerCase() === String(schedule.teacher_name || "").toLowerCase())?.id ||
      "";
    const nextForm = {
      class_level_id: String(schedule.class_level_id || ""),
      subject_id: String(schedule.subject_id || ""),
      teacher_id: String(scheduleTeacherId),
      teacher_name: schedule.teacher_name || "",
      notes: schedule.notes || "",
      year_value: String(schedule.year_value || selectedYear),
      week_number: String(schedule.week_number || selectedWeek),
      day_of_week: schedule.day_of_week || "MONDAY",
      start_time: formatTime(schedule.start_time),
      end_time: formatTime(schedule.end_time),
      is_external: Boolean(schedule.is_external),
    };

    setMessage("");
    setError("");
    setEditingId(schedule.id);
    setForm(nextForm);
    setModalOpen(true);
    if (!nextForm.is_external && nextForm.class_level_id) {
      loadSubjectsForClass(nextForm.class_level_id, nextForm.subject_id);
    }
  };

  const submitTeacher = async (e) => {
    e.preventDefault();
    setMessage("");
    setError("");

    const fullName = teacherForm.full_name.trim().replace(/\s+/g, " ");
    if (!fullName) {
      setError("Renseignez le nom du professeur.");
      return;
    }

    const nameParts = fullName.split(" ");
    const firstName = nameParts.shift() || fullName;
    const lastName = nameParts.join(" ") || "-";

    try {
      const created = await createUser({
        first_name: firstName,
        last_name: lastName,
        email_local_part: `${firstName}.${lastName}`,
        password: "EduFlow@123",
        role: "user",
        status: "ACTIVE",
      });
      const teacherData = await getTeachers();
      const safeTeachers = Array.isArray(teacherData) ? teacherData : [];
      setTeachers(safeTeachers);
      setSelectedTeacherId(String(created.id || safeTeachers.find((teacher) => teacherName(teacher) === `${firstName} ${lastName}`)?.id || selectedTeacherId));
      setTeacherForm({ full_name: "" });
      setMessage("Professeur ajouté avec succès.");
    } catch (err) {
      setError(err?.response?.data?.message || "Impossible d'ajouter ce professeur.");
    }
  };

  const submitSchedule = async (e) => {
    e.preventDefault();
    setMessage("");
    setError("");

    const isExternal = mode === "teacher" && form.is_external;
    const effectiveTeacherId = mode === "teacher" ? selectedTeacherId : form.teacher_id;
    const effectiveTeacher = teachers.find((teacher) => String(teacher.id) === String(effectiveTeacherId));

    if (!effectiveTeacher) {
      setError("Sélectionnez un professeur.");
      return;
    }

    const payload = {
      class_level_id: isExternal ? undefined : Number(form.class_level_id),
      subject_id: isExternal ? undefined : Number(form.subject_id),
      teacher_id: Number(effectiveTeacher.id),
      teacher_name: teacherName(effectiveTeacher),
      year_value: Number(form.year_value),
      week_number: Number(form.week_number),
      day_of_week: form.day_of_week,
      start_time: form.start_time,
      end_time: form.end_time,
      is_external: isExternal,
      notes: isExternal ? form.notes.trim() : "",
    };

    try {
      if (editingId) {
        await updateSchedule(editingId, payload);
        setMessage("Créneau modifié avec succès.");
      } else {
        await createSchedule(payload);
        setMessage("Créneau créé avec succès.");
      }

      closeModal();
      const teacherData = await getTeachers();
      setTeachers(Array.isArray(teacherData) ? teacherData : []);
      if (!selectedTeacherId && payload.teacher_id) {
        setSelectedTeacherId(String(payload.teacher_id));
      }
      await Promise.all([loadGridSchedules(), loadWeeklyTeacherOverview()]);
    } catch (err) {
      setError(err?.response?.data?.message || "Échec de sauvegarde du créneau.");
    }
  };

  const removeSchedule = async () => {
    if (!editingId || !window.confirm("Supprimer ce créneau de l'emploi du temps ?")) {
      return;
    }

    setMessage("");
    setError("");
    try {
      await deleteSchedule(editingId);
      setMessage("Créneau supprimé avec succès.");
      closeModal();
      await Promise.all([loadGridSchedules(), loadWeeklyTeacherOverview()]);
    } catch (err) {
      setError(err?.response?.data?.message || "Échec de suppression du créneau.");
    }
  };

  const viewTeacherSchedule = async (teacher) => {
    const nextTeacherId = String(teacher.id);
    setMessage("");
    setError("");
    setMode("teacher");
    setSelectedTeacherId(nextTeacherId);
    setSchedules([]);

    try {
      const data = await getSchedules({
        ...teacherScheduleQuery(teacher, nextTeacherId),
      });
      setSchedules(Array.isArray(data) ? data : []);
    } catch (err) {
      setSchedules([]);
      setError(err?.response?.data?.message || "Impossible de charger l'emploi du temps de ce professeur.");
    }
  };

  const buildWeeklyPdf = async (targetMode = mode, teacherOverride = null) => {
    const isClassPdf = targetMode === "class";
    const targetTeacherId = teacherOverride ? String(teacherOverride.id) : selectedTeacherId;

    if (isClassPdf && !selectedClassId) {
      setError("Choisissez une classe avant de générer son emploi du temps.");
      return null;
    }

    if (!isClassPdf && !targetTeacherId) {
      setError("Choisissez un professeur avant de générer son emploi du temps.");
      return null;
    }

    setError("");

    let weeklySchedules = [];
    try {
      const targetTeacher = teacherOverride || selectedTeacher;
      const query = isClassPdf
        ? {
            class_level_id: selectedClassId,
            year_value: selectedYear,
            week_number: selectedWeek,
            status: "ACTIVE",
          }
        : teacherScheduleQuery(targetTeacher, targetTeacherId);
      const data = await getSchedules(query);
      const apiSchedules = Array.isArray(data) ? data : [];
      const visibleSchedules = schedules.filter((schedule) => {
        if (isClassPdf && (String(schedule.year_value) !== String(selectedYear) || String(schedule.week_number) !== String(selectedWeek))) {
          return false;
        }

        if (isClassPdf) {
          return String(schedule.class_level_id) === String(selectedClassId);
        }

        return scheduleMatchesTeacher(schedule, targetTeacher);
      });
      weeklySchedules = uniqueSchedules(apiSchedules, visibleSchedules);
    } catch (err) {
      setError(err?.response?.data?.message || "Impossible de charger les créneaux pour le PDF.");
      return null;
    }

    const pdfSlots = Array.from(
      buildTimeSlots(weeklySchedules)
    );

    try {
      let exportSchool = school;
      const targetSchoolId = isClassPdf ? selectedClass?.school_id : teacherOverride?.school_id || school?.id;
      if (targetSchoolId && String(targetSchoolId) !== String(school?.id || "")) {
        exportSchool = await getSchoolById(targetSchoolId).catch(() => school);
      }

      return await buildSchedulePdf({
        mode: targetMode,
        school: exportSchool,
        logoUrl: exportSchool?.logo_data_url || resolveLogoUrl(exportSchool?.logo_path),
        className: compactClassName(selectedClass),
        teacherName: !isClassPdf ? teacherName(teacherOverride || selectedTeacher) : "",
        weekLabel: academicWeekLabel(selectedYear, selectedWeek),
        yearLabel: academicYearLabel(selectedYear),
        schedules: weeklySchedules,
        days: dayOptions,
        timeSlots: pdfSlots,
      });
    } catch (err) {
      setError(err?.message || "Impossible de générer le PDF.");
      return null;
    }
  };

  const previewWeeklyPdf = async (targetMode = mode, teacherOverride = null) => {
    setPdfLoading(true);
    try {
      const pdf = await buildWeeklyPdf(targetMode, teacherOverride);
      if (!pdf) {
        return;
      }

      if (pdfPreview.url) {
        URL.revokeObjectURL(pdfPreview.url);
      }
      const url = URL.createObjectURL(pdf.blob);
      setPdfPreview({ open: true, url, filename: pdf.filename, blob: pdf.blob });
    } finally {
      setPdfLoading(false);
    }
  };

  const downloadWeeklyPdf = async (targetMode = mode, teacherOverride = null) => {
    setPdfLoading(true);
    try {
      const pdf = await buildWeeklyPdf(targetMode, teacherOverride);
      if (pdf) {
        downloadBlob(pdf.blob, pdf.filename);
      }
    } finally {
      setPdfLoading(false);
    }
  };

  const teacherCourseCount = (teacher) => weeklyTeacherSchedules.filter((schedule) => scheduleMatchesTeacher(schedule, teacher)).length;

  return (
    <div className="admin-grid">
      <section className="panel schedule-board-panel">
        <div className="schedule-board-header">
          <div>
            <p className="brand-kicker">Emploi du temps</p>
            <h2>{mode === "class" ? "EDT par classe" : "EDT par professeur"}</h2>
            <p className="muted">
              {targetLabel} | {academicWeekLabel(selectedYear, selectedWeek)} | Année scolaire{" "}
              {academicYearLabel(selectedYear)}
            </p>
          </div>
          <div className="schedule-mode-tabs" role="tablist" aria-label="Mode d'affichage">
            <button
              type="button"
              className={mode === "class" ? "active" : ""}
              onClick={() => setMode("class")}
            >
              Par classe
            </button>
            <button
              type="button"
              className={mode === "teacher" ? "active" : ""}
              onClick={() => setMode("teacher")}
            >
              Par professeur
            </button>
          </div>
        </div>

        <div className="schedule-toolbar">
          {mode === "class" ? (
            <label>
              <span>Classe</span>
              <select value={selectedClassId} onChange={(e) => setSelectedClassId(e.target.value)}>
                {classes.map((item) => (
                  <option key={item.id} value={item.id}>
                    {classDisplayName(item)}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <div className="schedule-teacher-picker">
              <label>
                <span>Professeur</span>
                <select value={selectedTeacherId} onChange={(e) => setSelectedTeacherId(e.target.value)}>
                  <option value="">Sélectionner un professeur</option>
                  {teachers.map((teacher) => (
                    <option key={teacher.id} value={teacher.id}>
                      {teacherName(teacher)}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          )}

          <label>
            <span>Année scolaire</span>
            <select value={selectedYear} onChange={(e) => setSelectedYear(e.target.value)}>
              {yearOptions.map((year) => (
                <option key={year} value={year}>
                  {academicYearLabel(year)}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>Semaine</span>
            <select value={selectedWeek} onChange={(e) => setSelectedWeek(e.target.value)}>
              {weekOptions.map((week) => (
                <option key={week} value={week}>
                  {academicWeekLabel(selectedYear, week)}
                </option>
              ))}
            </select>
          </label>

          <button type="button" className="secondary-btn schedule-pdf-btn" onClick={() => previewWeeklyPdf(mode)} disabled={pdfLoading}>
            Voir PDF
          </button>
          <button type="button" className="secondary-btn schedule-pdf-btn" onClick={() => downloadWeeklyPdf(mode)} disabled={pdfLoading}>
            Télécharger PDF
          </button>
        </div>

        {loading && <p className="muted">Chargement...</p>}
        {message && <p className="success-text">{message}</p>}
        {error && <p className="error-text">{error}</p>}
        {!loading && mode === "teacher" && teachers.length === 0 && (
          <p className="muted">Aucun professeur enregistré pour cette école.</p>
        )}

        <div className="schedule-grid-wrap">
          <table className="schedule-week-grid">
            <thead>
              <tr>
                <th>Heure</th>
                {dayOptions.map((day) => (
                  <th key={day.value}>{day.label}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {timeSlots.map((slot) => (
                <tr key={slot.start} className={slot.pause ? "schedule-pause-row" : ""}>
                  <th>{slot.label}</th>
                  {dayOptions.map((day) => {
                    const items = schedules.filter(
                      (schedule) => schedule.day_of_week === day.value && formatTime(schedule.start_time) === slot.start
                    );

                    return (
                      <td key={`${day.value}-${slot.start}`}>
                        <div className="schedule-cell">
                          {slot.pause && <span className="schedule-pause-label">Pause</span>}
                          {items.map((schedule) => (
                            <button
                              key={schedule.id}
                              type="button"
                              className={`schedule-course schedule-course-${courseTone(schedule)}`}
                              onClick={() => openEditModal(schedule)}
                              title="Modifier ce créneau"
                            >
                              <strong>{scheduleSubjectCode(schedule)}</strong>
                              <span>
                                {Number(schedule.is_external) === 1 || schedule.is_external === true
                                  ? schedule.notes || "Autre établissement"
                                  : mode === "class"
                                    ? schedule.teacher_name
                                    : schedule.class_level_name}
                              </span>
                              <small>
                                {formatTime(schedule.start_time)} - {formatTime(schedule.end_time)}
                              </small>
                            </button>
                          ))}
                          {!slot.pause && (
                            <button
                              type="button"
                              className={items.length ? "schedule-add-mini" : "schedule-empty-cell"}
                              onClick={() => openCreateModal(day.value, slot)}
                              title="Ajouter un cours"
                            >
                              {items.length ? "+" : "Ajouter"}
                            </button>
                          )}
                        </div>
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      <section className="panel">
        <div className="panel-header compact-header">
          <div>
            <h3>EDT des enseignants de l'école</h3>
            <p className="muted">
              {academicWeekLabel(selectedYear, selectedWeek)} | Les mêmes créneaux alimentent la vue classe et la vue professeur.
            </p>
          </div>
        </div>
        <form className="schedule-teacher-form" onSubmit={submitTeacher}>
          <label>
            <span>Nom du professeur</span>
            <input
              placeholder="Ex: Ahmed Benali"
              value={teacherForm.full_name}
              onChange={(e) => setTeacherForm({ full_name: e.target.value })}
              required
            />
          </label>
          <button type="submit">Ajouter professeur</button>
        </form>
        <div className="teacher-schedule-grid">
          {teachers.map((teacher) => (
            <article key={teacher.id} className="teacher-schedule-card">
              <div>
                <p className="kpi-label">{teacherName(teacher)}</p>
                <p className="muted">{scheduleCountLabel(teacherCourseCount(teacher))}</p>
              </div>
              <div className="teacher-card-actions">
                <button
                  type="button"
                  className="secondary-btn"
                  onClick={() => viewTeacherSchedule(teacher)}
                >
                  Voir EDT
                </button>
                <button type="button" className="secondary-btn" onClick={() => downloadWeeklyPdf("teacher", teacher)} disabled={pdfLoading}>
                  Télécharger PDF
                </button>
              </div>
            </article>
          ))}
          {teachers.length === 0 && (
            <p className="muted">Aucun professeur enregistré pour cette école.</p>
          )}
        </div>
      </section>

      {modalOpen && (
        <div className="schedule-modal-backdrop" role="presentation">
          <section className="schedule-modal" aria-modal="true" role="dialog">
            <div className="schedule-modal-header">
              <div>
                <p className="brand-kicker">{editingId ? "Modifier" : "Ajouter"}</p>
                <h3>{editingId ? "Modifier le créneau" : "Nouveau cours"}</h3>
              </div>
              <button type="button" className="secondary-btn modal-close-btn" onClick={closeModal}>
                Fermer
              </button>
            </div>

            <form className="form-grid schedule-modal-form" onSubmit={submitSchedule}>
              {mode === "teacher" && (
                <label className="full-field">
                  <span>Type</span>
                  <select
                    value={form.is_external ? "EXTERNAL" : "INTERNAL"}
                    onChange={(e) => {
                      const isExternal = e.target.value === "EXTERNAL";
                      setForm((prev) => ({
                        ...prev,
                        is_external: isExternal,
                        class_level_id: isExternal ? "" : prev.class_level_id,
                        subject_id: isExternal ? "" : prev.subject_id,
                      }));
                      if (!isExternal && form.class_level_id) {
                        loadSubjectsForClass(form.class_level_id, form.subject_id);
                      }
                    }}
                  >
                    <option value="INTERNAL">Cours dans notre établissement</option>
                    <option value="EXTERNAL">Ailleurs</option>
                  </select>
                </label>
              )}

              {mode === "teacher" && !form.is_external && (
                <label>
                  <span>Classe</span>
                  <select
                    value={form.class_level_id}
                    onChange={(e) => {
                      const classLevelId = e.target.value;
                      setForm((prev) => ({ ...prev, class_level_id: classLevelId, subject_id: "" }));
                      loadSubjectsForClass(classLevelId);
                    }}
                    required
                  >
                    <option value="">Choisir une classe</option>
                    {classes.map((item) => (
                      <option key={item.id} value={item.id}>
                        {classDisplayName(item)}
                      </option>
                    ))}
                  </select>
                </label>
              )}

              {!form.is_external && (
                <label>
                  <span>Matière</span>
                  <select
                    value={form.subject_id}
                    onChange={(e) => setForm((prev) => ({ ...prev, subject_id: e.target.value }))}
                    required
                    disabled={!form.class_level_id || subjectsLoading}
                  >
                    <option value="">
                      {subjectsLoading ? "Chargement des matières..." : "Sélectionner une matière"}
                    </option>
                    {subjects.map((subject) => (
                      <option key={subject.id} value={subject.id}>
                        {subjectLabel(subject)}
                      </option>
                    ))}
                  </select>
                </label>
              )}

              {mode === "class" && (
                <label>
                  <span>Professeur</span>
                  <select
                    value={form.teacher_id}
                    onChange={(e) => {
                      const teacherId = e.target.value;
                      const teacher = teachers.find((item) => String(item.id) === String(teacherId));
                      setForm((prev) => ({ ...prev, teacher_id: teacherId, teacher_name: teacherName(teacher) }));
                    }}
                    required
                  >
                    <option value="">Sélectionner un professeur</option>
                    {teachers.map((teacher) => (
                      <option key={teacher.id} value={teacher.id}>
                        {teacherName(teacher)}
                      </option>
                    ))}
                  </select>
                </label>
              )}

              {form.is_external && (
                <label className="full-field">
                  <span>Note</span>
                  <input
                    placeholder="Autre établissement"
                    value={form.notes}
                    onChange={(e) => setForm((prev) => ({ ...prev, notes: e.target.value }))}
                  />
                </label>
              )}

              {!form.is_external && form.class_level_id && !subjectsLoading && subjects.length === 0 && (
                <p className="error-text full-field">Aucune matière active n'est liée à cette classe.</p>
              )}
              <div className="form-actions full-field">
                <button type="submit">{editingId ? "Enregistrer" : "Créer le cours"}</button>
                {editingId && (
                  <button type="button" className="danger-btn" onClick={removeSchedule}>
                    Supprimer
                  </button>
                )}
              </div>
            </form>
          </section>
        </div>
      )}

      {pdfPreview.open && (
        <div className="schedule-modal-backdrop" role="presentation">
          <section className="schedule-modal schedule-pdf-modal" aria-modal="true" role="dialog">
            <div className="schedule-modal-header">
              <div>
                <p className="brand-kicker">Aperçu</p>
                <h3>Voir PDF</h3>
              </div>
              <button type="button" className="secondary-btn modal-close-btn" onClick={closePdfPreview}>
                Fermer
              </button>
            </div>
            <object className="schedule-pdf-preview" data={pdfPreview.url} type="application/pdf">
              <iframe className="schedule-pdf-preview" src={pdfPreview.url} title={pdfPreview.filename} />
            </object>
            <div className="form-actions full-field">
              <button type="button" onClick={() => pdfPreview.blob && downloadBlob(pdfPreview.blob, pdfPreview.filename)}>
                Télécharger
              </button>
            </div>
          </section>
        </div>
      )}
    </div>
  );
}
