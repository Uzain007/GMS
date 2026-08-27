"use client";

import {
  Archive, ArrowRight, Building2, CircleDollarSign, Eye, LayoutDashboard, LoaderCircle,
  LogOut, Menu, Pencil, Plus, Power, RefreshCw, Search, Settings, ShieldCheck, UsersRound, X,
} from "lucide-react";
import { FormEvent, useMemo, useState } from "react";
import { AccountSecurityDialog, type MfaActions } from "./account-security";
import type {
  AuthenticatedUser, GymSummary, NewGym, NewSaasPlan, NewSaasPlanPrice,
  SaasPlanRecord, UpdateGym, UpdateSaasPlan,
} from "./lib/ironcore-api";
import { decimalToMinor } from "./tenant-operations";

type PlatformView = "overview" | "gyms" | "plans" | "settings";

export type PlatformPortalData = {
  user: AuthenticatedUser;
  gyms: GymSummary[];
  plans: SaasPlanRecord[];
  loading: boolean;
  error: string | null;
  onReload: () => void;
  onOpenGym: (gym: GymSummary) => void;
  onCreateGym: (input: NewGym) => Promise<void>;
  onUpdateGym: (gymId: string, input: UpdateGym) => Promise<void>;
  onCreatePlan: (input: NewSaasPlan) => Promise<void>;
  onUpdatePlan: (planId: string, input: UpdateSaasPlan, price?: NewSaasPlanPrice) => Promise<void>;
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

function CreateGymModal({ onClose, onCreate }: { onClose: () => void; onCreate: PlatformPortalData["onCreateGym"] }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    try {
      await onCreate({
        name: String(form.get("name")),
        legal_name: String(form.get("legal_name")) || undefined,
        base_currency: String(form.get("base_currency")) as GymSummary["base_currency"],
        country_code: String(form.get("country_code")).toUpperCase(),
        timezone: String(form.get("timezone")),
        owner: { name: String(form.get("owner_name")), email: String(form.get("owner_email")) },
      });
      onClose();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The gym could not be created.");
    } finally {
      setBusy(false);
    }
  }

  return <ModalShell title="Create a gym" eyebrow="Platform tenant onboarding" onClose={onClose}>
    <form onSubmit={submit}>{error && <div className="form-error" role="alert">{error}</div>}
      <div className="field-pair"><label>Gym name<input name="name" maxLength={160} required autoFocus /></label><label>Legal name<input name="legal_name" maxLength={200} /></label></div>
      <div className="field-trio"><label>Currency<select name="base_currency" defaultValue="GBP">{currencies.map((currency) => <option key={currency}>{currency}</option>)}</select></label><label>Country code<input name="country_code" required minLength={2} maxLength={2} defaultValue="GB" pattern="[A-Za-z]{2}" /></label><label>Timezone<input name="timezone" required defaultValue="Europe/London" placeholder="Europe/London" /></label></div>
      <div className="field-pair"><label>Gym owner name<input name="owner_name" maxLength={160} required /></label><label>Gym owner email<input name="owner_email" type="email" maxLength={254} required /></label></div>
      <div className="modal-note"><ShieldCheck size={17} />Laravel creates the trial gym and tenant owner membership atomically. The browser never assigns tenant authority.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy ? <><LoaderCircle className="spin" size={16} /> Creating…</> : <>Create gym <ArrowRight size={16} /></>}</button></div>
    </form>
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

function GymManagementModal({ gym, onClose, onOpen, onUpdate }: {
  gym: GymSummary;
  onClose: () => void;
  onOpen: () => void;
  onUpdate: PlatformPortalData["onUpdateGym"];
}) {
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

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

  return <ModalShell title={editing ? `Edit ${gym.name}` : gym.name} eyebrow={editing ? "Audited tenant settings" : "Gym details"} onClose={onClose}>
    {error && <div className="form-error" role="alert">{error}</div>}
    {editing ? <form onSubmit={submit}>
      <div className="field-pair"><label>Gym name<input name="name" required maxLength={160} defaultValue={gym.name} /></label><label>Legal name<input name="legal_name" maxLength={200} defaultValue={gym.legal_name ?? ""} /></label></div>
      <div className="field-trio"><label>Currency<select name="base_currency" defaultValue={gym.base_currency}>{currencies.map((currency) => <option key={currency}>{currency}</option>)}</select></label><label>Country code<input name="country_code" required minLength={2} maxLength={2} defaultValue={gym.country_code} /></label><label>Timezone<input name="timezone" required defaultValue={gym.timezone} /></label></div>
      <label>Tenant status<select name="status" defaultValue={gym.status}><option value="trial">Trial</option><option value="active">Active</option><option value="past_due">Past due</option><option value="suspended">Deactivated / suspended</option><option value="cancelled">Archived / cancelled</option></select></label>
      <label>Audit reason<textarea name="reason" required minLength={5} maxLength={500} placeholder="Explain this tenant or settings change" /></label>
      <div className="modal-note"><ShieldCheck size={17} />Currency belongs to this gym only. Existing payments and memberships retain their historical currency snapshots.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={() => setEditing(false)}>Cancel edit</button><button className="primary-button" disabled={busy}>{busy ? "Saving…" : "Save gym"}</button></div>
    </form> : <>
      <dl className="platform-detail-grid"><div><dt>Status</dt><dd>{readable(gym.status)}</dd></div><div><dt>Currency</dt><dd>{gym.base_currency}</dd></div><div><dt>Country</dt><dd>{gym.country_code}</dd></div><div><dt>Timezone</dt><dd>{gym.timezone}</dd></div><div><dt>Slug</dt><dd>{gym.slug}</dd></div><div><dt>Legal name</dt><dd>{gym.legal_name ?? "Not set"}</dd></div></dl>
      <div className="platform-management-actions"><button className="secondary-button" onClick={() => setEditing(true)}><Pencil size={15} /> Edit gym</button><button className="secondary-button" onClick={onOpen}><ArrowRight size={15} /> Open gym</button></div>
      <div className="modal-note"><Archive size={17} />Use Edit gym to activate, deactivate or archive this tenant. IronCore retains the audit and billing history.</div>
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
  const filteredGyms = useMemo(() => data.gyms.filter((gym) => `${gym.name} ${gym.slug} ${gym.country_code} ${gym.status}`.toLowerCase().includes(query.toLowerCase())), [data.gyms, query]);
  const activeGyms = data.gyms.filter((gym) => gym.status === "active").length;
  const trials = data.gyms.filter((gym) => gym.status === "trial").length;
  const attention = data.gyms.filter((gym) => ["past_due", "suspended"].includes(gym.status)).length;
  const navigation: Array<{ id: PlatformView; label: string; icon: typeof LayoutDashboard }> = [
    { id: "overview", label: "Overview", icon: LayoutDashboard },
    { id: "gyms", label: "Gyms", icon: Building2 },
    { id: "plans", label: "SaaS plans", icon: CircleDollarSign },
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
      <header className="platform-topbar"><div><button className="icon-button platform-menu-button" onClick={() => setMenuOpen(true)} aria-label="Open navigation"><Menu size={20} /></button><span>Platform control</span><h1>{navigation.find((item) => item.id === view)?.label}</h1></div><div>{view === "gyms" && <label className="search-box"><Search size={17} /><input aria-label="Search gyms" placeholder="Search gyms" value={query} onChange={(event) => setQuery(event.target.value)} /></label>}<button className="icon-button" onClick={data.onReload} aria-label="Refresh platform data"><RefreshCw className={data.loading ? "spin" : ""} size={18} /></button><button className="platform-profile" onClick={() => setSecurityOpen(true)}><span>{initials(data.user.name)}</span><strong>{data.user.name}</strong></button></div></header>
      <main className="platform-content">
        {data.error && <div className="form-error" role="alert">{data.error}</div>}
        {view === "overview" && <>
          <section className="platform-welcome"><div><p className="eyebrow">Authenticated platform access</p><h2>Welcome back, {data.user.name.split(" ")[0]}.</h2><p>Manage gyms and the IronCore subscription catalogue from live API records.</p></div><button className="primary-button" onClick={() => setGymModal(true)}><Plus size={17} /> Create gym</button></section>
          <section className="platform-metrics"><article><Building2 /><span><small>Total gyms</small><strong>{data.gyms.length}</strong></span></article><article><ShieldCheck /><span><small>Active</small><strong>{activeGyms}</strong></span></article><article><UsersRound /><span><small>On trial</small><strong>{trials}</strong></span></article><article><CircleDollarSign /><span><small>Need attention</small><strong>{attention}</strong></span></article></section>
          <section className="platform-grid"><article className="panel"><div className="panel-title"><div><p className="eyebrow">Tenant registry</p><h3>Recently available gyms</h3></div><button className="secondary-button" onClick={() => navigate("gyms")}>View all</button></div><div className="platform-quick-list">{data.gyms.slice(0, 5).map((gym) => <button key={gym.id} onClick={() => data.onOpenGym(gym)}><span>{initials(gym.name)}</span><div><strong>{gym.name}</strong><small>{gym.country_code} · {readable(gym.status)}</small></div><ArrowRight size={16} /></button>)}{!data.loading && data.gyms.length === 0 && <p>No gyms have been created yet.</p>}</div></article><article className="panel"><div className="panel-title"><div><p className="eyebrow">Product catalogue</p><h3>Active SaaS plans</h3></div><button className="secondary-button" onClick={() => navigate("plans")}>Manage</button></div><div className="platform-plan-summary"><strong>{data.plans.filter((plan) => plan.status === "active").length}</strong><span>active tiers</span><p>Prices are immutable and controlled only by Super Admin accounts.</p><button className="primary-button" onClick={() => setPlanModal(true)}><Plus size={16} /> Publish plan</button></div></article></section>
        </>}
        {view === "gyms" && <><section className="module-heading"><div><p className="eyebrow">Tenant registry</p><h2>Gyms</h2><p>View, edit, activate, deactivate or safely archive a gym with a recorded reason.</p></div><button className="primary-button" onClick={() => setGymModal(true)}><Plus size={17} /> Create gym</button></section><section className="panel table-scroll">{data.loading ? <div className="table-state"><LoaderCircle className="spin" size={20} /> Loading gyms…</div> : <table className="data-table"><thead><tr><th>Gym</th><th>Country</th><th>Currency</th><th>Status</th><th /></tr></thead><tbody>{filteredGyms.map((gym) => <tr key={gym.id}><td><strong>{gym.name}</strong><small className="table-sub">{gym.slug}</small></td><td>{gym.country_code}</td><td>{gym.base_currency}</td><td><span className={`status ${gym.status}`}><i />{readable(gym.status)}</span></td><td><div className="table-action-group"><button className="table-action" onClick={() => setSelectedGym(gym)}><Eye size={13} /> View / manage</button><button className="table-action" onClick={() => data.onOpenGym(gym)}>Open <ArrowRight size={13} /></button></div></td></tr>)}</tbody></table>}{!data.loading && filteredGyms.length === 0 && <div className="empty-state"><Search size={23} /><strong>No gyms found</strong><span>Change the search or create the first gym.</span></div>}</section></>}
        {view === "plans" && <><section className="module-heading"><div><p className="eyebrow">Platform billing</p><h2>SaaS plans</h2><p>Manage catalogue details and append-only prices without changing accepted subscription history.</p></div><button className="primary-button" onClick={() => setPlanModal(true)}><Plus size={17} /> Publish plan</button></section><section className="platform-plan-grid">{data.plans.map((plan) => <article className="panel" key={plan.id}><div><span className={`status ${plan.status}`}><i />{readable(plan.status)}</span><small>{plan.code}</small></div><h3>{plan.name}</h3><p>{plan.description ?? "No description"}</p><ul>{plan.prices.filter((price) => price.active).map((price) => <li key={price.id}><strong>{money(price.amount_minor, price.currency)}</strong><span>/{price.billing_interval === "monthly" ? "month" : "year"}</span></li>)}</ul><small>{plan.feature_limits.members.toLocaleString()} members · {plan.feature_limits.branches.toLocaleString()} branches · {plan.feature_limits.staff.toLocaleString()} staff</small><small>Payments: {plan.payment_methods.map(readable).join(" · ")}</small><div className="platform-card-actions"><button className="secondary-button" onClick={() => setSelectedPlan(plan)}><Eye size={14} /> View / edit</button></div></article>)}{!data.loading && data.plans.length === 0 && <div className="empty-state panel"><CircleDollarSign size={24} /><strong>No plans published</strong><span>Create the first platform plan and immutable price.</span></div>}</section></>}
        {view === "settings" && <><section className="module-heading"><div><p className="eyebrow">Platform settings</p><h2>Settings</h2><p>Tenant currencies and timezones are managed per gym; account security stays platform-wide.</p></div></section><section className="platform-settings-grid"><article className="panel"><CircleDollarSign size={21} /><h3>Tenant currency settings</h3><p>Each gym keeps its own current base currency. Changing Gym A never changes Gym B or any historical transaction snapshot.</p><div className="platform-settings-list">{data.gyms.map((gym) => <button key={gym.id} onClick={() => setSelectedGym(gym)}><span><strong>{gym.name}</strong><small>{gym.timezone}</small></span><b>{gym.base_currency}</b><Pencil size={14} /></button>)}</div></article><article className="panel"><ShieldCheck size={21} /><h3>Account security</h3><p>Manage your password, authenticator and recovery codes separately from tenant business settings.</p><button className="secondary-button" onClick={() => setSecurityOpen(true)}>Open account security</button></article><article className="panel"><Power size={21} /><h3>Provider status</h3><p>Stripe remains optional. Cash and bank transfer continue independently; credentials stay in deployment configuration, never this browser.</p></article></section></>}
      </main>
    </section>
    {gymModal && <CreateGymModal onClose={() => setGymModal(false)} onCreate={data.onCreateGym} />}
    {planModal && <CreatePlanModal onClose={() => setPlanModal(false)} onCreate={data.onCreatePlan} />}
    {selectedGym && <GymManagementModal gym={selectedGym} onClose={() => setSelectedGym(null)} onOpen={() => data.onOpenGym(selectedGym)} onUpdate={data.onUpdateGym} />}
    {selectedPlan && <PlanManagementModal plan={selectedPlan} onClose={() => setSelectedPlan(null)} onUpdate={data.onUpdatePlan} />}
    {securityOpen && <AccountSecurityDialog onClose={() => setSecurityOpen(false)} onChangePassword={data.onChangePassword} mfa={data.mfa} />}
  </div>;
}
