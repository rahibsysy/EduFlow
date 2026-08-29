import { useMemo, useState } from "react";
import ProgressBar from "./ProgressBar";

const defaultLessons = [
  {
    id: "intro",
    title: "Course introduction",
    duration: "8 min",
  },
  {
    id: "objectives",
    title: "Learning objectives",
    duration: "12 min",
  },
  {
    id: "core-concepts",
    title: "Core concepts",
    duration: "24 min",
  },
  {
    id: "practice",
    title: "Guided practice",
    duration: "18 min",
  },
  {
    id: "assessment",
    title: "Final assessment",
    duration: "15 min",
  },
];

export default function CourseView({
  title = "EduFlow Course",
  description = "Track each lesson as learners move through the course.",
  lessons = defaultLessons,
}) {
  const [completedLessons, setCompletedLessons] = useState([]);

  const progress = useMemo(() => {
    if (!lessons.length) return 0;
    return (completedLessons.length / lessons.length) * 100;
  }, [completedLessons.length, lessons.length]);

  const isComplete = lessons.length > 0 && completedLessons.length === lessons.length;

  const toggleLesson = (lessonId) => {
    setCompletedLessons((current) =>
      current.includes(lessonId)
        ? current.filter((id) => id !== lessonId)
        : [...current, lessonId]
    );
  };

  const resetProgress = () => {
    setCompletedLessons([]);
  };

  const styles = {
    page: {
      minHeight: "100vh",
      padding: "clamp(20px, 4vw, 48px)",
      background: "#f2f6fb",
      color: "#10203a",
      fontFamily: '"Aptos", "Segoe UI", "Trebuchet MS", sans-serif',
    },
    shell: {
      width: "min(920px, 100%)",
      margin: "0 auto",
      display: "grid",
      gap: 18,
    },
    header: {
      display: "grid",
      gap: 8,
    },
    eyebrow: {
      color: "#15957d",
      fontSize: 12,
      fontWeight: 800,
      letterSpacing: 1.4,
      textTransform: "uppercase",
    },
    title: {
      margin: 0,
      fontSize: "clamp(28px, 5vw, 44px)",
      lineHeight: 1.05,
      letterSpacing: 0,
    },
    description: {
      maxWidth: 640,
      margin: 0,
      color: "#5f6f8d",
      lineHeight: 1.6,
    },
    panel: {
      display: "grid",
      gap: 18,
      padding: "clamp(18px, 3vw, 28px)",
      border: "1px solid #d7e1ef",
      borderRadius: 8,
      background: "linear-gradient(180deg, #ffffff, #f7fbff)",
      boxShadow: "0 14px 34px rgba(14, 42, 82, 0.1)",
    },
    banner: {
      display: isComplete ? "block" : "none",
      padding: "12px 14px",
      border: "1px solid #99f6e4",
      borderRadius: 8,
      background: "#ecfdf5",
      color: "#0f766e",
      fontWeight: 800,
    },
    lessonList: {
      display: "grid",
      gap: 10,
      margin: 0,
      padding: 0,
      listStyle: "none",
    },
    lessonItem: {
      display: "flex",
      alignItems: "center",
      justifyContent: "space-between",
      gap: 12,
      padding: 14,
      border: "1px solid #d7e1ef",
      borderRadius: 8,
      background: "#ffffff",
    },
    lessonLabel: {
      display: "flex",
      alignItems: "center",
      gap: 12,
      minWidth: 0,
      cursor: "pointer",
    },
    checkbox: {
      width: 20,
      height: 20,
      flex: "0 0 auto",
      accentColor: "#15957d",
      cursor: "pointer",
    },
    lessonText: {
      display: "grid",
      gap: 3,
      minWidth: 0,
    },
    lessonTitle: {
      color: "#10203a",
      fontWeight: 800,
      overflowWrap: "anywhere",
    },
    lessonDuration: {
      color: "#5f6f8d",
      fontSize: 13,
    },
    badge: {
      flex: "0 0 auto",
      padding: "5px 9px",
      borderRadius: 999,
      background: "#eef4fb",
      color: "#5f6f8d",
      fontSize: 12,
      fontWeight: 800,
    },
    completedBadge: {
      background: "#ecfdf5",
      color: "#0f766e",
    },
    actions: {
      display: "flex",
      justifyContent: "flex-end",
    },
    button: {
      width: "auto",
      minWidth: 130,
      border: "1px solid #d7e1ef",
      borderRadius: 8,
      padding: "10px 14px",
      background: "#ffffff",
      color: "#10203a",
      font: "inherit",
      fontWeight: 800,
      cursor: "pointer",
    },
  };

  return (
    <main style={styles.page}>
      <section style={styles.shell}>
        <header style={styles.header}>
          <span style={styles.eyebrow}>Course checklist</span>
          <h1 style={styles.title}>{title}</h1>
          <p style={styles.description}>{description}</p>
        </header>

        <div style={styles.panel}>
          <ProgressBar value={progress} />

          <div style={styles.banner} role="status">
            Success! Every lesson is complete.
          </div>

          <ul style={styles.lessonList} aria-label="Course lessons">
            {lessons.map((lesson, index) => {
              const checked = completedLessons.includes(lesson.id);

              return (
                <li key={lesson.id} style={styles.lessonItem}>
                  <label style={styles.lessonLabel}>
                    <input
                      type="checkbox"
                      checked={checked}
                      onChange={() => toggleLesson(lesson.id)}
                      style={styles.checkbox}
                    />
                    <span style={styles.lessonText}>
                      <span style={styles.lessonTitle}>
                        {index + 1}. {lesson.title}
                      </span>
                      <span style={styles.lessonDuration}>{lesson.duration}</span>
                    </span>
                  </label>
                  <span
                    style={{
                      ...styles.badge,
                      ...(checked ? styles.completedBadge : {}),
                    }}
                  >
                    {checked ? "Done" : "Pending"}
                  </span>
                </li>
              );
            })}
          </ul>

          <div style={styles.actions}>
            <button type="button" onClick={resetProgress} style={styles.button}>
              Reset progress
            </button>
          </div>
        </div>
      </section>
    </main>
  );
}
