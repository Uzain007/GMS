"use client";

import {
  Archive, ArrowRight, BarChart3, Building2, CheckCircle2, ChevronDown, CircleDollarSign, Copy, Eye, EyeOff, FileClock, KeyRound, LayoutDashboard, LoaderCircle,
  LogOut, Mail, Menu, Pencil, Plus, Power, RefreshCw, Search, Settings, ShieldCheck, UserRound, UsersRound, X,
} from "lucide-react";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { AccountSecurityDialog, type MfaActions } from "./account-security";
import {
  countryOptions, defaultTimezoneForCountry, normalizeCountryCode, normalizeTimezone, timezoneOptions,
} from "./gym-location-options";
import type {
  AuditLogFilters, AuditLogRecord, AuthenticatedUser, CreatedGym, GymOwnerAccount, GymSummary, NewGym, NewGymOwnerAccount, NewSaasPlan, NewSaasPlanPrice,
  Paginated, PlatformAnalyticsRecord, PlatformBillingRecord, PlatformMemberPage, SaasPlanRecord, UpdateGym, UpdateGymOwnerAccount, UpdateSaasPlan,
} from "./lib/ironcore-api";
import { SearchableSelect } from "./searchable-select";
import { decimalToMinor } from "./tenant-operations";
import { AuditLogManagement } from "./audit-log-management";
import { PlatformAnalytics, PlatformBillingDashboard, PlatformMemberDirectory } from "./platform-insights";

type PlatformView = "overview" | "gyms" | "members" | "plans" | "billing" | "analytics" | "audit" | "settings";

export type PlatformPortalData = {
  user: AuthenticatedUser;
  gyms: GymSummary[];
  plans: SaasPlanRecord[];
  loading: boolean;
  error: string | null;
  onReload: () => void;
  onOpenGym: (gym: GymSummary) => void;
  onCreateGym: (input: NewGym) => Promise<CreatedGym>;
  onUpdateGym: (gymId: string, input: UpdateGym) => Promise<void>;
  onDeleteGym: (gymId: string, confirmation: string, reason: string) => Promise<void>;
  onLoadGymOwner: (gymId: string) => Promise<GymOwnerAccount | null>;
  onCreateGymOwner: (gymId: string, input: NewGymOwnerAccount) => Promise<GymOwnerAccount>;
  onUpdateGymOwner: (gymId: string, input: UpdateGymOwnerAccount) => Promise<GymOwnerAccount>;
  onSendGymOwnerReset: (gymId: string, reason: string) => Promise<GymOwnerAccount>;
  onGenerateGymOwnerTemporaryPassword: (gymId: string, reason: string) => Promise<{ account: GymOwnerAccount; temporary_password: string }>;
  onCreatePlan: (input: NewSaasPlan) => Promise<void>;
  onUpdatePlan: (planId: string, input: UpdateSaasPlan, price?: NewSaasPlanPrice) => Promise<void>;
  onLoadMembers: (params: URLSearchParams) => Promise<PlatformMemberPage>;
  onExportMembers: (params: URLSearchParams) => Promise<Blob>;
  onLoadBilling: (params: URLSearchParams) => Promise<PlatformBillingRecord>;
  onLoadAnalytics: (params: URLSearchParams) => Promise<PlatformAnalyticsRecord>;
  onLoadAudit: (filters: AuditLogFilters) => Promise<Paginated<AuditLogRecord>>;
  onExportAudit: (format: "csv" | "xlsx" | "pdf", filters: AuditLogFilters) => Promise<void>;
  onChangePassword: (currentPassword: string, password: string) => Promise<void>;
  onLogout: () => void;
  mfa?: MfaActions;
};

const currencies: GymSummary["base_currency"][] = ["GBP", "USD", "PKR", "AED", "SAR"];

function initials(name: string): string {
  return name.split(" ").map((part) => part[0]).join("").slice(0, 2).toUpperCase();
}

function readable(value: string): string {
  return value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function money(amountMinor: number, currency: string): string {
  return new Intl.NumberFormat("en-GB", { style: "currency", currency }).format(amountMinor / 100);
}

function ModalShell({ title, eyebrow, onClose, children }: {
  title: string;
  eyebrow: string;
  onClose: () => void;
  children: React.ReactNode;
}) {
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-label={title}>
    <button className="modal-scrim" onClick={onClose} aria-label="Close dialog" />
    <section className="modal-card platform-modal">
      <div className="modal-heading"><span><Building2 size={21} /></span><div><p className="eyebrow">{eyebrow}</p><h2>{title}</h2></div><button className="icon-button modal-close-control" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>
      {children}
    </section>
  </div>;
}

function CreateGymModal({ onClose, onCreate, onOpen }: { onClose: () => void; onCreate: PlatformPortalData["onCreateGym"]; onOpen: (gym: GymSummary) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [countryCode, setCountryCode] = useState("GB");
  const [timezone, setTimezone] = useState("Europe/London");
  const [createOwner, setCreateOwner] = useState(true);
  const [setupMethod, setSetupMethod] = useState<"invite" | "temporary_password">("invite");
  const [created, setCreated] = useState<CreatedGym | null>(null);
  const [createdTemporaryPassword, setCreatedTemporaryPassword] = useState<string | null>(null);
  const [showTemporaryPassword, setShowTemporaryPassword] = useState(false);
  const [copied, setCopied] = useState(false);

  function changeCountry(nextCountryCode: string) {
    setCountryCode(nextCountryCode);
    setTimezone(defaultTimezoneForCountry(nextCountryCode));
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    try {
      const temporaryPassword = setupMethod === "temporary_password" ? String(form.get("temporary_password")) : undefined;
      const result = await onCreate({
        name: String(form.get("name")),
        legal_name: String(form.get("legal_name")) || undefined,
        base_currency: String(form.get("base_currency")) as GymSummary["base_currency"],
        country_code: String(form.get("country_code")).toUpperCase(),
        timezone: String(form.get("timezone")),
        owner: {
          create_login_account: createOwner,
          name: createOwner ? String(form.get("owner_name")) : undefined,
          email: createOwner ? String(form.get("owner_email")) : undefined,
          phone: createOwner ? String(form.get("owner_phone")) : undefined,
          setup_method: createOwner ? setupMethod : undefined,
          temporary_password: temporaryPassword,
          temporary_password_confirmation: setupMethod === "temporary_password" ? String(form.get("temporary_password_confirmation")) : undefined,
          require_password_change: setupMethod === "temporary_password" ? true : undefined,
        },
      });
      setCreated(result);
      setCreatedTemporaryPassword(temporaryPassword ?? null);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The gym could not be created.");
    } finally {
      setBusy(false);
    }
  }

  return <ModalShell title={created ? `${created.gym.name} created` : "Create a gym"} eyebrow="Platform tenant onboarding" onClose={onClose}>
    {created ? <div className="owner-create-result" role="status">
      <span className="owner-create-success-icon"><CheckCircle2 size={24} /></span>
      <div className="owner-create-heading"><h3>{created.gym.name} created</h3><p>Gym created successfully</p></div>
      <dl className="owner-create-summary">
        <div><dt>Gym name</dt><dd>{created.gym.name}</dd></div>
        <div><dt>Owner name</dt><dd>{created.owner_account?.name ?? "Not created"}</dd></div>
        <div><dt>Owner login email</dt><dd>{created.owner_account?.email ?? "Not created"}</dd></div>
        <div><dt>Account setup</dt><dd>{created.owner_account?.setup_method ? readable(created.owner_account.setup_method) : "No login account"}</dd></div>
        <div><dt>Password change required</dt><dd>{created.owner_account?.must_change_password ? "Yes" : "No"}</dd></div>
      </dl>
      <p className="owner-create-message">{created.owner_account
        ? created.owner_account.setup_method === "invite"
          ? `A secure setup email was queued for ${created.owner_account.email}.`
          : created.owner_account.setup_method === "temporary_password"
            ? `${created.owner_account.name} can sign in with the temporary password and must replace it before entering the gym portal.`
            : `${created.owner_account.name}'s existing IronCore login was linked to this gym, and a secure password setup email was queued.`
        : "No owner login was created. You can add one from Manage gym."}</p>
      {createdTemporaryPassword && <div className="one-time-secret-panel"><KeyRound size={19} /><div><span>Shown once only</span><code>{showTemporaryPassword ? createdTemporaryPassword : "•".repeat(16)}</code><small>Copy it now. IronCore cannot recover it after this window closes.</small></div><div className="one-time-secret-actions"><button className="icon-button" type="button" onClick={() => setShowTemporaryPassword((value) => !value)} aria-label={showTemporaryPassword ? "Hide temporary password" : "Show temporary password"}>{showTemporaryPassword ? <EyeOff size={17} /> : <Eye size={17} />}</button><button className="secondary-button" type="button" onClick={async () => { try { await navigator.clipboard.writeText(createdTemporaryPassword); setCopied(true); } catch { setError("Your browser blocked copying. Select the temporary password and copy it manually."); } }}><Copy size={15} /> {copied ? "Copied" : "Copy"}</button></div></div>}
      <div className="owner-create-actions"><button className="secondary-button" type="button" onClick={() => { onOpen(created.gym); onClose(); }}>Open gym</button><button className="primary-button" type="button" onClick={onClose}>Done</button></div>
    </div> :
    <form onSubmit={submit}>{error && <div className="form-error" role="alert">{error}</div>}
      <div className="field-pair"><label>Gym name<input name="name" maxLength={160} required autoFocus /></label><label>Legal name<input name="legal_name" maxLength={200} /></label></div>
      <div className="field-trio"><label>Currency<select name="base_currency" defaultValue="GBP">{currencies.map((currency) => <option key={currency}>{currency}</option>)}</select></label><SearchableSelect label="Country & calling code" name="country_code" options={countryOptions} value={countryCode} onChange={changeCountry} placeholder="Search country or calling code" /><SearchableSelect label="Timezone" name="timezone" options={timezoneOptions} value={timezone} onChange={setTimezone} placeholder="Search IANA timezone" /></div>
      <fieldset className="owner-account-fields"><legend>Gym Owner Account</legend>
        <label className="owner-toggle"><input type="checkbox" checked={createOwner} onChange={(event) => setCreateOwner(event.target.checked)} /> Create login account</label>
        {createOwner && <>
          <div className="field-pair"><label>Owner full name<input name="owner_name" maxLength={160} required /></label><label>Owner email<input name="owner_email" type="email" maxLength={254} autoComplete="off" required /></label></div>
          <label>Owner phone<input name="owner_phone" type="tel" maxLength={40} required placeholder="+44 7700 900000" /></label>
          <div className="owner-setup-options" role="radiogroup" aria-label="Owner account setup method">
            <label><input type="radio" name="setup_method" value="invite" checked={setupMethod === "invite"} onChange={() => setSetupMethod("invite")} /><span><Mail size={17} /><strong>Send secure invite</strong><small>Email a one-time password setup link.</small></span></label>
            <label><input type="radio" name="setup_method" value="temporary_password" checked={setupMethod === "temporary_password"} onChange={() => setSetupMethod("temporary_password")} /><span><KeyRound size={17} /><strong>Set temporary password</strong><small>Share it privately; the owner must replace it.</small></span></label>
          </div>
          {setupMethod === "temporary_password" && <div className="field-pair"><label>Temporary password<span className="password-input-wrap"><input name="temporary_password" type={showTemporaryPassword ? "text" : "password"} autoComplete="new-password" minLength={12} maxLength={255} required /><button className="password-visibility-button" type="button" onClick={() => setShowTemporaryPassword((value) => !value)} aria-label={showTemporaryPassword ? "Hide temporary password" : "Show temporary password"}>{showTemporaryPassword ? <EyeOff size={16} /> : <Eye size={16} />}</button></span></label><label>Confirm temporary password<span className="password-input-wrap"><input name="temporary_password_confirmation" type={showTemporaryPassword ? "text" : "password"} autoComplete="new-password" minLength={12} maxLength={255} required /><button className="password-visibility-button" type="button" onClick={() => setShowTemporaryPassword((value) => !value)} aria-label={showTemporaryPassword ? "Hide confirmed password" : "Show confirmed password"}>{showTemporaryPassword ? <EyeOff size={16} /> : <Eye size={16} />}</button></span></label></div>}
          <label className="owner-toggle owner-required"><input type="checkbox" checked readOnly /> Require password change on first login</label>
        </>}
      </fieldset>
      <div className="modal-note"><ShieldCheck size={17} />Passwords are hashed by Laravel. Super Admin can manage access but can never view or impersonate the owner&apos;s saved password.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy ? <><LoaderCircle className="spin" size={16} /> Creating…</> : <>Create gym <ArrowRight size={16} /></>}</button></div>
    </form>}
  </ModalShell>;
}

function CreatePlanModal({ onClose, onCreate }: { onClose: () => void; onCreate: PlatformPortalData["onCreatePlan"] }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    try {
      await onCreate({
        code: String(form.get("code")), name: String(form.get("name")),
        description: String(form.get("description")) || undefined,
        currency: String(form.get("currency")) as GymSummary["base_currency"],
        billing_interval: String(form.get("billing_interval")) as "monthly" | "yearly",
        amount_minor: decimalToMinor(String(form.get("amount"))),
        trial_days: Number(form.get("trial_days")), sort_order: Number(form.get("sort_order")),
        feature_limits: {
          members: Number(form.get("members")), branches: Number(form.get("branches")), staff: Number(form.get("staff")),
          advanced_reports: form.get("advanced_reports") === "on", priority_support: form.get("priority_support") === "on",
        },
        payment_methods: [
          form.get("bank_transfer") === "on" ? "bank_transfer" : null,
          form.get("cash") === "on" ? "cash" : null,
          form.get("stripe") === "on" ? "stripe" : null,
        ].filter((method): method is "bank_transfer" | "cash" | "stripe" => method !== null),
      });
      onClose();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The plan could not be published.");
    } finally {
      setBusy(false);
    }
  }

  return <ModalShell title="Publish a SaaS plan" eyebrow="Platform catalogue" onClose={onClose}>
    <form onSubmit={submit}>{error && <div className="form-error" role="alert">{error}</div>}
      <div className="field-pair"><label>Plan name<input name="name" required maxLength={120} autoFocus /></label><label>Code<input name="code" required maxLength={60} pattern="[a-z0-9_-]+" /></label></div>
      <label>Description<textarea name="description" rows={2} maxLength={1000} /></label>
      <div className="field-trio"><label>Currency<select name="currency" defaultValue="GBP">{currencies.map((currency) => <option key={currency}>{currency}</option>)}</select></label><label>Interval<select name="billing_interval" defaultValue="monthly"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></label><label>Price<input name="amount" inputMode="decimal" required placeholder="79.00" /></label></div>
      <div className="field-trio"><label>Member limit<input name="members" type="number" min="1" defaultValue="2500" required /></label><label>Branch limit<input name="branches" type="number" min="1" defaultValue="3" required /></label><label>Staff limit<input name="staff" type="number" min="1" defaultValue="25" required /></label></div>
      <div className="field-pair"><label>Trial days<input name="trial_days" type="number" min="0" max="90" defaultValue="14" required /></label><label>Sort order<input name="sort_order" type="number" min="0" defaultValue="100" required /></label></div>
      <div className="check-row"><label><input name="advanced_reports" type="checkbox" /> Advanced reports</label><label><input name="priority_support" type="checkbox" /> Priority support</label></div>
      <div className="check-row payment-method-checks"><label><input name="bank_transfer" type="checkbox" defaultChecked /> Bank transfer</label><label><input name="cash" type="checkbox" defaultChecked /> Cash</label><label><input name="stripe" type="checkbox" /> Debit/Credit Card · Stripe</label></div>
      <div className="modal-note"><ShieldCheck size={17} />Publishing stores the plan and immutable price in IronCore. Stripe is synchronized only if a gym later selects card payment.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy ? <><LoaderCircle className="spin" size={16} /> Publishing…</> : <>Publish plan <ArrowRight size={16} /></>}</button></div>
    </form>
  </ModalShell>;
}

function GymManagementModal({ gym, onClose, onOpen, onUpdate, onDelete, onLoadOwner, onCreateOwner, onUpdateOwner, onSendReset, onGenerateTemporary }: {
  gym: GymSummary;
  onClose: () => void;
  onOpen: () => void;
  onUpdate: PlatformPortalData["onUpdateGym"];
  onLoadOwner: PlatformPortalData["onLoadGymOwner"];
  onCreateOwner: PlatformPortalData["onCreateGymOwner"];
  onUpdateOwner: PlatformPortalData["onUpdateGymOwner"];
  onSendReset: PlatformPortalData["onSendGymOwnerReset"];
  onGenerateTemporary: PlatformPortalData["onGenerateGymOwnerTemporaryPassword"];
  onDelete?: PlatformPortalData["onDeleteGym"];
}) {
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [owner, setOwner] = useState<GymOwnerAccount | null | undefined>(undefined);
  const [ownerEditing, setOwnerEditing] = useState(false);
  const [ownerAction, setOwnerAction] = useState<"reset" | "temporary" | null>(null);
  const [ownerSetupMethod, setOwnerSetupMethod] = useState<"invite" | "temporary_password">("invite");
  const [temporaryPassword, setTemporaryPassword] = useState<string | null>(null);
  const [hardDelete, setHardDelete] = useState(false);
  const [lifecycleTarget, setLifecycleTarget] = useState<GymSummary["status"] | null>(null);
  const initialCountryCode = normalizeCountryCode(gym.country_code);
  const [countryCode, setCountryCode] = useState(initialCountryCode);
  const [timezone, setTimezone] = useState(() => normalizeTimezone(gym.timezone, initialCountryCode));

  function changeCountry(nextCountryCode: string) {
    setCountryCode(nextCountryCode);
    setTimezone(defaultTimezoneForCountry(nextCountryCode));
  }

  useEffect(() => {
    let active = true;
    void onLoadOwner(gym.id).then((account) => { if (active) setOwner(account); }).catch((reason) => {
      if (active) setError(reason instanceof Error ? reason.message : "The owner account could not be loaded.");
    });
    return () => { active = false; };
  }, [gym.id, onLoadOwner]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    try {
      await onUpdate(gym.id, {
        name: String(form.get("name")), legal_name: String(form.get("legal_name")) || null,
        base_currency: String(form.get("base_currency")) as GymSummary["base_currency"],
        country_code: String(form.get("country_code")).toUpperCase(), timezone: String(form.get("timezone")),
        status: String(form.get("status")) as GymSummary["status"], reason: String(form.get("reason")),
      });
      onClose();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The gym could not be updated."); }
    finally { setBusy(false); }
  }

  async function submitOwner(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); setError(null); setNotice(null);
    try {
      const next = owner ? await onUpdateOwner(gym.id, {
        name: String(form.get("owner_name")), email: String(form.get("owner_email")), phone: String(form.get("owner_phone")),
        status: String(form.get("owner_status")) as "active" | "suspended", reason: String(form.get("reason")),
      }) : await onCreateOwner(gym.id, {
        name: String(form.get("owner_name")), email: String(form.get("owner_email")), phone: String(form.get("owner_phone")),
        setup_method: ownerSetupMethod,
        temporary_password: ownerSetupMethod === "temporary_password" ? String(form.get("temporary_password")) : undefined,
        temporary_password_confirmation: ownerSetupMethod === "temporary_password" ? String(form.get("temporary_password_confirmation")) : undefined,
        require_password_change: ownerSetupMethod === "temporary_password" ? true : undefined,
        reason: String(form.get("reason")),
      });
      setOwner(next); setOwnerEditing(false);
      setNotice(owner ? "Owner account updated and audited." : ownerSetupMethod === "invite" ? "Owner login created and secure setup email queued." : "Owner login created with a temporary password.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The owner account could not be saved."); }
    finally { setBusy(false); }
  }

  async function submitOwnerAction(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!ownerAction) return;
    const reason = String(new FormData(event.currentTarget).get("reason"));
    setBusy(true); setError(null); setNotice(null); setTemporaryPassword(null);
    try {
      if (ownerAction === "reset") {
        setOwner(await onSendReset(gym.id, reason));
        setNotice("A secure password reset/setup email was queued for the owner.");
      } else {
        const result = await onGenerateTemporary(gym.id, reason);
        setOwner(result.account); setTemporaryPassword(result.temporary_password);
      }
      setOwnerAction(null);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The owner security action failed."); }
    finally { setBusy(false); }
  }

  async function copyTemporaryPassword() {
    if (!temporaryPassword) return;
    try { await navigator.clipboard.writeText(temporaryPassword); setNotice("Temporary password copied. Share it through a private channel."); }
    catch { setError("Your browser blocked copying. Copy the temporary password manually now."); }
  }

  async function submitHardDelete(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!onDelete) return;
    const form = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    try {
      await onDelete(gym.id, String(form.get("confirmation")), String(form.get("reason")));
      onClose();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The gym could not be permanently deleted."); }
    finally { setBusy(false); }
  }

  async function submitLifecycle(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!lifecycleTarget) return;
    const reason = String(new FormData(event.currentTarget).get("reason"));
    setBusy(true); setError(null);
    try { await onUpdate(gym.id, { status: lifecycleTarget, reason }); onClose(); }
    catch (reason) { setError(reason instanceof Error ? reason.message : "The gym lifecycle change could not be saved."); }
    finally { setBusy(false); }
  }

  return <ModalShell title={editing ? `Edit ${gym.name}` : gym.name} eyebrow={editing ? "Audited tenant settings" : "Gym details"} onClose={onClose}>
    {error && <div className="form-error" role="alert">{error}</div>}
    {notice && <div className="form-success" role="status">{notice}</div>}
    {editing ? <form onSubmit={submit}>
      <div className="field-pair"><label>Gym name<input name="name" required maxLength={160} defaultValue={gym.name} /></label><label>Legal name<input name="legal_name" maxLength={200} defaultValue={gym.legal_name ?? ""} /></label></div>
      <div className="field-trio"><label>Currency<select name="base_currency" defaultValue={gym.base_currency}>{currencies.map((currency) => <option key={currency}>{currency}</option>)}</select></label><SearchableSelect label="Country & calling code" name="country_code" options={countryOptions} value={countryCode} onChange={changeCountry} placeholder="Search country or calling code" /><SearchableSelect label="Timezone" name="timezone" options={timezoneOptions} value={timezone} onChange={setTimezone} placeholder="Search IANA timezone" /></div>
      <label>Tenant status<select name="status" defaultValue={gym.status}><option value="trial">Trial</option><option value="active">Active</option><option value="past_due">Past due</option><option value="suspended">Deactivated / suspended</option><option value="cancelled">Archived / cancelled</option></select></label>
      <label>Audit reason<textarea name="reason" required minLength={5} maxLength={500} placeholder="Explain this tenant or settings change" /></label>
      <div className="modal-note"><ShieldCheck size={17} />Currency belongs to this gym only. Existing payments and memberships retain their historical currency snapshots.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setEditing(false)}>Cancel edit</button><button className="primary-button" disabled={busy}>{busy ? "Saving…" : "Save gym"}</button></div>
    </form> : <>
      <dl className="platform-detail-grid"><div><dt>Status</dt><dd>{readable(gym.status)}</dd></div><div><dt>Currency</dt><dd>{gym.base_currency}</dd></div><div><dt>Country</dt><dd>{gym.country_code}</dd></div><div><dt>Timezone</dt><dd>{gym.timezone}</dd></div><div><dt>Slug</dt><dd>{gym.slug}</dd></div><div><dt>Legal name</dt><dd>{gym.legal_name ?? "Not set"}</dd></div></dl>
      <div className="platform-management-actions"><button className="secondary-button" onClick={() => setEditing(true)}><Pencil size={15} /> Edit gym</button>{gym.status !== "cancelled" && <button className="secondary-button" onClick={onOpen}><ArrowRight size={15} /> Open gym</button>}{gym.status === "suspended" ? <button className="secondary-button" type="button" onClick={() => setLifecycleTarget("active")}><Power size={15} /> Reactivate</button> : gym.status !== "cancelled" && <button className="secondary-button" type="button" onClick={() => setLifecycleTarget("suspended")}><Power size={15} /> Suspend access</button>}{gym.status !== "cancelled" && <button className="secondary-button" type="button" onClick={() => setLifecycleTarget("cancelled")}><Archive size={15} /> Archive gym</button>}{gym.status === "cancelled" && onDelete && <button className="danger-button" type="button" onClick={() => setHardDelete(true)}><Archive size={15} /> Permanently delete test gym</button>}</div>
      <div className="modal-note"><Archive size={17} />Use Edit gym to activate, deactivate or archive this tenant. IronCore retains the audit and billing history.</div>
      <section className="owner-account-panel" aria-labelledby="owner-account-title">
        <div className="owner-account-heading"><div><p className="eyebrow">Gym Owner Account</p><h3 id="owner-account-title">Owner login and access</h3></div>{owner !== undefined && <button className="secondary-button" type="button" onClick={() => { setOwnerEditing(true); setOwnerAction(null); setTemporaryPassword(null); }}><Pencil size={15} /> {owner ? "Edit owner" : "Create owner login"}</button>}</div>
        {owner === undefined ? <div className="table-state"><LoaderCircle className="spin" size={18} /> Loading owner account…</div> : owner ? <>
          <dl className="platform-detail-grid owner-detail-grid"><div><dt>Owner</dt><dd>{owner.name}</dd></div><div><dt>Login email</dt><dd>{owner.email}</dd></div><div><dt>Phone</dt><dd>{owner.phone ?? "Not set"}</dd></div><div><dt>Account status</dt><dd>{readable(owner.account_status)}</dd></div><div><dt>Invite / setup</dt><dd>{readable(owner.setup_status)}</dd></div><div><dt>Last login</dt><dd>{owner.last_login_at ? new Date(owner.last_login_at).toLocaleString() : "Never"}</dd></div><div><dt>Role</dt><dd>Gym Owner / Gym Admin</dd></div><div><dt>Password</dt><dd>Securely hashed · never visible</dd></div></dl>
          <div className="platform-management-actions owner-security-actions"><button className="secondary-button" type="button" onClick={() => setOwnerAction("reset")}><Mail size={15} /> {owner.setup_status === "invite_pending" ? "Resend invite" : "Reset password by email"}</button><button className="secondary-button" type="button" onClick={() => setOwnerAction("temporary")}><KeyRound size={15} /> Generate temporary password</button></div>
        </> : <div className="compact-empty-state"><UserRound size={22} /><div><strong>No owner login yet</strong><span>Create one here without changing the gym&apos;s business settings.</span></div></div>}

        {ownerEditing && <form className="owner-account-form" onSubmit={submitOwner}>
          <div className="field-pair"><label>Owner full name<input name="owner_name" required maxLength={160} defaultValue={owner?.name ?? ""} /></label><label>Login email<input name="owner_email" required type="email" maxLength={254} defaultValue={owner?.email ?? ""} /></label></div>
          <div className="field-pair"><label>Owner phone<input name="owner_phone" required type="tel" maxLength={40} defaultValue={owner?.phone ?? ""} /></label>{owner ? <label>Account status<select name="owner_status" defaultValue={owner.account_status}><option value="active">Active</option><option value="suspended">Suspended</option></select></label> : <label>Setup method<select value={ownerSetupMethod} onChange={(event) => setOwnerSetupMethod(event.target.value as "invite" | "temporary_password")}><option value="invite">Send secure invite/setup email</option><option value="temporary_password">Set temporary password manually</option></select></label>}</div>
          {!owner && ownerSetupMethod === "temporary_password" && <div className="field-pair"><label>Temporary password<input name="temporary_password" type="password" minLength={12} maxLength={255} autoComplete="new-password" required /></label><label>Confirm temporary password<input name="temporary_password_confirmation" type="password" minLength={12} maxLength={255} autoComplete="new-password" required /></label></div>}
          {!owner && ownerSetupMethod === "temporary_password" && <label className="owner-toggle owner-required"><input type="checkbox" checked readOnly /> Require password change on first login</label>}
          <label>Audit reason<textarea name="reason" required minLength={5} maxLength={500} placeholder={owner ? "Explain the owner profile, email or access change" : "Explain why this owner login is being created"} /></label>
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setOwnerEditing(false)}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? "Saving…" : owner ? "Save owner account" : "Create owner login"}</button></div>
        </form>}

        {ownerAction && <form className="owner-action-form" onSubmit={submitOwnerAction}>
          <p>{ownerAction === "reset" ? "IronCore will email a secure, expiring one-time reset link. The current password remains private." : "IronCore will replace the current password immediately, sign out existing sessions and show the new temporary password once."}</p>
          <label>Audit reason<textarea name="reason" required minLength={5} maxLength={500} autoFocus /></label>
          <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setOwnerAction(null)}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? "Working…" : ownerAction === "reset" ? "Send secure link" : "Generate password"}</button></div>
        </form>}

        {temporaryPassword && <div className="temporary-password-result" role="status"><ShieldCheck size={19} /><div><strong>Copy this temporary password now</strong><code>{temporaryPassword}</code><small>It will not be shown again. The owner must change it before opening the gym portal.</small></div><button className="secondary-button" type="button" onClick={copyTemporaryPassword}><Copy size={15} /> Copy</button><button className="icon-button one-time-secret-dismiss" type="button" onClick={() => setTemporaryPassword(null)} aria-label="Dismiss temporary password"><X size={16} /></button></div>}
      </section>
      {lifecycleTarget && <form className="owner-action-form" onSubmit={submitLifecycle}><p><strong>{lifecycleTarget === "active" ? "Reactivate gym access" : lifecycleTarget === "suspended" ? "Suspend gym access" : "Archive and close gym"}</strong><br />{lifecycleTarget === "active" ? "Eligible gym users will regain access." : "All gym user logins will be blocked while historical data remains intact."}</p><label>Audit reason<textarea name="reason" required minLength={5} maxLength={1000} autoFocus /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setLifecycleTarget(null)}>Cancel</button><button className={lifecycleTarget === "active" ? "primary-button" : "danger-button"} disabled={busy}>{busy ? "Saving…" : "Confirm status change"}</button></div></form>}
      {hardDelete && <form className="owner-action-form destructive-confirmation" onSubmit={submitHardDelete}><p><strong>Permanent deletion is only allowed for an empty test gym.</strong> Any financial, membership, attendance, or meaningful audit history blocks this action.</p><label>Type the exact gym name<input name="confirmation" required autoComplete="off" /></label><label>Audit reason<textarea name="reason" required minLength={5} maxLength={1000} /></label><div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setHardDelete(false)}>Cancel</button><button className="danger-button" disabled={busy}>{busy ? "Checking…" : "Permanently delete"}</button></div></form>}
    </>}
  </ModalShell>;
}

function PlanManagementModal({ plan, onClose, onUpdate }: {
  plan: SaasPlanRecord;
  onClose: () => void;
  onUpdate: PlatformPortalData["onUpdatePlan"];
}) {
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const activePrice = plan.prices.find((price) => price.active) ?? plan.prices[0];

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget);
    const reason = String(form.get("reason"));
    const nextPrice = {
      currency: String(form.get("currency")) as GymSummary["base_currency"],
      billing_interval: String(form.get("billing_interval")) as "monthly" | "yearly",
      amount_minor: decimalToMinor(String(form.get("amount"))),
      trial_days: Number(form.get("trial_days")), reason,
    } satisfies NewSaasPlanPrice;
    const priceChanged = !activePrice || activePrice.currency !== nextPrice.currency || activePrice.billing_interval !== nextPrice.billing_interval || activePrice.amount_minor !== nextPrice.amount_minor || activePrice.trial_days !== nextPrice.trial_days;
    setBusy(true); setError(null);
    try {
      await onUpdate(plan.id, {
        name: String(form.get("name")), description: String(form.get("description")) || null,
        status: String(form.get("status")) as SaasPlanRecord["status"], sort_order: Number(form.get("sort_order")),
        feature_limits: {
          members: Number(form.get("members")), branches: Number(form.get("branches")), staff: Number(form.get("staff")),
          advanced_reports: form.get("advanced_reports") === "on", priority_support: form.get("priority_support") === "on",
        },
        payment_methods: [form.get("bank_transfer") === "on" ? "bank_transfer" : null, form.get("cash") === "on" ? "cash" : null, form.get("stripe") === "on" ? "stripe" : null].filter((method): method is "bank_transfer" | "cash" | "stripe" => method !== null),
        reason,
      }, priceChanged ? nextPrice : undefined);
      onClose();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The SaaS plan could not be updated."); }
    finally { setBusy(false); }
  }

  return <ModalShell title={editing ? `Edit ${plan.name}` : plan.name} eyebrow={editing ? "Audited catalogue change" : "SaaS plan details"} onClose={onClose}>
    {error && <div className="form-error" role="alert">{error}</div>}
    {editing ? <form onSubmit={submit}>
      <div className="field-pair"><label>Plan name<input name="name" required maxLength={160} defaultValue={plan.name} /></label><label>Status<select name="status" defaultValue={plan.status}><option value="draft">Draft / unpublished</option><option value="active">Active</option><option value="archived">Archived</option></select></label></div>
      <label>Description<textarea name="description" rows={2} maxLength={2000} defaultValue={plan.description ?? ""} /></label>
      <div className="field-trio"><label>Currency<select name="currency" defaultValue={activePrice?.currency ?? "GBP"}>{currencies.map((currency) => <option key={currency}>{currency}</option>)}</select></label><label>Interval<select name="billing_interval" defaultValue={activePrice?.billing_interval ?? "monthly"}><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></label><label>Price<input name="amount" required inputMode="decimal" defaultValue={activePrice ? (activePrice.amount_minor / 100).toFixed(2) : "0.00"} /></label></div>
      <div className="field-trio"><label>Member limit<input name="members" type="number" min="1" required defaultValue={plan.feature_limits.members} /></label><label>Branch limit<input name="branches" type="number" min="1" required defaultValue={plan.feature_limits.branches} /></label><label>Staff limit<input name="staff" type="number" min="1" required defaultValue={plan.feature_limits.staff} /></label></div>
      <div className="field-pair"><label>Trial days<input name="trial_days" type="number" min="0" max="90" defaultValue={activePrice?.trial_days ?? 0} /></label><label>Sort order<input name="sort_order" type="number" min="0" defaultValue={plan.sort_order} /></label></div>
      <div className="check-row"><label><input name="advanced_reports" type="checkbox" defaultChecked={plan.feature_limits.advanced_reports} /> Advanced reports</label><label><input name="priority_support" type="checkbox" defaultChecked={plan.feature_limits.priority_support} /> Priority support</label></div>
      <div className="check-row payment-method-checks"><label><input name="bank_transfer" type="checkbox" defaultChecked={plan.payment_methods.includes("bank_transfer")} /> Bank transfer</label><label><input name="cash" type="checkbox" defaultChecked={plan.payment_methods.includes("cash")} /> Cash</label><label><input name="stripe" type="checkbox" defaultChecked={plan.payment_methods.includes("stripe")} /> Stripe card</label></div>
      <label>Audit reason<textarea name="reason" required minLength={5} maxLength={1000} /></label>
      <div className="modal-note"><ShieldCheck size={17} />A changed price creates a new immutable price row. Existing subscriptions keep their accepted snapshots. Stripe remains optional.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setEditing(false)}>Cancel edit</button><button className="primary-button" disabled={busy}>{busy ? "Saving…" : "Save plan"}</button></div>
    </form> : <>
      <dl className="platform-detail-grid"><div><dt>Status</dt><dd>{readable(plan.status)}</dd></div><div><dt>Code</dt><dd>{plan.code}</dd></div><div><dt>Current price</dt><dd>{activePrice ? `${money(activePrice.amount_minor, activePrice.currency)} / ${activePrice.billing_interval}` : "No price"}</dd></div><div><dt>Payments</dt><dd>{plan.payment_methods.map(readable).join(", ")}</dd></div><div><dt>Members</dt><dd>{plan.feature_limits.members.toLocaleString()}</dd></div><div><dt>Branches / staff</dt><dd>{plan.feature_limits.branches} / {plan.feature_limits.staff}</dd></div></dl>
      <p className="platform-detail-copy">{plan.description ?? "No description"}</p>
      <div className="platform-management-actions"><button className="secondary-button" onClick={() => setEditing(true)}><Pencil size={15} /> Edit plan</button></div>
      <div className="modal-note"><Archive size={17} />Use Edit plan to deactivate, unpublish or archive it without changing historical subscriptions.</div>
    </>}
  </ModalShell>;
}

export function PlatformPortal({ data }: { data: PlatformPortalData }) {
  const [view, setView] = useState<PlatformView>("overview");
  const [query, setQuery] = useState("");
  const [menuOpen, setMenuOpen] = useState(false);
  const [gymModal, setGymModal] = useState(false);
  const [planModal, setPlanModal] = useState(false);
  const [selectedGym, setSelectedGym] = useState<GymSummary | null>(null);
  const [selectedPlan, setSelectedPlan] = useState<SaasPlanRecord | null>(null);
  const [securityOpen, setSecurityOpen] = useState(false);
  const [profileOpen, setProfileOpen] = useState(false);
  const [archivedGyms, setArchivedGyms] = useState(false);
  const filteredGyms = useMemo(() => data.gyms.filter((gym) => (archivedGyms ? gym.status === "cancelled" : gym.status !== "cancelled") && `${gym.name} ${gym.slug} ${gym.country_code} ${gym.status}`.toLowerCase().includes(query.toLowerCase())), [archivedGyms, data.gyms, query]);
  const activeGyms = data.gyms.filter((gym) => gym.status === "active").length;
  const trials = data.gyms.filter((gym) => gym.status === "trial").length;
  const attention = data.gyms.filter((gym) => ["past_due", "suspended"].includes(gym.status)).length;
  const navigation: Array<{ id: PlatformView; label: string; icon: typeof LayoutDashboard }> = [
    { id: "overview", label: "Overview", icon: LayoutDashboard },
    { id: "gyms", label: "Gyms", icon: Building2 },
    { id: "members", label: "Global members", icon: UsersRound },
    { id: "plans", label: "SaaS plans", icon: CircleDollarSign },
    { id: "billing", label: "Billing dashboard", icon: CircleDollarSign },
    { id: "analytics", label: "Analytics", icon: BarChart3 },
    { id: "audit", label: "Audit Log", icon: FileClock },
    { id: "settings", label: "Settings", icon: Settings },
  ];

  function navigate(next: PlatformView) { setView(next); setQuery(""); setMenuOpen(false); }

  return <div className="platform-shell">
    <button className={`sidebar-scrim ${menuOpen ? "show" : ""}`} onClick={() => setMenuOpen(false)} aria-label="Close navigation" />
    <aside className={`platform-sidebar ${menuOpen ? "open" : ""}`}>
      <div className="platform-brand"><span>IC</span><strong>IRONCORE</strong><button className="icon-button platform-menu-close" onClick={() => setMenuOpen(false)} aria-label="Close navigation"><X size={18} /></button></div>
      <p className="nav-eyebrow">Super Admin portal</p>
      <nav aria-label="Platform navigation">{navigation.map((item) => { const Icon = item.icon; return <button key={item.id} className={view === item.id ? "active" : ""} onClick={() => navigate(item.id)}><Icon size={18} />{item.label}</button>; })}</nav>
      <div className="platform-sidebar-foot"><button onClick={() => setSecurityOpen(true)}><ShieldCheck size={17} /> Account security</button><button onClick={data.onLogout}><LogOut size={17} /> Sign out</button></div>
    </aside>
    <section className="platform-main">
      <header className="platform-topbar"><div><button className="icon-button platform-menu-button" onClick={() => setMenuOpen(true)} aria-label="Open navigation"><Menu size={20} /></button><span>Platform control</span><h1>{navigation.find((item) => item.id === view)?.label}</h1></div><div>{view === "gyms" && <label className="search-box"><Search size={17} /><input aria-label="Search gyms" placeholder="Search gyms" value={query} onChange={(event) => setQuery(event.target.value)} /></label>}<button className="icon-button" onClick={data.onReload} aria-label="Refresh platform data"><RefreshCw className={data.loading ? "spin" : ""} size={18} /></button><div className="profile-wrap platform-profile-wrap"><button className="platform-profile profile-button" onClick={() => setProfileOpen((open) => !open)} aria-label="Open account menu" aria-haspopup="menu" aria-expanded={profileOpen}><span>{initials(data.user.name)}</span><strong>{data.user.name}</strong><ChevronDown size={15} /></button>{profileOpen && <div className="profile-popover" role="menu"><button type="button" className="profile-summary" onClick={() => { setProfileOpen(false); setSecurityOpen(true); }}><span className="profile-summary-avatar">{initials(data.user.name)}</span><span><strong>{data.user.name}</strong><small>Super Admin</small></span></button><button onClick={() => { setProfileOpen(false); setSecurityOpen(true); }}><ShieldCheck size={17} /> Account security</button><button className="danger" onClick={() => { setProfileOpen(false); data.onLogout(); }}><LogOut size={17} /> Sign out</button></div>}</div></div></header>
      <main className="platform-content">
        {data.error && <div className="form-error" role="alert">{data.error}</div>}
        {view === "overview" && <>
          <section className="platform-welcome"><div><p className="eyebrow">Authenticated platform access</p><h2>Welcome back, {data.user.name.split(" ")[0]}.</h2><p>Manage gyms and the IronCore subscription catalogue from live API records.</p></div><button className="primary-button" onClick={() => setGymModal(true)}><Plus size={17} /> Create gym</button></section>
          <section className="platform-metrics"><article><Building2 /><span><small>Total gyms</small><strong>{data.gyms.length}</strong></span></article><article><ShieldCheck /><span><small>Active</small><strong>{activeGyms}</strong></span></article><article><UsersRound /><span><small>On trial</small><strong>{trials}</strong></span></article><article><CircleDollarSign /><span><small>Need attention</small><strong>{attention}</strong></span></article></section>
          <section className="platform-grid"><article className="panel"><div className="panel-title"><div><p className="eyebrow">Tenant registry</p><h3>Recently available gyms</h3></div><button className="secondary-button" onClick={() => navigate("gyms")}>View all</button></div><div className="platform-quick-list">{data.gyms.slice(0, 5).map((gym) => <button key={gym.id} onClick={() => data.onOpenGym(gym)}><span>{initials(gym.name)}</span><div><strong>{gym.name}</strong><small>{gym.country_code} · {readable(gym.status)}</small></div><ArrowRight size={16} /></button>)}{!data.loading && data.gyms.length === 0 && <p>No gyms have been created yet.</p>}</div></article><article className="panel"><div className="panel-title"><div><p className="eyebrow">Product catalogue</p><h3>Active SaaS plans</h3></div><button className="secondary-button" onClick={() => navigate("plans")}>Manage</button></div><div className="platform-plan-summary"><strong>{data.plans.filter((plan) => plan.status === "active").length}</strong><span>active tiers</span><p>Prices are immutable and controlled only by Super Admin accounts.</p><button className="primary-button" onClick={() => setPlanModal(true)}><Plus size={16} /> Publish plan</button></div></article></section>
        </>}
        {view === "gyms" && <><section className="module-heading"><div><p className="eyebrow">Tenant registry</p><h2>Gyms</h2><p>View, edit, activate, deactivate or safely archive a gym with a recorded reason.</p></div><button className="primary-button" onClick={() => setGymModal(true)}><Plus size={17} /> Create gym</button></section><div className="billing-toggle gym-list-toggle" role="group" aria-label="Gym lifecycle list"><button className={!archivedGyms ? "active" : ""} onClick={() => setArchivedGyms(false)}>Current gyms</button><button className={archivedGyms ? "active" : ""} onClick={() => setArchivedGyms(true)}>Archived gyms</button></div><section className="panel table-scroll">{data.loading ? <div className="table-state"><LoaderCircle className="spin" size={20} /> Loading gyms…</div> : <table className="data-table"><thead><tr><th>Gym</th><th>Country</th><th>Currency</th><th>Status</th><th /></tr></thead><tbody>{filteredGyms.map((gym) => <tr key={gym.id}><td><strong>{gym.name}</strong><small className="table-sub">{gym.slug}</small></td><td>{gym.country_code}</td><td>{gym.base_currency}</td><td><span className={`status ${gym.status}`}><i />{readable(gym.status)}</span></td><td><div className="table-action-group"><button className="table-action" onClick={() => setSelectedGym(gym)}><Eye size={13} /> View / manage</button>{gym.status !== "cancelled" && <button className="table-action" onClick={() => data.onOpenGym(gym)}>Open <ArrowRight size={13} /></button>}</div></td></tr>)}</tbody></table>}{!data.loading && filteredGyms.length === 0 && <div className="empty-state"><Search size={23} /><strong>{archivedGyms ? "No archived gyms" : "No gyms found"}</strong><span>{archivedGyms ? "Archived tenants stay here with their history retained." : "Change the search or create the first gym."}</span></div>}</section></>}
        {view === "members" && <PlatformMemberDirectory gyms={data.gyms} load={data.onLoadMembers} exportRows={data.onExportMembers} />}
        {view === "plans" && <><section className="module-heading"><div><p className="eyebrow">Platform billing</p><h2>SaaS plans</h2><p>Manage catalogue details and append-only prices without changing accepted subscription history.</p></div><button className="primary-button" onClick={() => setPlanModal(true)}><Plus size={17} /> Publish plan</button></section><section className="platform-plan-grid">{data.plans.map((plan) => <article className="panel" key={plan.id}><div><span className={`status ${plan.status}`}><i />{readable(plan.status)}</span><small>{plan.code}</small></div><h3>{plan.name}</h3><p>{plan.description ?? "No description"}</p><ul>{plan.prices.filter((price) => price.active).map((price) => <li key={price.id}><strong>{money(price.amount_minor, price.currency)}</strong><span>/{price.billing_interval === "monthly" ? "month" : "year"}</span></li>)}</ul><small>{plan.feature_limits.members.toLocaleString()} members · {plan.feature_limits.branches.toLocaleString()} branches · {plan.feature_limits.staff.toLocaleString()} staff</small><small>Payments: {plan.payment_methods.map(readable).join(" · ")}</small><div className="platform-card-actions"><button className="secondary-button" onClick={() => setSelectedPlan(plan)}><Eye size={14} /> View / edit</button></div></article>)}{!data.loading && data.plans.length === 0 && <div className="empty-state panel"><CircleDollarSign size={24} /><strong>No plans published</strong><span>Create the first platform plan and immutable price.</span></div>}</section></>}
        {view === "billing" && <PlatformBillingDashboard gyms={data.gyms} plans={data.plans} load={data.onLoadBilling} onOpenGym={data.onOpenGym} />}
        {view === "analytics" && <PlatformAnalytics load={data.onLoadAnalytics} />}
        {view === "audit" && <AuditLogManagement gyms={data.gyms} onLoad={data.onLoadAudit} onExport={data.onExportAudit} />}
        {view === "settings" && <><section className="module-heading"><div><p className="eyebrow">Platform settings</p><h2>Settings</h2><p>Tenant currencies and timezones are managed per gym; account security stays platform-wide.</p></div></section><section className="platform-settings-grid"><article className="panel"><CircleDollarSign size={21} /><h3>Tenant currency settings</h3><p>Each gym keeps its own current base currency. Changing Gym A never changes Gym B or any historical transaction snapshot.</p><div className="platform-settings-list">{data.gyms.map((gym) => <button key={gym.id} onClick={() => setSelectedGym(gym)}><span><strong>{gym.name}</strong><small>{gym.timezone}</small></span><b>{gym.base_currency}</b><Pencil size={14} /></button>)}</div></article><article className="panel"><ShieldCheck size={21} /><h3>Account security</h3><p>Manage your password, authenticator and recovery codes separately from tenant business settings.</p><button className="secondary-button" onClick={() => setSecurityOpen(true)}>Open account security</button></article><article className="panel"><Power size={21} /><h3>Provider status</h3><p>Stripe remains optional. Cash and bank transfer continue independently; credentials stay in deployment configuration, never this browser.</p></article></section></>}
      </main>
    </section>
    {gymModal && <CreateGymModal onClose={() => setGymModal(false)} onCreate={data.onCreateGym} onOpen={data.onOpenGym} />}
    {planModal && <CreatePlanModal onClose={() => setPlanModal(false)} onCreate={data.onCreatePlan} />}
    {selectedGym && <GymManagementModal gym={selectedGym} onClose={() => setSelectedGym(null)} onOpen={() => data.onOpenGym(selectedGym)} onUpdate={data.onUpdateGym} onDelete={data.onDeleteGym} onLoadOwner={data.onLoadGymOwner} onCreateOwner={data.onCreateGymOwner} onUpdateOwner={data.onUpdateGymOwner} onSendReset={data.onSendGymOwnerReset} onGenerateTemporary={data.onGenerateGymOwnerTemporaryPassword} />}
    {selectedPlan && <PlanManagementModal plan={selectedPlan} onClose={() => setSelectedPlan(null)} onUpdate={data.onUpdatePlan} />}
    {securityOpen && <AccountSecurityDialog onClose={() => setSecurityOpen(false)} onChangePassword={data.onChangePassword} mfa={data.mfa} />}
  </div>;
}
