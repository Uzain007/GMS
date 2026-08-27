"use client";

import { FormEvent, useEffect, useState } from "react";
import { Camera, Copy, MailPlus, Pencil, Plus, RefreshCw, Search, ShieldCheck, Trash2, UserCog, X } from "lucide-react";
import Image from "next/image";
import type { StaffRole } from "./lib/ironcore-api";

export type StaffRow = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  role: StaffRole;
  branchId: string | null;
  employeeNumber: string;
  jobTitle: string | null;
  status: "active" | "suspended" | "inactive";
  hiredAt: string | null;
  hasProfileImage: boolean;
};
export type InvitationRow = { id: string; email: string; role: StaffRole; branchId: string | null; employeeNumber: string; jobTitle: string | null; status: string; expiresAt: string };
export type NewStaffInvite = { email: string; role: StaffRole; employee_number: string; job_title?: string; home_branch_id?: string; expires_in_days: number };
export type NewTrainer = { name: string; email: string; phone: string; home_branch_id: string; status: "active" | "inactive"; profile_image?: File };
export type TrainerSetup = { setupLink: string | null; existingAccount: boolean };
export type StaffUpdate = { display_name: string; contact_email: string; phone: string; role: StaffRole; employee_number: string; job_title?: string | null; home_branch_id?: string | null; status: StaffRow["status"]; reason: string };
export type StaffData = {
  rows: StaffRow[];
  invitations: InvitationRow[];
  branches: Array<{ id: string; name: string }>;
  loading: boolean;
  error: string | null;
  actorRole: string;
  readOnly?: boolean;
  onReload: () => void;
  onCreateTrainer: (input: NewTrainer) => Promise<TrainerSetup>;
  onInvite: (input: NewStaffInvite) => Promise<string>;
  onUpdate: (id: string, input: StaffUpdate) => Promise<void>;
  onUpdateImage: (id: string, image: File | null, reason: string) => Promise<void>;
  onDelete: (id: string, reason: string) => Promise<void>;
  onLoadImage: (id: string) => Promise<Blob | null>;
};

const roleLabel = (role: string) => role.split("_").map((word) => word[0].toUpperCase() + word.slice(1)).join(" ");
const displayDate = (value: string | null) => value ? new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(value)) : "Not set";
const operationalRoles: StaffRole[] = ["receptionist", "trainer"];
const allRoles: StaffRole[] = ["gym_owner", "gym_manager", ...operationalRoles];

export function StaffManagement({ data, query }: { data: StaffData; query: string }) {
  const [createOpen, setCreateOpen] = useState(false);
  const [inviteOpen, setInviteOpen] = useState(false);
  const [editing, setEditing] = useState<StaffRow | null>(null);
  const search = query.toLowerCase();
  const rows = data.rows.filter((row) => `${row.name} ${row.email} ${row.phone ?? ""} ${row.employeeNumber} ${row.jobTitle ?? ""}`.toLowerCase().includes(search));
  const invitations = data.invitations.filter((row) => `${row.email} ${row.employeeNumber}`.toLowerCase().includes(search));
  const manager = data.actorRole === "gym_manager";
  const canEdit = (row: StaffRow) => !data.readOnly && (!manager || operationalRoles.includes(row.role));

  return <>
    <section className="module-heading">
      <div><p className="eyebrow">Gym team</p><h2>Staff / Trainers</h2><p>{data.readOnly ? "Representative team records for product review." : "Create trainers, control branch access and manage the wider gym team."}</p></div>
      {!data.readOnly && <div className="staff-heading-actions"><button className="secondary-button" onClick={() => setInviteOpen(true)}><MailPlus size={17} /> Invite staff</button><button className="primary-button" onClick={() => setCreateOpen(true)}><Plus size={18} /> Create trainer</button></div>}
    </section>
    <div className="live-scope-banner"><ShieldCheck size={17} /><span><strong>Tenant role boundary active</strong><small>Only this gym’s trainers are listed. Branch, role and status checks are repeated by the backend.</small></span></div>
    {data.loading && <div className="table-state"><RefreshCw className="spin" size={20} /> Loading gym team…</div>}
    {data.error && <div className="table-state error" role="alert"><strong>Staff data could not be loaded</strong><span>{data.error}</span><button className="secondary-button" onClick={data.onReload}>Try again</button></div>}
    {!data.loading && !data.error && <>
      <section className="mini-metrics"><article><span>Staff profiles</span><strong>{data.rows.length}</strong><small>Tenant-scoped users</small></article><article><span>Active trainers</span><strong>{data.rows.filter((row) => row.role === "trainer" && row.status === "active").length}</strong><small>Available for classes and coaching</small></article><article><span>Pending invites</span><strong>{data.invitations.length}</strong><small>Hashed, time-limited tokens</small></article></section>
      <section className="panel table-scroll"><div className="panel-heading compact"><div><p className="eyebrow">Authorised users</p><h2>Staff directory</h2></div></div>{rows.length ? <table className="data-table"><thead><tr><th>Staff member</th><th>Employee no.</th><th>Role</th><th>Branch</th><th>Status</th><th /></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td><div className="person-cell"><StaffAvatar row={row} load={data.onLoadImage} /><strong>{row.name}</strong></div><small className="table-sub">{row.email}{row.phone ? ` · ${row.phone}` : ""} · {row.jobTitle ?? "No job title"}</small></td><td>{row.employeeNumber}</td><td><span className="plan-pill">{roleLabel(row.role)}</span></td><td>{data.branches.find((branch) => branch.id === row.branchId)?.name ?? "All branches"}</td><td><span className={`status ${row.status}`}><i />{row.status}</span></td><td><button className="icon-button" disabled={!canEdit(row)} onClick={() => setEditing(row)} aria-label={`Manage ${row.name}`} title={!canEdit(row) ? "Managers cannot modify owners or other managers" : "Manage staff member"}><Pencil size={17} /></button></td></tr>)}</tbody></table> : <Empty text="No staff found" />}</section>
      <section className="panel table-scroll"><div className="panel-heading compact"><div><p className="eyebrow">Secure onboarding</p><h2>Pending invitations</h2></div></div>{invitations.length ? <table className="data-table"><thead><tr><th>Email</th><th>Employee no.</th><th>Role</th><th>Branch</th><th>Expires</th></tr></thead><tbody>{invitations.map((row) => <tr key={row.id}><td><strong>{row.email}</strong><small className="table-sub">{row.jobTitle ?? "No job title"}</small></td><td>{row.employeeNumber}</td><td>{roleLabel(row.role)}</td><td>{data.branches.find((branch) => branch.id === row.branchId)?.name ?? "All branches"}</td><td>{displayDate(row.expiresAt)}</td></tr>)}</tbody></table> : <Empty text="No pending invitations" />}</section>
    </>}
    {createOpen && <CreateTrainerModal data={data} close={() => setCreateOpen(false)} />}
    {inviteOpen && <InviteModal data={data} close={() => setInviteOpen(false)} />}
    {editing && <EditModal data={data} row={editing} close={() => setEditing(null)} />}
  </>;
}

function StaffAvatar({ row, load }: { row: StaffRow; load: (id: string) => Promise<Blob | null> }) {
  const [source, setSource] = useState<string | null>(null);
  useEffect(() => {
    if (!row.hasProfileImage) return;
    let active = true;
    let objectUrl: string | null = null;
    void load(row.id).then((blob) => {
      if (!active || !blob) return;
      objectUrl = URL.createObjectURL(blob);
      setSource(objectUrl);
    }).catch(() => undefined);
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl); };
  }, [load, row.hasProfileImage, row.id]);
  return source ? <Image className="staff-avatar-image" src={source} alt={`${row.name} profile`} width={29} height={29} unoptimized /> : <span>{row.name.split(" ").map((part) => part[0]).join("").slice(0, 2)}</span>;
}

function Empty({ text }: { text: string }) { return <div className="empty-state"><Search size={24} /><strong>{text}</strong><span>Records for this gym will appear here.</span></div>; }
function Shell({ title, close, children }: { title: string; close: () => void; children: React.ReactNode }) { return <div className="modal-layer" role="dialog" aria-modal="true"><button className="modal-scrim" onClick={close} aria-label="Close dialog" /><div className="modal-card staff-modal"><div className="modal-heading"><span><UserCog size={21} /></span><div><p className="eyebrow">Tenant access</p><h2>{title}</h2></div><button className="icon-button" onClick={close} aria-label="Close"><X size={19} /></button></div>{children}</div></div>; }

function CreateTrainerModal({ data, close }: { data: StaffData; close: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [setup, setSetup] = useState<TrainerSetup | null>(null);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const image = form.get("profile_image");
    setBusy(true); setError(null);
    try {
      setSetup(await data.onCreateTrainer({ name: String(form.get("name")), email: String(form.get("email")), phone: String(form.get("phone")), home_branch_id: String(form.get("branch")), status: String(form.get("status")) as "active" | "inactive", profile_image: image instanceof File && image.size ? image : undefined }));
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Trainer could not be created."); }
    finally { setBusy(false); }
  }
  return <Shell title={setup ? "Trainer created" : "Create trainer"} close={close}>{setup ? <><div className="modal-note"><ShieldCheck size={17} />The trainer is saved in this gym and will appear in eligible class and coaching lists.</div>{setup.setupLink ? <><label>Secure account setup link<textarea readOnly rows={4} value={setup.setupLink} /></label><p className="staff-helper">This one-time link is shown only now. Send it securely to the trainer so they can set their password.</p><div className="modal-actions"><button className="secondary-button" onClick={() => void navigator.clipboard.writeText(setup.setupLink!)}><Copy size={16} /> Copy link</button><button className="primary-button" onClick={close}>Done</button></div></> : <><div className="modal-note"><ShieldCheck size={17} />This email already has an IronCore account. The trainer can use their existing password.</div><div className="modal-actions"><button className="primary-button" onClick={close}>Done</button></div></>}</> : <form onSubmit={submit}>{error && <div className="form-error" role="alert">{error}</div>}<label>Name<input name="name" required maxLength={160} autoFocus /></label><div className="field-pair"><label>Email<input name="email" type="email" required maxLength={254} /></label><label>Phone<input name="phone" type="tel" required maxLength={40} /></label></div><label className="staff-file-label"><span>Profile image <small>Optional · JPG, PNG or WebP, max 5 MB</small></span><input name="profile_image" type="file" accept="image/jpeg,image/png,image/webp" /></label><div className="field-pair"><label>Branch<select name="branch" required defaultValue=""><option value="" disabled>Select branch</option>{data.branches.map((branch) => <option value={branch.id} key={branch.id}>{branch.name}</option>)}</select></label><label>Status<select name="status" defaultValue="active"><option value="active">Active</option><option value="inactive">Inactive</option></select></label></div><div className="modal-note"><ShieldCheck size={17} />Role is fixed as Trainer. The backend validates gym, branch and access before saving.</div><div className="modal-actions"><button type="button" className="secondary-button" onClick={close}>Cancel</button><button disabled={busy || data.branches.length === 0} className="primary-button">{busy ? "Creating…" : "Create trainer"}</button></div></form>}</Shell>;
}

function InviteModal({ data, close }: { data: StaffData; close: () => void }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const [link, setLink] = useState<string | null>(null); const roles = data.actorRole === "gym_manager" ? operationalRoles : allRoles;
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const form = new FormData(event.currentTarget); setBusy(true); setError(null); try { setLink(await data.onInvite({ email: String(form.get("email")), role: String(form.get("role")) as StaffRole, employee_number: String(form.get("employee_number")), job_title: String(form.get("job_title")) || undefined, home_branch_id: String(form.get("branch")) || undefined, expires_in_days: Number(form.get("expires")) })); } catch (reason) { setError(reason instanceof Error ? reason.message : "Invitation could not be created."); } finally { setBusy(false); } }
  return <Shell title={link ? "Invitation created" : "Invite staff member"} close={close}>{link ? <><div className="modal-note"><ShieldCheck size={17} />This one-time acceptance link is shown only now. Send it securely to the invited email address.</div><label>Acceptance link<textarea readOnly rows={4} value={link} /></label><div className="modal-actions"><button className="secondary-button" onClick={() => void navigator.clipboard.writeText(link)}><Copy size={16} /> Copy link</button><button className="primary-button" onClick={close}>Done</button></div></> : <form onSubmit={submit}>{error && <div className="form-error" role="alert">{error}</div>}<label>Email<input name="email" type="email" required autoFocus /></label><div className="field-pair"><label>Role<select name="role">{roles.map((role) => <option value={role} key={role}>{roleLabel(role)}</option>)}</select></label><label>Employee no.<input name="employee_number" required pattern="[A-Za-z0-9_-]+" /></label></div><label>Job title<input name="job_title" maxLength={120} /></label><div className="field-pair"><label>Home branch<select name="branch"><option value="">All branches</option>{data.branches.map((branch) => <option value={branch.id} key={branch.id}>{branch.name}</option>)}</select></label><label>Expires in<select name="expires" defaultValue="7"><option value="1">1 day</option><option value="3">3 days</option><option value="7">7 days</option><option value="14">14 days</option><option value="30">30 days</option></select></label></div><div className="modal-note"><ShieldCheck size={17} />The server stores only a SHA-256 token hash and never stores the acceptance secret.</div><div className="modal-actions"><button type="button" className="secondary-button" onClick={close}>Cancel</button><button disabled={busy} className="primary-button">{busy ? "Creating…" : "Create invitation"}</button></div></form>}</Shell>;
}

function EditModal({ data, row, close }: { data: StaffData; row: StaffRow; close: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const roles = data.actorRole === "gym_manager" ? operationalRoles : allRoles;
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const reason = String(form.get("reason"));
    const image = form.get("profile_image");
    setBusy(true); setError(null);
    try {
      await data.onUpdate(row.id, { display_name: String(form.get("name")), contact_email: String(form.get("email")), phone: String(form.get("phone")), role: row.role === "trainer" ? "trainer" : String(form.get("role")) as StaffRole, employee_number: String(form.get("employee_number")), job_title: String(form.get("job_title")) || null, home_branch_id: String(form.get("branch")) || null, status: String(form.get("status")) as StaffRow["status"], reason });
      if (image instanceof File && image.size) await data.onUpdateImage(row.id, image, reason);
      else if (form.get("remove_image") === "on") await data.onUpdateImage(row.id, null, reason);
      close();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Staff could not be updated."); }
    finally { setBusy(false); }
  }
  async function remove(event: React.MouseEvent<HTMLButtonElement>) {
    const form = event.currentTarget.form;
    const reason = String(new FormData(form ?? undefined).get("reason") ?? "");
    if (!reason.trim()) { setError("Enter an audit reason before deleting this trainer."); return; }
    if (!confirmDelete) { setConfirmDelete(true); return; }
    setBusy(true); setError(null);
    try { await data.onDelete(row.id, reason); close(); }
    catch (failure) { setError(failure instanceof Error ? failure.message : "Trainer could not be deleted."); setConfirmDelete(false); }
    finally { setBusy(false); }
  }
  const trainer = row.role === "trainer";
  return <Shell title={`Manage ${row.name}`} close={close}><form onSubmit={submit}>{error && <div className="form-error" role="alert">{error}</div>}<div className="field-pair"><label>Name<input name="name" defaultValue={row.name} required maxLength={160} /></label><label>Email<input name="email" type="email" defaultValue={row.email} required maxLength={254} /></label></div><div className="field-pair"><label>Phone<input name="phone" type="tel" defaultValue={row.phone ?? ""} required maxLength={40} /></label><label>Role<select name="role" defaultValue={row.role} disabled={trainer}>{roles.map((role) => <option value={role} key={role}>{roleLabel(role)}</option>)}</select></label></div><div className="field-pair"><label>Status<select name="status" defaultValue={row.status}><option value="active">Active</option>{!trainer && <option value="suspended">Suspended</option>}<option value="inactive">Inactive</option></select></label><label>Employee no.<input name="employee_number" defaultValue={row.employeeNumber} required /></label></div><label>Job title<input name="job_title" defaultValue={row.jobTitle ?? ""} /></label><label>Home branch<select name="branch" defaultValue={row.branchId ?? ""} required={trainer}><option value="">{trainer ? "Select branch" : "All branches"}</option>{data.branches.map((branch) => <option value={branch.id} key={branch.id}>{branch.name}</option>)}</select></label>{trainer && <><label className="staff-file-label"><span><Camera size={14} /> Replace profile image <small>Optional</small></span><input name="profile_image" type="file" accept="image/jpeg,image/png,image/webp" /></label>{row.hasProfileImage && <label className="staff-remove-image"><input name="remove_image" type="checkbox" /> Remove current profile image</label>}</>}<label>Audit reason<textarea name="reason" required maxLength={500} placeholder="Explain why this change is required" /></label><div className="modal-note"><ShieldCheck size={17} />Inactive trainers immediately disappear from class, workout-plan and coaching dropdowns.</div><div className="modal-actions staff-edit-actions">{trainer && <button type="button" className="danger-button" disabled={busy} onClick={(event) => void remove(event)}><Trash2 size={16} /> {confirmDelete ? "Confirm delete" : "Delete trainer"}</button>}<span /><button type="button" className="secondary-button" onClick={close}>Cancel</button><button disabled={busy} className="primary-button">{busy ? "Saving…" : "Save changes"}</button></div></form></Shell>;
}
