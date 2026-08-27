"use client";

import {
  Activity, AlertTriangle, BellRing, CalendarCheck2, CheckCircle2, ChevronRight, ClipboardList,
  Dumbbell, Eye, HeartPulse, LineChart, LoaderCircle, Mail, MessageSquareText,
  Pencil, Plus, RefreshCw, ShieldCheck, Smartphone, Target, Trash2, UserRoundCheck, UserRoundX, X,
} from "lucide-react";
import { FormEvent, ReactNode, useState } from "react";
import type {
  IronCoreRole, NewProgressMeasurement, NewTrainerAssignment, NewWorkoutPlan,
  NewWorkoutSession, NotificationDeliveryRecord, NotificationPreferenceRecord,
  ProgressMeasurementRecord, TrainerAssignmentRecord, UpdateNotificationPreference, UpdateProgressMeasurement,
  UpdateWorkoutPlan, WorkoutPlanRecord, WorkoutSessionRecord,
} from "./lib/ironcore-api";
import { zonedLocalDateTimeToIso } from "./lib/gym-time";

type PersonOption = { id: string; name: string; memberCode?: string };

export type CoachingData = {
  readOnly?: boolean;
  timezone: string;
  assignments: TrainerAssignmentRecord[];
  plans: WorkoutPlanRecord[];
  sessions: WorkoutSessionRecord[];
  measurements: ProgressMeasurementRecord[];
  preference: NotificationPreferenceRecord | null;
  deliveries: NotificationDeliveryRecord[];
  members: PersonOption[];
  trainers: PersonOption[];
  actorRole: IronCoreRole;
  loading: boolean;
  error: string | null;
  onReload: () => void;
  onAssign: (input: NewTrainerAssignment) => Promise<void>;
  onEndAssignment: (assignmentId: string, reason: string) => Promise<void>;
  onCreatePlan: (input: NewWorkoutPlan) => Promise<void>;
  onUpdatePlan: (planId: string, input: UpdateWorkoutPlan) => Promise<void>;
  onLogSession: (input: NewWorkoutSession) => Promise<void>;
  onRecordProgress: (input: NewProgressMeasurement) => Promise<void>;
  onUpdateProgress: (measurementId: string, input: UpdateProgressMeasurement) => Promise<void>;
  onDeleteProgress: (measurementId: string, reason: string) => Promise<void>;
  onUpdatePreference: (input: UpdateNotificationPreference) => Promise<void>;
};

function Modal({ title, eyebrow, icon, onClose, children }: { title: string; eyebrow: string; icon: ReactNode; onClose: () => void; children: ReactNode }) {
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-label={title}>
    <button className="modal-scrim" onClick={onClose} aria-label="Close dialog" />
    <section className="modal-card coaching-modal"><div className="modal-heading"><span>{icon}</span><div><p className="eyebrow">{eyebrow}</p><h2>{title}</h2></div><button className="icon-button modal-close-control" type="button" onClick={onClose} aria-label="Close"><X size={18} /></button></div>{children}</section>
  </div>;
}

function displayMetric(row: ProgressMeasurementRecord): string {
  const value = new Intl.NumberFormat("en-GB", { maximumFractionDigits: 3 }).format(row.value_milli / 1000);
  return `${value} ${row.unit === "percent" ? "%" : row.unit}`;
}

function measurementLocalDateTime(value: string, timeZone: string): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone, year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", hourCycle: "h23",
  }).formatToParts(new Date(value));
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((item) => item.type === type)?.value ?? "";
  return `${part("year")}-${part("month")}-${part("day")}T${part("hour")}:${part("minute")}`;
}

function displayTarget(plan: WorkoutPlanRecord["exercises"][number]): string {
  const parts = [
    plan.target_sets ? `${plan.target_sets} sets` : null,
    plan.target_reps_min ? `${plan.target_reps_min}${plan.target_reps_max && plan.target_reps_max !== plan.target_reps_min ? `–${plan.target_reps_max}` : ""} reps` : null,
    plan.target_load_grams !== null ? `${plan.target_load_grams / 1000} kg` : null,
    plan.target_duration_seconds ? `${plan.target_duration_seconds}s` : null,
  ].filter(Boolean);
  return parts.join(" · ") || "Trainer-guided";
}

export function CoachingManagement({ data }: { data: CoachingData }) {
  const [tab, setTab] = useState<"plans" | "progress" | "notifications">("plans");
  const [assignmentModal, setAssignmentModal] = useState(false);
  const [endingAssignment, setEndingAssignment] = useState<TrainerAssignmentRecord | null>(null);
  const [planModal, setPlanModal] = useState(false);
  const [managedPlan, setManagedPlan] = useState<WorkoutPlanRecord | null>(null);
  const [newExercises, setNewExercises] = useState<NewWorkoutPlan["exercises"]>([{ name: "", day_number: 1, sort_order: 1, target_sets: 3, target_reps_min: 8, target_reps_max: 8, rest_seconds: 90 }]);
  const [managedExercises, setManagedExercises] = useState<NewWorkoutPlan["exercises"]>([]);
  const [logPlan, setLogPlan] = useState<WorkoutPlanRecord | null>(null);
  const [viewingMeasurement, setViewingMeasurement] = useState<ProgressMeasurementRecord | null>(null);
  const [editingMeasurement, setEditingMeasurement] = useState<ProgressMeasurementRecord | null>(null);
  const [deletingMeasurement, setDeletingMeasurement] = useState<ProgressMeasurementRecord | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const canAssign = !data.readOnly && ["super_admin", "gym_owner", "gym_manager"].includes(data.actorRole);
  const canCreatePlan = !data.readOnly && ["super_admin", "gym_owner", "gym_manager", "trainer"].includes(data.actorRole);
  const canRecordOthers = !data.readOnly && ["super_admin", "gym_owner", "gym_manager", "trainer"].includes(data.actorRole);
  const canManageMeasurements = !data.readOnly && ["super_admin", "gym_owner", "gym_manager"].includes(data.actorRole);
  const canEditPreferences = !data.readOnly && data.actorRole === "member";
  const activePlans = data.plans.filter((plan) => plan.status === "active");
  const activeMeasurements = data.measurements.filter((row) => row.status === "active");
  const latestWeight = activeMeasurements.find((row) => row.metric === "body_weight");

  const chart = (() => {
    const rows = activeMeasurements.filter((row) => row.metric === "body_weight").slice().reverse().slice(-8);
    if (rows.length < 2) return null;
    const values = rows.map((row) => row.value_milli);
    const min = Math.min(...values); const max = Math.max(...values); const range = Math.max(max - min, 1);
    return rows.map((row, index) => `${(index / (rows.length - 1)) * 100},${76 - ((row.value_milli - min) / range) * 58}`).join(" ");
  })();

  async function act(callback: () => Promise<void>, success: string) {
    setBusy(true); setNotice(null); setActionError(null);
    try { await callback(); setNotice(success); }
    catch (error) { setActionError(error instanceof Error ? error.message : "The action could not be completed."); }
    finally { setBusy(false); }
  }

  async function assignTrainer(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    await act(async () => { await data.onAssign({ trainer_staff_profile_id: String(form.get("trainer_id")), member_id: String(form.get("member_id")), starts_on: String(form.get("starts_on")), notes: String(form.get("notes")) || undefined }); setAssignmentModal(false); }, "Trainer assignment created.");
  }

  async function createPlan(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    await act(async () => {
      await data.onCreatePlan({
        member_id: String(form.get("member_id")), trainer_staff_profile_id: String(form.get("trainer_id")),
        title: String(form.get("title")), goal: String(form.get("goal")) || undefined,
        starts_on: String(form.get("starts_on")), ends_on: String(form.get("ends_on")) || undefined, status: "draft",
        exercises: newExercises.map((exercise, index) => ({ ...exercise, name: exercise.name.trim(), sort_order: index + 1 })),
      });
      setPlanModal(false); setNewExercises([{ name: "", day_number: 1, sort_order: 1, target_sets: 3, target_reps_min: 8, target_reps_max: 8, rest_seconds: 90 }]);
    }, "Workout plan draft created. Review it before activation.");
  }

  function openPlan(plan: WorkoutPlanRecord) {
    setManagedPlan(plan);
    setManagedExercises(plan.exercises.map((exercise) => ({
      name: exercise.name, instructions: exercise.instructions ?? undefined, day_number: exercise.day_number,
      sort_order: exercise.sort_order, target_sets: exercise.target_sets ?? undefined,
      target_reps_min: exercise.target_reps_min ?? undefined, target_reps_max: exercise.target_reps_max ?? undefined,
      target_load_grams: exercise.target_load_grams ?? undefined, target_duration_seconds: exercise.target_duration_seconds ?? undefined,
      rest_seconds: exercise.rest_seconds ?? undefined,
    })));
  }

  async function updatePlan(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!managedPlan) return; const form = new FormData(event.currentTarget); const draft = managedPlan.status === "draft";
    await act(async () => {
      await data.onUpdatePlan(managedPlan.id, {
        title: String(form.get("title")), goal: String(form.get("goal")) || undefined, notes: String(form.get("notes")) || undefined,
        ends_on: String(form.get("ends_on")) || undefined, status: String(form.get("status")) as WorkoutPlanRecord["status"],
        ...(draft ? {
          member_id: String(form.get("member_id")), trainer_staff_profile_id: String(form.get("trainer_id")), starts_on: String(form.get("starts_on")),
          exercises: managedExercises.map((exercise, index) => ({ ...exercise, name: exercise.name.trim(), sort_order: index + 1 })),
        } : {}),
        reason: String(form.get("reason")),
      });
      setManagedPlan(null);
    }, "Workout plan changes saved to the backend.");
  }

  function exerciseFields(rows: NewWorkoutPlan["exercises"], setRows: (rows: NewWorkoutPlan["exercises"]) => void) {
    const update = (index: number, patch: Partial<NewWorkoutPlan["exercises"][number]>) => setRows(rows.map((row, rowIndex) => rowIndex === index ? { ...row, ...patch } : row));
    return <div className="exercise-builder"><div className="panel-title"><div><p className="eyebrow">Exercises</p><strong>Ordered prescription</strong></div><button className="secondary-button" type="button" onClick={() => setRows([...rows, { name: "", day_number: 1, sort_order: rows.length + 1, target_sets: 3, target_reps_min: 8, target_reps_max: 8, rest_seconds: 90 }])}><Plus size={14} /> Add exercise</button></div>{rows.map((exercise, index) => <div className="exercise-editor-row" key={index}><div className="field-pair"><label>Exercise {index + 1}<input required maxLength={160} value={exercise.name} onChange={(event) => update(index, { name: event.target.value })} /></label><label>Day<input type="number" min="1" max="365" required value={exercise.day_number} onChange={(event) => update(index, { day_number: Number(event.target.value) })} /></label></div><label>Instructions<textarea rows={2} maxLength={2000} value={exercise.instructions ?? ""} onChange={(event) => update(index, { instructions: event.target.value || undefined })} /></label><div className="field-trio"><label>Sets<input type="number" min="1" max="100" value={exercise.target_sets ?? ""} onChange={(event) => update(index, { target_sets: Number(event.target.value) || undefined })} /></label><label>Min reps<input type="number" min="1" max="1000" value={exercise.target_reps_min ?? ""} onChange={(event) => update(index, { target_reps_min: Number(event.target.value) || undefined })} /></label><label>Max reps<input type="number" min="1" max="1000" value={exercise.target_reps_max ?? ""} onChange={(event) => update(index, { target_reps_max: Number(event.target.value) || undefined })} /></label></div>{rows.length > 1 && <button className="table-action danger" type="button" onClick={() => setRows(rows.filter((_, rowIndex) => rowIndex !== index))}><Trash2 size={13} /> Remove exercise</button>}</div>)}</div>;
  }

  async function endAssignment(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!endingAssignment) return; const form = new FormData(event.currentTarget);
    await act(async () => {
      await data.onEndAssignment(endingAssignment.id, String(form.get("reason")));
      setEndingAssignment(null);
    }, "Trainer access ended and retained in the audit history.");
  }

  async function logWorkout(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!logPlan?.exercises[0]) return; const form = new FormData(event.currentTarget); const loadKg = Number(form.get("load_kg"));
    await act(async () => {
      await data.onLogSession({
        workout_plan_id: logPlan.id, member_id: canRecordOthers ? logPlan.member_id : undefined,
        performed_at: zonedLocalDateTimeToIso(String(form.get("performed_at")), data.timezone), duration_seconds: Number(form.get("duration_minutes")) * 60,
        notes: String(form.get("notes")) || undefined,
        sets: [{ workout_plan_exercise_id: logPlan.exercises[0].id, set_number: 1, reps: Number(form.get("reps")), load_grams: loadKg > 0 ? Math.round(loadKg * 1000) : undefined, rpe: Number(form.get("rpe")) }],
      });
      setLogPlan(null);
    }, "Workout session recorded as append-only history.");
  }

  async function recordProgress(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const formElement = event.currentTarget; const form = new FormData(formElement); const value = Number(form.get("value"));
    await act(async () => {
      await data.onRecordProgress({ member_id: canRecordOthers ? String(form.get("member_id")) : undefined, metric: String(form.get("metric")) as NewProgressMeasurement["metric"], value_milli: Math.round(value * 1000), unit: String(form.get("unit")) as NewProgressMeasurement["unit"], measured_at: new Date().toISOString(), note: String(form.get("note")) || undefined });
      formElement.reset();
    }, "Progress measurement added without changing earlier history.");
  }

  async function correctProgress(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!editingMeasurement) return;
    const form = new FormData(event.currentTarget); const value = Number(form.get("value"));
    await act(async () => {
      await data.onUpdateProgress(editingMeasurement.id, {
        metric: String(form.get("metric")) as UpdateProgressMeasurement["metric"],
        value_milli: Math.round(value * 1000),
        unit: String(form.get("unit")) as UpdateProgressMeasurement["unit"],
        measured_at: zonedLocalDateTimeToIso(String(form.get("measured_at")), data.timezone),
        note: String(form.get("note")) || undefined,
        reason: String(form.get("reason")),
      });
      setEditingMeasurement(null);
    }, "Measurement corrected. The original remains in history.");
  }

  async function deleteProgress(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!deletingMeasurement) return;
    const form = new FormData(event.currentTarget);
    await act(async () => {
      await data.onDeleteProgress(deletingMeasurement.id, String(form.get("reason")));
      setDeletingMeasurement(null);
    }, "Measurement removed from live progress and retained in history.");
  }

  async function updatePreferences(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    await act(() => data.onUpdatePreference({
      email_enabled: form.get("email_enabled") === "on", sms_enabled: form.get("sms_enabled") === "on", push_enabled: form.get("push_enabled") === "on",
      class_reminders_enabled: form.get("class_reminders_enabled") === "on", workout_reminders_enabled: form.get("workout_reminders_enabled") === "on",
      payment_reminders_enabled: form.get("payment_reminders_enabled") === "on", marketing_enabled: form.get("marketing_enabled") === "on",
      quiet_hours_start: String(form.get("quiet_hours_start")) || null, quiet_hours_end: String(form.get("quiet_hours_end")) || null,
    }), "Notification preferences saved.");
  }

  return <section className="coaching-workspace">
    <div className="live-scope-banner"><ShieldCheck size={18} /><div><strong>{data.readOnly ? "Representative coaching preview" : "Assignment-bound coaching workspace"}</strong><small>{data.readOnly ? "This sample is read-only. Sign in to manage real coaching records." : "Plans, performance and progress stay inside the selected gym. Trainer access is verified from an active assignment by the API."}</small></div>{!data.readOnly && <button className="icon-button" onClick={data.onReload} aria-label="Refresh coaching data"><RefreshCw size={16} /></button>}</div>
    {data.error && <div className="form-error" role="alert">{data.error}</div>}{actionError && <div className="form-error" role="alert">{actionError}</div>}{notice && <div className="form-notice" role="status">{notice}</div>}

    <div className="coaching-metrics">
      <article className="panel"><span className="coaching-icon violet"><ClipboardList size={19} /></span><div><small>Active plans</small><strong>{activePlans.length}</strong><p>Assignment protected</p></div></article>
      <article className="panel"><span className="coaching-icon green"><CheckCircle2 size={19} /></span><div><small>Logged sessions</small><strong>{data.sessions.length}</strong><p>Append-only evidence</p></div></article>
      <article className="panel"><span className="coaching-icon amber"><HeartPulse size={19} /></span><div><small>Latest weight</small><strong>{latestWeight ? displayMetric(latestWeight) : "—"}</strong><p>Exact thousandths</p></div></article>
    </div>

    <div className="engagement-tabs" role="tablist"><button className={tab === "plans" ? "active" : ""} onClick={() => setTab("plans")}><Dumbbell size={16} /> Plans & sessions</button><button className={tab === "progress" ? "active" : ""} onClick={() => setTab("progress")}><LineChart size={16} /> Progress</button><button className={tab === "notifications" ? "active" : ""} onClick={() => setTab("notifications")}><BellRing size={16} /> Notifications</button></div>

    {tab === "plans" && <>
      <div className="module-heading"><div><p className="eyebrow">Structured coaching</p><h2>Workout plans</h2><p>Ordered prescriptions and completed sets remain linked to the correct member and trainer.</p></div><div className="heading-actions">{canAssign && <button className="secondary-button" onClick={() => setAssignmentModal(true)}><UserRoundCheck size={16} /> Assign trainer</button>}{canCreatePlan && <button className="primary-button" onClick={() => setPlanModal(true)}><Plus size={16} /> New plan</button>}</div></div>
      {canAssign && data.assignments.some((row) => row.status === "active") && <div className="assignment-list panel"><div><p className="eyebrow">Current access</p><strong>Active trainer assignments</strong></div>{data.assignments.filter((row) => row.status === "active").map((row) => <div className="assignment-row" key={row.id}><span className="coaching-icon soft"><UserRoundCheck size={16} /></span><div><strong>{row.trainer?.name ?? "Assigned trainer"} → {row.member?.name ?? "Member"}</strong><small>{row.member?.member_code ? `Member Code ${row.member.member_code} · ` : ""}Since {row.starts_on}{row.ends_on ? ` · ends ${row.ends_on}` : ""}</small></div><button className="secondary-button" onClick={() => setEndingAssignment(row)}><UserRoundX size={15} /> End access</button></div>)}</div>}
      <div className="plan-grid">{data.plans.map((plan) => <article className="panel workout-plan-card" key={plan.id}><div className="plan-card-top"><span className="coaching-icon violet"><Target size={19} /></span><span className={`status ${plan.status}`}>{plan.status}</span></div><p className="eyebrow">{plan.member?.name ?? "Member plan"}{plan.member?.member_code ? ` · Member Code ${plan.member.member_code}` : ""}</p><h3>{plan.title}</h3><p>{plan.goal ?? "Structured trainer-led progression."}</p><div className="plan-meta"><span><UserRoundCheck size={14} />{plan.trainer?.name ?? "Assigned trainer"}</span><span><CalendarCheck2 size={14} />From {plan.starts_on}</span></div><div className="exercise-list">{plan.exercises.map((exercise) => <div key={exercise.id}><span>{exercise.sort_order}</span><div><strong>{exercise.name}</strong><small>{displayTarget(exercise)}</small></div><ChevronRight size={15} /></div>)}</div><div className="plan-card-actions">{!data.readOnly && plan.status === "active" && <button className="primary-button plan-log-button" onClick={() => setLogPlan(plan)}><Activity size={16} /> Log workout</button>}{!data.readOnly && canCreatePlan && <button className="secondary-button" onClick={() => openPlan(plan)}><Eye size={15} /> View / manage</button>}</div></article>)}</div>
      {!data.loading && !data.plans.length && <div className="panel table-state">No workout plans are visible for this assignment scope.</div>}
    </>}

    {tab === "progress" && <div className="progress-layout">
      <article className="panel progress-chart-card">
        <div className="panel-title"><div><p className="eyebrow">Body weight</p><h3>Progress trend</h3></div><span>History preserved</span></div>
        {chart ? <svg viewBox="0 0 100 84" preserveAspectRatio="none" role="img" aria-label="Member body weight trend"><defs><linearGradient id="progressFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#7c3aed" stopOpacity=".28" /><stop offset="100%" stopColor="#7c3aed" stopOpacity="0" /></linearGradient></defs><polygon points={`0,76 ${chart} 100,76`} fill="url(#progressFill)" /><polyline points={chart} fill="none" stroke="#7c3aed" strokeWidth="2" vectorEffect="non-scaling-stroke" /></svg> : <div className="table-state">Add at least two active weight measurements to display a trend.</div>}
        <div className="measurement-list">
          {data.measurements.slice(0, 12).map((row) => <div className={`measurement-row ${row.status}`} key={row.id}>
            <span className="coaching-icon soft"><HeartPulse size={15} /></span>
            <div className="measurement-copy"><strong>{row.metric.replaceAll("_", " ")}</strong><small>{row.member?.name ?? "Member"} · {new Date(row.measured_at).toLocaleDateString("en-GB")}</small></div>
            <div className="measurement-value"><b>{displayMetric(row)}</b><span className={`measurement-status ${row.status}`}>{row.status}</span></div>
            <div className="measurement-actions">
              <button className="icon-button" type="button" onClick={() => setViewingMeasurement(row)} aria-label={`View ${row.metric.replaceAll("_", " ")} measurement`}><Eye size={15} /></button>
              {canManageMeasurements && row.status === "active" && <><button className="icon-button" type="button" onClick={() => setEditingMeasurement(row)} aria-label={`Edit ${row.metric.replaceAll("_", " ")} measurement`}><Pencil size={15} /></button><button className="icon-button measurement-delete-trigger" type="button" onClick={() => setDeletingMeasurement(row)} aria-label={`Delete ${row.metric.replaceAll("_", " ")} measurement`}><Trash2 size={15} /></button></>}
            </div>
          </div>)}
          {!data.loading && !data.measurements.length && <div className="table-state">No measurements recorded yet.</div>}
        </div>
      </article>
      {!data.readOnly && <article className="panel progress-entry">
        <p className="eyebrow">New measurement</p><h3>Record progress</h3><p>Save an exact measurement to this gym&apos;s protected history.</p>
        <form className="progress-form" onSubmit={recordProgress}>
          {canRecordOthers && <label><span>Member</span><select name="member_id" required defaultValue=""><option value="" disabled>Select member</option>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}{member.memberCode ? ` · Member Code ${member.memberCode}` : ""}</option>)}</select></label>}
          <div className="field-pair"><label><span>Metric</span><select name="metric" defaultValue="body_weight"><option value="body_weight">Body weight</option><option value="body_fat">Body fat</option><option value="waist">Waist</option><option value="chest">Chest</option><option value="hips">Hips</option><option value="biceps">Biceps</option><option value="thigh">Thigh</option><option value="custom">Custom</option></select></label><label><span>Unit</span><select name="unit" defaultValue="kg"><option value="kg">kg</option><option value="percent">%</option><option value="cm">cm</option><option value="count">count</option><option value="seconds">seconds</option><option value="metres">metres</option><option value="custom">custom</option></select></label></div>
          <label><span>Value</span><input name="value" type="number" step="0.001" required placeholder="82.450" /></label>
          <label><span>Note <small>optional</small></span><textarea name="note" rows={2} maxLength={2000} /></label>
          <button className="primary-button" disabled={busy} type="submit">{busy ? <LoaderCircle className="spin" size={16} /> : <Plus size={16} />} Add measurement</button>
        </form>
      </article>}
    </div>}

    {tab === "notifications" && <div className="notification-layout"><article className="panel preference-card"><p className="eyebrow">Member communication</p><h3>Notification preferences</h3><p>Email, SMS and push adapters run through tenant-bound Redis jobs. Provider secrets never enter the browser.</p>{canEditPreferences && data.preference ? <form onSubmit={updatePreferences}><PreferenceToggle name="email_enabled" label="Email" detail="Plan and service updates" icon={<Mail size={17} />} checked={data.preference.email_enabled} /><PreferenceToggle name="sms_enabled" label="SMS" detail="Time-sensitive reminders" icon={<MessageSquareText size={17} />} checked={data.preference.sms_enabled} /><PreferenceToggle name="push_enabled" label="Push" detail="PWA and future native apps" icon={<Smartphone size={17} />} checked={data.preference.push_enabled} /><div className="preference-divider" /><PreferenceToggle name="class_reminders_enabled" label="Class reminders" detail="Upcoming bookings" checked={data.preference.class_reminders_enabled} /><PreferenceToggle name="workout_reminders_enabled" label="Workout reminders" detail="Assigned plans" checked={data.preference.workout_reminders_enabled} /><PreferenceToggle name="payment_reminders_enabled" label="Payment reminders" detail="Invoices and collection" checked={data.preference.payment_reminders_enabled} /><PreferenceToggle name="marketing_enabled" label="Marketing" detail="Explicit opt-in only" checked={data.preference.marketing_enabled} /><div className="field-pair"><label>Quiet from<input name="quiet_hours_start" type="time" defaultValue={data.preference.quiet_hours_start ?? "22:00"} /></label><label>Quiet until<input name="quiet_hours_end" type="time" defaultValue={data.preference.quiet_hours_end ?? "07:00"} /></label></div><button className="primary-button" disabled={busy} type="submit">Save preferences</button></form> : <div className="notification-safe-note"><ShieldCheck size={18} /><span><strong>Preference changes are member-controlled</strong><small>Gym teams see delivery state without seeing encrypted destinations.</small></span></div>}</article><article className="panel delivery-card"><div className="panel-title"><div><p className="eyebrow">Queued evidence</p><h3>Recent deliveries</h3></div><span>{data.deliveries.length} events</span></div><div className="delivery-list">{data.deliveries.map((delivery) => <div key={delivery.id}><span className={`channel-icon ${delivery.channel}`}>{delivery.channel === "email" ? <Mail size={16} /> : delivery.channel === "sms" ? <MessageSquareText size={16} /> : <Smartphone size={16} />}</span><div><strong>{delivery.template_key.replaceAll("_", " ")}</strong><small>{new Date(delivery.scheduled_at).toLocaleString("en-GB")}</small></div><span className={`status ${delivery.status}`}>{delivery.status}</span></div>)}{!data.deliveries.length && <div className="table-state">No notification deliveries recorded yet.</div>}</div></article></div>}

    {assignmentModal && <Modal title="Assign a trainer" eyebrow="Coaching access boundary" icon={<UserRoundCheck size={20} />} onClose={() => setAssignmentModal(false)}><form onSubmit={assignTrainer}><label>Trainer<select name="trainer_id" required defaultValue=""><option value="" disabled>Select trainer</option>{data.trainers.map((trainer) => <option key={trainer.id} value={trainer.id}>{trainer.name}</option>)}</select></label><label>Member<select name="member_id" required defaultValue=""><option value="" disabled>Select member</option>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}{member.memberCode ? ` · Member Code ${member.memberCode}` : ""}</option>)}</select></label><label>Starts<input name="starts_on" type="date" required /></label><label>Notes<textarea name="notes" rows={2} maxLength={2000} /></label><div className="modal-note"><ShieldCheck size={16} />This assignment becomes the server-authoritative trainer access boundary.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setAssignmentModal(false)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Create assignment</button></div></form></Modal>}
    {endingAssignment && <Modal title="End trainer access" eyebrow="Audited access transition" icon={<UserRoundX size={20} />} onClose={() => setEndingAssignment(null)}><form onSubmit={endAssignment}><p className="booking-summary"><strong>{endingAssignment.trainer?.name ?? "Assigned trainer"}</strong> will immediately lose coaching access to <strong>{endingAssignment.member?.name ?? "this member"}</strong>. Historical plans and sessions remain intact.</p><label>Reason<textarea name="reason" rows={3} maxLength={500} required placeholder="Trainer changed or coaching engagement ended" /></label><div className="modal-note"><ShieldCheck size={16} />The API records the actor, reason and before/after state. This assignment cannot be reactivated.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setEndingAssignment(null)}>Keep access</button><button className="primary-button" disabled={busy} type="submit">End access</button></div></form></Modal>}
    {viewingMeasurement && <Modal title="Measurement details" eyebrow="Protected progress history" icon={<Eye size={20} />} onClose={() => setViewingMeasurement(null)}><div className="measurement-detail"><div><span>Member</span><strong>{viewingMeasurement.member?.name ?? "Member"}</strong></div><div><span>Member Code</span><strong>{viewingMeasurement.member?.member_code ?? "—"}</strong></div><div><span>Metric</span><strong>{viewingMeasurement.metric.replaceAll("_", " ")}</strong></div><div><span>Value</span><strong>{displayMetric(viewingMeasurement)}</strong></div><div><span>Measured</span><strong>{new Date(viewingMeasurement.measured_at).toLocaleString("en-GB")}</strong></div><div><span>Status</span><strong className={`measurement-status ${viewingMeasurement.status}`}>{viewingMeasurement.status}</strong></div><div className="measurement-detail-note"><span>Note</span><strong>{viewingMeasurement.note || "No note recorded"}</strong></div></div><div className="modal-actions"><button className="primary-button" type="button" onClick={() => setViewingMeasurement(null)}>Close</button></div></Modal>}
    {editingMeasurement && <Modal title="Edit measurement" eyebrow="Create a corrected revision" icon={<Pencil size={20} />} onClose={() => setEditingMeasurement(null)}><form className="progress-form" onSubmit={correctProgress}><div className="measurement-member-summary"><HeartPulse size={17} /><span><small>Member</small><strong>{editingMeasurement.member?.name ?? "Member"}</strong></span></div><div className="field-pair"><label><span>Metric</span><select name="metric" defaultValue={editingMeasurement.metric}><option value="body_weight">Body weight</option><option value="body_fat">Body fat</option><option value="waist">Waist</option><option value="chest">Chest</option><option value="hips">Hips</option><option value="biceps">Biceps</option><option value="thigh">Thigh</option><option value="custom">Custom</option></select></label><label><span>Unit</span><select name="unit" defaultValue={editingMeasurement.unit}><option value="kg">kg</option><option value="percent">%</option><option value="cm">cm</option><option value="count">count</option><option value="seconds">seconds</option><option value="metres">metres</option><option value="custom">custom</option></select></label></div><label><span>Value</span><input name="value" type="number" step="0.001" required defaultValue={editingMeasurement.value_milli / 1000} /></label><label><span>Measured at</span><input name="measured_at" type="datetime-local" required defaultValue={measurementLocalDateTime(editingMeasurement.measured_at, data.timezone)} /></label><label><span>Note <small>optional</small></span><textarea name="note" rows={2} maxLength={2000} defaultValue={editingMeasurement.note ?? ""} /></label><label><span>Reason for edit</span><textarea name="reason" rows={3} maxLength={500} required placeholder="Explain what was corrected" /></label><div className="modal-note"><ShieldCheck size={16} />The original measurement remains unchanged in history. This saves a new corrected revision.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setEditingMeasurement(null)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Save correction</button></div></form></Modal>}
    {deletingMeasurement && <Modal title="Delete measurement" eyebrow="Safe historical void" icon={<AlertTriangle size={20} />} onClose={() => setDeletingMeasurement(null)}><form className="progress-form" onSubmit={deleteProgress}><p className="booking-summary">Remove <strong>{displayMetric(deletingMeasurement)}</strong> ({deletingMeasurement.metric.replaceAll("_", " ")}) for <strong>{deletingMeasurement.member?.name ?? "this member"}</strong> from live progress?</p><label><span>Reason for deletion</span><textarea name="reason" rows={3} maxLength={500} required placeholder="Duplicate entry or measurement recorded in error" /></label><div className="modal-note warning"><AlertTriangle size={16} />The record will disappear from live charts, but its values, actor and reason stay in protected history.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setDeletingMeasurement(null)}>Keep measurement</button><button className="danger-button" disabled={busy} type="submit">Delete safely</button></div></form></Modal>}
    {planModal && <Modal title="Create workout plan" eyebrow="Ordered member prescription" icon={<ClipboardList size={20} />} onClose={() => setPlanModal(false)}><form onSubmit={createPlan}>{canRecordOthers && <label>Member<select name="member_id" required defaultValue=""><option value="" disabled>Select member</option>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}{member.memberCode ? ` · Member Code ${member.memberCode}` : ""}</option>)}</select></label>}<label>Trainer<select name="trainer_id" required defaultValue={data.trainers[0]?.id ?? ""}><option value="" disabled>Select trainer</option>{data.trainers.map((trainer) => <option key={trainer.id} value={trainer.id}>{trainer.name}</option>)}</select></label><label>Plan title<input name="title" required maxLength={160} placeholder="12-week strength foundation" /></label><label>Goal<input name="goal" maxLength={240} placeholder="Build consistent full-body strength" /></label><div className="field-pair"><label>Starts<input name="starts_on" type="date" required /></label><label>Ends<input name="ends_on" type="date" /></label></div>{exerciseFields(newExercises, setNewExercises)}<div className="modal-note"><ShieldCheck size={16} />The plan starts as a draft so exercises, member and trainer can be reviewed before activation.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setPlanModal(false)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Create draft</button></div></form></Modal>}
    {managedPlan && <Modal title={`Manage ${managedPlan.title}`} eyebrow="Audited workout lifecycle" icon={<ClipboardList size={20} />} onClose={() => setManagedPlan(null)}>{["completed", "cancelled"].includes(managedPlan.status) ? <><div className="measurement-detail"><div><span>Member</span><strong>{managedPlan.member?.name ?? "Member"}</strong></div><div><span>Trainer</span><strong>{managedPlan.trainer?.name ?? "Trainer"}</strong></div><div><span>Status</span><strong>{managedPlan.status}</strong></div><div><span>Dates</span><strong>{managedPlan.starts_on} – {managedPlan.ends_on ?? "ongoing"}</strong></div></div><div className="modal-note"><ShieldCheck size={16} />Completed and archived plans are immutable. Sessions and exercises remain available as historical evidence.</div><div className="modal-actions"><button className="primary-button" onClick={() => setManagedPlan(null)}>Close</button></div></> : <form onSubmit={updatePlan}><label>Plan title<input name="title" required maxLength={160} defaultValue={managedPlan.title} /></label><label>Goal<input name="goal" maxLength={240} defaultValue={managedPlan.goal ?? ""} /></label><label>Notes<textarea name="notes" rows={2} maxLength={4000} defaultValue={managedPlan.notes ?? ""} /></label><div className="field-pair"><label>Member<select name="member_id" required defaultValue={managedPlan.member_id} disabled={managedPlan.status !== "draft"}>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select></label><label>Trainer<select name="trainer_id" required defaultValue={managedPlan.trainer_staff_profile_id} disabled={managedPlan.status !== "draft"}>{data.trainers.map((trainer) => <option key={trainer.id} value={trainer.id}>{trainer.name}</option>)}</select></label></div><div className="field-trio"><label>Starts<input name="starts_on" type="date" required defaultValue={managedPlan.starts_on} disabled={managedPlan.status !== "draft"} /></label><label>Ends<input name="ends_on" type="date" defaultValue={managedPlan.ends_on ?? ""} /></label><label>Status<select name="status" defaultValue={managedPlan.status}>{managedPlan.status === "draft" ? <><option value="draft">Draft</option><option value="active">Activate</option><option value="cancelled">Archive</option></> : <><option value="active">Active</option><option value="completed">Complete</option><option value="cancelled">Archive</option></>}</select></label></div>{managedPlan.status === "draft" ? exerciseFields(managedExercises, setManagedExercises) : <div className="exercise-list">{managedPlan.exercises.map((exercise) => <div key={exercise.id}><span>{exercise.sort_order}</span><div><strong>{exercise.name}</strong><small>{displayTarget(exercise)}</small></div></div>)}</div>}<label>Audit reason<textarea name="reason" required minLength={5} maxLength={1000} placeholder="Explain this workout plan change" /></label><div className="modal-note"><ShieldCheck size={16} />Draft exercises can be changed. Once active, prescriptions and completed workout evidence stay protected; archive creates a terminal status instead of deleting history.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setManagedPlan(null)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Save plan</button></div></form>}</Modal>}
    {logPlan && <Modal title={`Log ${logPlan.title}`} eyebrow="Append-only completion" icon={<Activity size={20} />} onClose={() => setLogPlan(null)}><form onSubmit={logWorkout}><p className="booking-summary">{logPlan.exercises[0]?.name} · {displayTarget(logPlan.exercises[0])}</p><label>Performed at<input name="performed_at" type="datetime-local" required /></label><div className="field-trio"><label>Reps<input name="reps" type="number" min="0" max="10000" required /></label><label>Load kg<input name="load_kg" type="number" min="0" max="1000" step="0.001" /></label><label>RPE<input name="rpe" type="number" min="1" max="10" defaultValue="7" required /></label></div><label>Duration minutes<input name="duration_minutes" type="number" min="1" max="1440" required /></label><label>Notes<textarea name="notes" rows={2} maxLength={4000} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setLogPlan(null)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Record session</button></div></form></Modal>}
  </section>;
}

function PreferenceToggle({ name, label, detail, icon, checked }: { name: string; label: string; detail: string; icon?: ReactNode; checked: boolean }) {
  return <label className="preference-toggle">{icon && <span>{icon}</span>}<span><strong>{label}</strong><small>{detail}</small></span><input name={name} type="checkbox" defaultChecked={checked} /></label>;
}
