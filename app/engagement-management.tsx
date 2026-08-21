"use client";

import QRCode from "qrcode";
import {
  CalendarPlus, Camera, CheckCircle2, Clock3, DoorOpen, Dumbbell, ListOrdered,
  LogOut, QrCode, RefreshCw, ShieldCheck, TicketCheck, UserCheck, UsersRound, X,
} from "lucide-react";
import { FormEvent, useEffect, useMemo, useRef, useState } from "react";
import type { AttendanceRecord, ClassBookingRecord, ClassSessionRecord, IronCoreRole, NewClassSession } from "./lib/ironcore-api";
import { formatGymDateTime, formatGymTime, zonedLocalDateTimeToIso } from "./lib/gym-time";
import { QrCameraScanner } from "./qr-camera-scanner";

type Option = { id: string; name: string };
type MemberOption = Option & { number: string };

export type EngagementData = {
  attendance: AttendanceRecord[];
  sessions: ClassSessionRecord[];
  bookings: ClassBookingRecord[];
  members: MemberOption[];
  branches: Option[];
  trainers: Option[];
  timezone: string;
  actorRole: IronCoreRole;
  readOnly?: boolean;
  loading: boolean;
  error: string | null;
  onReload: () => void;
  onCheckIn: (input: { branchId: string; method: "member_code" | "qr"; accessValue: string }) => Promise<void>;
  onCheckOut: (attendanceId: string) => Promise<void>;
  onCreateSession: (input: NewClassSession) => Promise<void>;
  onBook: (sessionId: string, memberId?: string) => Promise<ClassBookingRecord>;
  onCancel: (bookingId: string, reason: string) => Promise<void>;
  onAttend: (bookingId: string) => Promise<void>;
  onIssueCredential: (memberId: string) => Promise<string>;
};

function Modal({ title, eyebrow, icon, onClose, children }: { title: string; eyebrow: string; icon: React.ReactNode; onClose: () => void; children: React.ReactNode }) {
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-label={title}>
    <button className="modal-scrim" onClick={onClose} aria-label="Close dialog" />
    <section className="modal-card engagement-modal">
      <div className="modal-heading"><span>{icon}</span><div><p className="eyebrow">{eyebrow}</p><h2>{title}</h2></div><button className="icon-button" onClick={onClose} aria-label="Close"><X size={18} /></button></div>
      {children}
    </section>
  </div>;
}

export function EngagementManagement({ data }: { data: EngagementData }) {
  const [tab, setTab] = useState<"attendance" | "classes">(data.actorRole === "member" ? "classes" : "attendance");
  const [classModal, setClassModal] = useState(false);
  const [bookingSession, setBookingSession] = useState<ClassSessionRecord | null>(null);
  const [credentialModal, setCredentialModal] = useState(false);
  const [credential, setCredential] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [scannerOpen, setScannerOpen] = useState(false);
  const [checkInBranch, setCheckInBranch] = useState(data.branches[0]?.id ?? "");
  const qrCanvas = useRef<HTMLCanvasElement>(null);
  const memberCodeInput = useRef<HTMLInputElement>(null);

  const present = data.attendance.filter((row) => row.status === "checked_in");
  // The API supplies a bounded current schedule window; rendering remains
  // deterministic and does not make access decisions from the browser clock.
  const upcoming = data.sessions.filter((row) => row.status === "scheduled");
  const waitlisted = data.bookings.filter((row) => row.status === "waitlisted");
  const canCheckIn = !data.readOnly && ["super_admin", "gym_owner", "gym_manager", "receptionist"].includes(data.actorRole);
  const canManageClasses = !data.readOnly && ["super_admin", "gym_owner", "gym_manager"].includes(data.actorRole);
  const canBookOthers = !data.readOnly && ["super_admin", "gym_owner", "gym_manager", "receptionist"].includes(data.actorRole);
  const canMarkAttendance = !data.readOnly && data.actorRole !== "member";
  const defaultBranch = data.branches[0]?.id ?? "";
  const selectedCheckInBranch = data.branches.some((branch) => branch.id === checkInBranch) ? checkInBranch : defaultBranch;

  useEffect(() => {
    if (!credential || !qrCanvas.current) return;
    // The opaque credential is rendered locally and never stored in browser
    // storage; the server persists only its tenant-scoped SHA-256 digest.
    void QRCode.toCanvas(qrCanvas.current, credential, { width: 210, margin: 1, color: { dark: "#24222c", light: "#ffffff" } });
  }, [credential]);

  async function act(callback: () => Promise<void>, success: string) {
    setBusy(true); setActionError(null); setNotice(null);
    try { await callback(); setNotice(success); }
    catch (error) { setActionError(error instanceof Error ? error.message : "The action could not be completed."); }
    finally { setBusy(false); }
  }

  async function checkIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const formElement = event.currentTarget; const form = new FormData(formElement);
    const accessValue = String(form.get("access_value")).trim();
    if (!/^\d{4,6}$/.test(accessValue)) {
      setNotice(null); setActionError("Enter the member's 4–6 digit Member Code."); memberCodeInput.current?.focus(); return;
    }
    await act(async () => { await data.onCheckIn({ branchId: String(form.get("branch_id")), method: "member_code", accessValue }); formElement.reset(); }, "Member checked in successfully.");
  }

  async function checkInFromCamera(credentialValue: string) {
    if (!selectedCheckInBranch) throw new Error("Select a branch before scanning.");
    setBusy(true); setActionError(null); setNotice(null);
    try {
      await data.onCheckIn({ branchId: selectedCheckInBranch, method: "qr", accessValue: credentialValue });
      setNotice("Member checked in successfully by secure QR.");
    } catch (error) {
      const message = error instanceof Error ? error.message : "This QR code could not be checked in.";
      setActionError(message);
      throw error;
    } finally {
      setBusy(false);
    }
  }

  async function createClass(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    await act(async () => {
      await data.onCreateSession({
        branch_id: String(form.get("branch_id")),
        trainer_staff_profile_id: String(form.get("trainer_id")) || undefined,
        title: String(form.get("title")), description: String(form.get("description")) || undefined,
        starts_at: zonedLocalDateTimeToIso(String(form.get("starts_at")), data.timezone),
        ends_at: zonedLocalDateTimeToIso(String(form.get("ends_at")), data.timezone),
        capacity: Number(form.get("capacity")), waitlist_enabled: form.get("waitlist_enabled") === "on",
      });
      setClassModal(false);
    }, "Class added to the schedule.");
  }

  async function createBooking(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!bookingSession) return; const form = new FormData(event.currentTarget);
    setBusy(true); setActionError(null); setNotice(null);
    try {
      const booking = await data.onBook(bookingSession.id, canBookOthers ? String(form.get("member_id")) : undefined);
      setBookingSession(null);
      setNotice(booking.status === "waitlisted" ? "Class is full; the member joined the FIFO waitlist." : "Class booking confirmed.");
    } catch (error) {
      setActionError(error instanceof Error ? error.message : "The booking could not be completed.");
    } finally {
      setBusy(false);
    }
  }

  async function issueCredential(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    await act(async () => { setCredential(await data.onIssueCredential(String(form.get("member_id")))); }, "A new QR credential was issued. The previous credential is revoked.");
  }

  const bookingBySession = useMemo(() => {
    const grouped = new Map<string, ClassBookingRecord[]>();
    for (const booking of data.bookings) grouped.set(booking.class_session_id, [...(grouped.get(booking.class_session_id) ?? []), booking]);
    return grouped;
  }, [data.bookings]);

  return <section className="engagement-workspace">
    <div className="live-scope-banner"><ShieldCheck size={18} /><div><strong>{data.readOnly ? "Read-only engagement preview" : "Live tenant engagement workspace"}</strong><small>{data.readOnly ? "Representative attendance and class records do not call the API." : "Every scan, roster and booking remains bound to the selected gym and branch. QR secrets are never stored in plaintext."}</small></div>{!data.readOnly && <button className="icon-button" onClick={data.onReload} aria-label="Refresh engagement data"><RefreshCw size={16} /></button>}</div>
    {data.error && <div className="form-error" role="alert">{data.error}</div>}
    {actionError && <div className="form-error" role="alert">{actionError}</div>}
    {notice && <div className="form-notice" role="status">{notice}</div>}

    <div className="engagement-metrics">
      <article className="panel engagement-stat"><span className="engagement-icon green"><DoorOpen size={19} /></span><div><small>Present now</small><strong>{present.length}</strong><p>Across selected-gym branches</p></div></article>
      <article className="panel engagement-stat"><span className="engagement-icon violet"><Dumbbell size={19} /></span><div><small>Upcoming classes</small><strong>{upcoming.length}</strong><p>Within the current schedule</p></div></article>
      <article className="panel engagement-stat"><span className="engagement-icon amber"><ListOrdered size={19} /></span><div><small>Waitlisted</small><strong>{waitlisted.length}</strong><p>FIFO promotion protected</p></div></article>
    </div>

    <div className="engagement-tabs" role="tablist"><button className={tab === "attendance" ? "active" : ""} onClick={() => setTab("attendance")}><UserCheck size={16} /> Attendance</button><button className={tab === "classes" ? "active" : ""} onClick={() => setTab("classes")}><CalendarPlus size={16} /> Classes & bookings</button></div>

    {tab === "attendance" && <>
      {canCheckIn && <article className="panel checkin-console"><div className="checkin-copy"><span className="engagement-icon violet"><QrCode size={21} /></span><div><p className="eyebrow">Front desk admission</p><h2>Scan QR or enter Member Code</h2><p>Camera scans use the secure QR token. The visible Member Code is a separate manual fallback; the API checks membership and branch access before admission.</p></div></div><form onSubmit={checkIn}><label>Branch<select name="branch_id" value={selectedCheckInBranch} onChange={(event) => setCheckInBranch(event.target.value)} required>{data.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label><label>Member Code<input ref={memberCodeInput} name="access_value" required autoComplete="off" inputMode="numeric" pattern="[0-9]{4,6}" minLength={4} maxLength={6} placeholder="e.g. 104287" /></label><button className="primary-button" disabled={busy} type="submit"><TicketCheck size={16} /> Check in with code</button><button className="secondary-button" disabled={busy || !selectedCheckInBranch} type="button" onClick={() => setScannerOpen(true)}><Camera size={16} /> Scan QR with Camera</button><button className="secondary-button" type="button" onClick={() => { setCredential(null); setCredentialModal(true); }}><QrCode size={16} /> Issue member QR</button></form></article>}
      <article className="panel engagement-table"><div className="panel-title"><div><p className="eyebrow">Live presence</p><h3>Today&apos;s attendance</h3></div><small>{data.attendance.length} records</small></div>{data.loading ? <div className="table-state">Loading attendance…</div> : <div className="table-scroll"><table className="data-table engagement-data-table"><thead><tr><th>Member</th><th>Branch</th><th>Method</th><th>Checked in</th><th>Status</th><th aria-label="Actions" /></tr></thead><tbody>{data.attendance.map((row) => <tr key={row.id}><td><strong>{row.member?.name ?? row.member_id}</strong><small>{row.member?.member_number}</small></td><td>{row.branch?.name ?? row.branch_id}</td><td><span className="method-chip">{row.method.replace("_", " ")}</span></td><td>{formatGymTime(row.checked_in_at, data.timezone)}</td><td><span className={`status ${row.status}`}>{row.status.replace("_", " ")}</span></td><td>{row.status === "checked_in" && canCheckIn && <button className="table-action" disabled={busy} onClick={() => void act(() => data.onCheckOut(row.id), "Member checked out.")}><LogOut size={13} /> Check out</button>}</td></tr>)}</tbody></table>{!data.attendance.length && <div className="table-state">No attendance recorded today.</div>}</div>}</article>
    </>}

    {tab === "classes" && <>
      <div className="module-heading"><div><p className="eyebrow">Capacity-controlled schedule</p><h2>Upcoming classes</h2><p>Confirmed places, FIFO waitlists and trainer attendance stay synchronized under database locks.</p></div>{canManageClasses && <button className="primary-button" onClick={() => setClassModal(true)}><CalendarPlus size={16} /> New class</button>}</div>
      <div className="class-grid">{upcoming.map((session) => { const roster = bookingBySession.get(session.id) ?? []; const percent = Math.min(100, (session.booked_count / session.capacity) * 100); return <article className="panel class-card" key={session.id}><div className="class-card-head"><span className="engagement-icon violet"><Dumbbell size={19} /></span><span className={`status ${session.status}`}>{session.status}</span></div><p className="eyebrow">{session.branch?.name ?? "Gym class"}</p><h3>{session.title}</h3><p>{session.description ?? "Instructor-led member session."}</p><dl><div><Clock3 size={14} /><span>{formatGymDateTime(session.starts_at, data.timezone)}</span></div><div><UsersRound size={14} /><span>{session.booked_count}/{session.capacity} booked · {session.waitlist_count} waiting</span></div>{session.trainer?.name && <div><UserCheck size={14} /><span>{session.trainer.name}</span></div>}</dl><div className="capacity-track" aria-label={`${session.booked_count} of ${session.capacity} places booked`}><i style={{ width: `${percent}%` }} /></div>{canBookOthers && <button className="primary-button class-book-button" onClick={() => setBookingSession(session)}>Book a place</button>}{roster.length > 0 && <div className="mini-roster"><strong>Roster</strong>{roster.slice(0, 4).map((booking) => <div key={booking.id}><span>{booking.member?.name ?? "Member"}</span><span className={`status ${booking.status}`}>{booking.status}</span>{booking.status === "booked" && canMarkAttendance && <button aria-label="Mark attended" onClick={() => void act(() => data.onAttend(booking.id), "Class attendance recorded.")}><CheckCircle2 size={13} /></button>}{!data.readOnly && ["booked", "waitlisted"].includes(booking.status) && <button aria-label="Cancel booking" onClick={() => void act(() => data.onCancel(booking.id, "Cancelled by authorised workspace user"), "Booking cancelled; the next waitlisted member was promoted when applicable.")}><X size={13} /></button>}</div>)}</div>}</article>; })}</div>
      {!upcoming.length && <div className="panel table-state">No upcoming classes in this schedule window.</div>}
    </>}

    {classModal && <Modal title="Schedule a class" eyebrow="Tenant class session" icon={<CalendarPlus size={20} />} onClose={() => setClassModal(false)}><form onSubmit={createClass}><label>Class title<input name="title" required maxLength={160} autoFocus placeholder="Strength & conditioning" /></label><label>Description<textarea name="description" rows={2} maxLength={2000} /></label><div className="field-pair"><label>Branch<select name="branch_id" defaultValue={defaultBranch} required>{data.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label><label>Trainer<select name="trainer_id" defaultValue=""><option value="">Unassigned</option>{data.trainers.map((trainer) => <option key={trainer.id} value={trainer.id}>{trainer.name}</option>)}</select></label></div><div className="field-pair"><label>Starts<input name="starts_at" type="datetime-local" required /></label><label>Ends<input name="ends_at" type="datetime-local" required /></label></div><label>Capacity<input name="capacity" type="number" min="1" max="1000" defaultValue="20" required /></label><label className="inline-check"><input name="waitlist_enabled" type="checkbox" defaultChecked /> Enable FIFO waitlist when full</label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setClassModal(false)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Schedule class</button></div></form></Modal>}
    {bookingSession && <Modal title={`Book ${bookingSession.title}`} eyebrow="Capacity-safe booking" icon={<TicketCheck size={20} />} onClose={() => setBookingSession(null)}><form onSubmit={createBooking}><p className="booking-summary">{formatGymDateTime(bookingSession.starts_at, data.timezone)} · {bookingSession.booked_count}/{bookingSession.capacity} confirmed{bookingSession.booked_count >= bookingSession.capacity ? " · waitlist applies" : ""}</p>{canBookOthers && <label>Member<select name="member_id" required defaultValue=""><option value="" disabled>Select a member</option>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name} · {member.number}</option>)}</select></label>}<div className="modal-note"><ListOrdered size={16} />The server locks this class before assigning a place or FIFO waitlist position.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setBookingSession(null)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Confirm booking</button></div></form></Modal>}
    {credentialModal && <Modal title="Issue member QR" eyebrow="Revocable access credential" icon={<QrCode size={20} />} onClose={() => setCredentialModal(false)}>{credential ? <div className="credential-result"><canvas ref={qrCanvas} aria-label="New member QR credential" /><strong>Save this QR now</strong><p>It is shown only once. Issuing another QR immediately revokes this one.</p><button className="secondary-button" onClick={() => void navigator.clipboard?.writeText(credential)}>Copy encoded credential</button></div> : <form onSubmit={issueCredential}><label>Member<select name="member_id" required defaultValue=""><option value="" disabled>Select a member</option>{data.members.map((member) => <option key={member.id} value={member.id}>{member.name} · {member.number}</option>)}</select></label><div className="modal-note"><ShieldCheck size={16} />Only a SHA-256 digest is retained by IronCore; the QR plaintext appears in this response once.</div><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setCredentialModal(false)}>Cancel</button><button className="primary-button" disabled={busy} type="submit">Issue QR</button></div></form>}</Modal>}
    {scannerOpen && <QrCameraScanner branchName={data.branches.find((branch) => branch.id === selectedCheckInBranch)?.name ?? ""} onScan={checkInFromCamera} onClose={() => setScannerOpen(false)} onManualFallback={() => { setScannerOpen(false); window.setTimeout(() => memberCodeInput.current?.focus(), 0); }} />}
  </section>;
}
