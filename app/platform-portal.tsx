"use client";

import {
  ArrowRight, Building2, CircleDollarSign, LayoutDashboard, LoaderCircle,
  LogOut, Menu, Plus, RefreshCw, Search, ShieldCheck, UsersRound, X,
} from "lucide-react";
import { FormEvent, useMemo, useState } from "react";
import { AccountSecurityDialog, type MfaActions } from "./account-security";
import type {
  AuthenticatedUser, GymSummary, NewGym, NewSaasPlan, SaasPlanRecord,
} from "./lib/ironcore-api";
import { decimalToMinor } from "./tenant-operations";

type PlatformView = "overview" | "gyms" | "plans";

export type PlatformPortalData = {
  user: AuthenticatedUser;
  gyms: GymSummary[];
  plans: SaasPlanRecord[];
  loading: boolean;
  error: string | null;
  onReload: () => void;
  onOpenGym: (gym: GymSummary) => void;
  onCreateGym: (input: NewGym) => Promise<void>;
  onCreatePlan: (input: NewSaasPlan) => Promise<void>;
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
      <div className="modal-heading"><span><Building2 size={21} /></span><div><p className="eyebrow">{eyebrow}</p><h2>{title}</h2></div><button className="icon-button" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>
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
      <div className="modal-note"><ShieldCheck size={17} />The API creates an immutable Stripe-backed price. Later price changes append history instead of overwriting it.</div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit" disabled={busy}>{busy ? <><LoaderCircle className="spin" size={16} /> Publishing…</> : <>Publish plan <ArrowRight size={16} /></>}</button></div>
    </form>
  </ModalShell>;
}

export function PlatformPortal({ data }: { data: PlatformPortalData }) {
  const [view, setView] = useState<PlatformView>("overview");
  const [query, setQuery] = useState("");
  const [menuOpen, setMenuOpen] = useState(false);
  const [gymModal, setGymModal] = useState(false);
  const [planModal, setPlanModal] = useState(false);
  const [securityOpen, setSecurityOpen] = useState(false);
  const filteredGyms = useMemo(() => data.gyms.filter((gym) => `${gym.name} ${gym.slug} ${gym.country_code} ${gym.status}`.toLowerCase().includes(query.toLowerCase())), [data.gyms, query]);
  const activeGyms = data.gyms.filter((gym) => gym.status === "active").length;
  const trials = data.gyms.filter((gym) => gym.status === "trial").length;
  const attention = data.gyms.filter((gym) => ["past_due", "suspended"].includes(gym.status)).length;
  const navigation: Array<{ id: PlatformView; label: string; icon: typeof LayoutDashboard }> = [
    { id: "overview", label: "Overview", icon: LayoutDashboard },
    { id: "gyms", label: "Gyms", icon: Building2 },
    { id: "plans", label: "SaaS plans", icon: CircleDollarSign },
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
        {view === "gyms" && <><section className="module-heading"><div><p className="eyebrow">Tenant registry</p><h2>Gyms</h2><p>Open an explicit tenant workspace or create a new trial gym and owner.</p></div><button className="primary-button" onClick={() => setGymModal(true)}><Plus size={17} /> Create gym</button></section><section className="panel table-scroll">{data.loading ? <div className="table-state"><LoaderCircle className="spin" size={20} /> Loading gyms…</div> : <table className="data-table"><thead><tr><th>Gym</th><th>Country</th><th>Currency</th><th>Status</th><th /></tr></thead><tbody>{filteredGyms.map((gym) => <tr key={gym.id}><td><strong>{gym.name}</strong><small className="table-sub">{gym.slug}</small></td><td>{gym.country_code}</td><td>{gym.base_currency}</td><td><span className={`status ${gym.status}`}><i />{readable(gym.status)}</span></td><td><button className="table-action" onClick={() => data.onOpenGym(gym)}>Open gym <ArrowRight size={14} /></button></td></tr>)}</tbody></table>}{!data.loading && filteredGyms.length === 0 && <div className="empty-state"><Search size={23} /><strong>No gyms found</strong><span>Change the search or create the first gym.</span></div>}</section></>}
        {view === "plans" && <><section className="module-heading"><div><p className="eyebrow">Platform billing</p><h2>SaaS plans</h2><p>Publish immutable recurring prices for the IronCore platform account.</p></div><button className="primary-button" onClick={() => setPlanModal(true)}><Plus size={17} /> Publish plan</button></section><section className="platform-plan-grid">{data.plans.map((plan) => <article className="panel" key={plan.id}><div><span className={`status ${plan.status}`}><i />{readable(plan.status)}</span><small>{plan.code}</small></div><h3>{plan.name}</h3><p>{plan.description ?? "No description"}</p><ul>{plan.prices.filter((price) => price.active).map((price) => <li key={price.id}><strong>{money(price.amount_minor, price.currency)}</strong><span>/{price.billing_interval === "monthly" ? "month" : "year"}</span></li>)}</ul><small>{plan.feature_limits.members.toLocaleString()} members · {plan.feature_limits.branches.toLocaleString()} branches · {plan.feature_limits.staff.toLocaleString()} staff</small></article>)}{!data.loading && data.plans.length === 0 && <div className="empty-state panel"><CircleDollarSign size={24} /><strong>No plans published</strong><span>Create the first platform plan and immutable price.</span></div>}</section></>}
      </main>
    </section>
    {gymModal && <CreateGymModal onClose={() => setGymModal(false)} onCreate={data.onCreateGym} />}
    {planModal && <CreatePlanModal onClose={() => setPlanModal(false)} onCreate={data.onCreatePlan} />}
    {securityOpen && <AccountSecurityDialog onClose={() => setSecurityOpen(false)} onChangePassword={data.onChangePassword} mfa={data.mfa} />}
  </div>;
}
