"use client";

import {
  Activity, ArrowDownRight, ArrowUpRight, BarChart3, Bell, Building2,
  CalendarDays, Check, ChevronDown, CircleDollarSign, Clock3, CreditCard,
  Download, Dumbbell, FileBarChart, FileSpreadsheet, Gauge, Landmark, LayoutDashboard, LogOut, Menu, MoreHorizontal, Pencil, Plus,
  Eye, HeartPulse, ReceiptText, RefreshCw, Search, Settings, ShieldCheck, Sparkles, TrendingUp, Users,
  Upload, UsersRound, WalletCards, X, type LucideIcon,
} from "lucide-react";
import { FormEvent, useMemo, useState } from "react";
import { BranchesView, MembershipsView, PlansView, type OperationData } from "./tenant-operations";
import { FinancialManagement, type FinanceData } from "./financial-management";
import { SaasBillingManagement, type SaasBillingData } from "./saas-billing-management";
import { StaffManagement, type StaffData } from "./staff-management";
import { EngagementManagement, type EngagementData } from "./engagement-management";
import { CoachingManagement, type CoachingData } from "./coaching-management";
import { ReportManagement, type ReportData } from "./report-management";
import { GymClientOverview, type GymOverviewData } from "./gym-client-overview";
import { AccountSecurityDialog, type MfaActions } from "./account-security";
import type { GymBankTransferSetting, GymSummary, MemberImportRecord, StaffRecord, UpdateGym, UpdateGymBankTransferSetting, UpdateOwnStaffProfile } from "./lib/ironcore-api";
import { countryOptions, defaultTimezoneForCountry, normalizeCountryCode, normalizeTimezone, timezoneOptions } from "./gym-location-options";
import { SearchableSelect } from "./searchable-select";

export type View = "overview" | "gym-dashboard" | "gyms" | "members" | "branches" | "plans" | "memberships" | "attendance" | "coaching" | "payments" | "billing" | "reports" | "staff" | "trainer-profile" | "settings";
type Currency = "GBP" | "USD" | "PKR" | "AED" | "SAR";
type Gym = { name: string; location: string; initials: string; members: number; plan: string; revenueGbp: number; status: "Healthy" | "Attention" | "Trial"; accent: string };
export type DashboardMember = { id: string; name: string; gym: string; membership: string; memberCode?: string; homeBranchId?: string | null; joined: string; status: string; statusValue?: "lead" | "active" | "paused" | "cancelled" | "archived"; firstName?: string; lastName?: string; email?: string | null; phone?: string | null; accountLinked?: boolean };
export type DashboardMemberProfile = DashboardMember & { currentMembership: { planName: string; planCode: string; branchName: string | null; status: "pending" | "active" | "paused" | "cancelled" | "expired"; startsAt: string; endsAt: string | null; nextBillingAt: string | null; priceMinor: number; currency: Currency; autoRenew: boolean; isInDate: boolean } | null };
export type NewDashboardMember = { first_name: string; last_name: string; email: string; phone: string; status?: "lead" | "active" };
export type UpdateDashboardMember = { first_name: string; last_name: string; email: string; phone: string; status: NonNullable<DashboardMember["statusValue"]>; reason: string };
type LiveMembers = { rows: DashboardMember[]; total: number; loading: boolean; error: string | null; onSearch: (query: string) => void; onReload: () => void; onView?: (memberId: string) => Promise<DashboardMemberProfile>; onInvitePortal?: (memberId: string) => Promise<string>; onUpdate?: (memberId: string, member: UpdateDashboardMember) => Promise<void>; onPreviewImport?: (file: File) => Promise<MemberImportRecord>; onConfirmImport?: (importId: string) => Promise<MemberImportRecord>; onPollImport?: (importId: string) => Promise<MemberImportRecord>; onDownloadTemplate?: (format: "csv" | "xlsx") => Promise<void>; onExport?: () => Promise<void> };
export type GymSettingsData = { gym: GymSummary; canManage: boolean; bankTransferSetting: GymBankTransferSetting | null; onUpdate: (input: UpdateGym) => Promise<void>; onUpdateBankTransferSetting: (input: UpdateGymBankTransferSetting) => Promise<void> };
export type TrainerProfileData = { profile: StaffRecord | null; branchName: string | null; loading: boolean; error: string | null; onUpdate: (input: UpdateOwnStaffProfile) => Promise<void> };
type DashboardProps = {
  portalMode?: "platform" | "gym";
  operator?: { name: string; role: string };
  activeGym?: { id: string; name: string };
  gymOptions?: Array<{ id: string; name: string }>;
  onGymChange?: (gymId: string) => void;
  onLogout?: () => void;
  onChangePassword?: (currentPassword: string, password: string) => Promise<void>;
  mfa?: MfaActions;
  liveMembers?: LiveMembers;
  liveOperations?: OperationData;
  liveStaff?: StaffData;
  liveFinance?: FinanceData;
  liveSaasBilling?: SaasBillingData;
  liveEngagement?: EngagementData;
  liveCoaching?: CoachingData;
  liveReports?: ReportData;
  gymSettings?: GymSettingsData;
  trainerProfile?: TrainerProfileData;
  tenantViews?: View[];
  onPortalSwitch?: () => void;
  portalSwitchLabel?: string;
  onCreateMember?: (member: NewDashboardMember) => Promise<void>;
};

const navItems: { id: View; label: string; icon: LucideIcon }[] = [
  { id: "overview", label: "Overview", icon: LayoutDashboard },
  { id: "gym-dashboard", label: "Dashboard", icon: LayoutDashboard },
  { id: "gyms", label: "Gyms", icon: Building2 },
  { id: "members", label: "Members", icon: UsersRound },
  { id: "branches", label: "Branches", icon: Building2 },
  { id: "plans", label: "Membership plans", icon: CircleDollarSign },
  { id: "memberships", label: "Memberships", icon: Dumbbell },
  { id: "attendance", label: "Attendance & classes", icon: Activity },
  { id: "coaching", label: "Coaching & progress", icon: HeartPulse },
  { id: "payments", label: "Payments", icon: WalletCards },
  { id: "billing", label: "SaaS billing", icon: ReceiptText },
  { id: "reports", label: "Reports", icon: FileBarChart },
  { id: "staff", label: "Staff / Trainers", icon: ShieldCheck },
  { id: "trainer-profile", label: "Trainer profile", icon: Users },
  { id: "settings", label: "Settings", icon: Settings },
];

const currencyMeta: Record<Currency, { locale: string; rate: number }> = {
  GBP: { locale: "en-GB", rate: 1 }, USD: { locale: "en-US", rate: 1.32 },
  PKR: { locale: "en-PK", rate: 378 }, AED: { locale: "en-AE", rate: 4.85 },
  SAR: { locale: "en-SA", rate: 4.95 },
};

const startingGyms: Gym[] = [
  { name: "Forge Fitness", location: "Manchester, UK", initials: "FF", members: 2841, plan: "Scale", revenueGbp: 18420, status: "Healthy", accent: "violet" },
  { name: "Apex Athletics", location: "Dubai, UAE", initials: "AA", members: 1987, plan: "Scale", revenueGbp: 14280, status: "Healthy", accent: "blue" },
  { name: "Core Culture", location: "Lahore, Pakistan", initials: "CC", members: 1329, plan: "Growth", revenueGbp: 8960, status: "Attention", accent: "amber" },
  { name: "Atlas Strength", location: "Riyadh, KSA", initials: "AS", members: 946, plan: "Growth", revenueGbp: 6420, status: "Healthy", accent: "cyan" },
  { name: "Northline Gym", location: "Leeds, UK", initials: "NG", members: 324, plan: "Trial", revenueGbp: 1280, status: "Trial", accent: "rose" },
];

const members: DashboardMember[] = [
  { id: "demo-1", name: "Amelia Hart", gym: "Forge Fitness", membership: "Unlimited", joined: "04 Aug 2026", status: "Active" },
  { id: "demo-2", name: "Hassan Malik", gym: "Core Culture", membership: "Annual Pro", joined: "03 Aug 2026", status: "Active" },
  { id: "demo-3", name: "Omar Al-Farsi", gym: "Apex Athletics", membership: "Peak", joined: "02 Aug 2026", status: "Active" },
  { id: "demo-4", name: "Sarah Collins", gym: "Northline Gym", membership: "Monthly", joined: "01 Aug 2026", status: "Trial" },
  { id: "demo-5", name: "Mariam Qureshi", gym: "Atlas Strength", membership: "Women only", joined: "31 Jul 2026", status: "Paused" },
];

const payments = [
  { member: "Amelia Hart", gym: "Forge Fitness", method: "Visa •• 4242", amount: 89, state: "Paid", time: "2 min ago" },
  { member: "Hassan Malik", gym: "Core Culture", method: "Cash · front desk", amount: 42, state: "Recorded", time: "18 min ago" },
  { member: "Omar Al-Farsi", gym: "Apex Athletics", method: "Mastercard •• 8891", amount: 126, state: "Paid", time: "41 min ago" },
  { member: "Sarah Collins", gym: "Northline Gym", method: "Visa •• 1934", amount: 49, state: "Failed", time: "1 hr ago" },
];

const activities = [
  { icon: CreditCard, title: "Online payment completed", detail: "Apex Athletics · AED 465", time: "2m", tone: "success" },
  { icon: Users, title: "34 members imported", detail: "Core Culture · CSV import", time: "18m", tone: "purple" },
  { icon: Building2, title: "New gym is ready", detail: "Northline Gym · trial started", time: "1h", tone: "blue" },
  { icon: Bell, title: "Payment retry scheduled", detail: "Forge Fitness · 6 memberships", time: "3h", tone: "warning" },
];

const chartByRange: Record<string, number[]> = {
  "7D": [44, 52, 47, 68, 61, 79, 88, 84, 97, 92, 109, 116],
  "30D": [36, 48, 43, 59, 55, 71, 66, 82, 79, 95, 91, 108],
  "90D": [25, 34, 41, 38, 53, 61, 58, 72, 81, 78, 94, 103],
  "12M": [18, 24, 31, 35, 42, 49, 57, 64, 71, 79, 92, 108],
};

function money(gbp: number, currency: Currency, compact = false) {
  const converted = gbp * currencyMeta[currency].rate;
  if (!compact) {
    return new Intl.NumberFormat(currencyMeta[currency].locale, {
      style: "currency",
      currency,
      maximumFractionDigits: 0,
    }).format(converted);
  }

  // Browser and server ICU versions can disagree on compact suffix casing
  // (for example £86K vs £86k), so use deterministic SSR-safe suffixes.
  const magnitude = Math.abs(converted);
  const [divisor, suffix] = magnitude >= 1_000_000_000
    ? [1_000_000_000, "B"]
    : magnitude >= 1_000_000
      ? [1_000_000, "M"]
      : magnitude >= 1_000
        ? [1_000, "K"]
        : [1, ""];

  return `${new Intl.NumberFormat(currencyMeta[currency].locale, {
    style: "currency",
    currency,
    maximumFractionDigits: 0,
  }).format(converted / divisor)}${suffix}`;
}

function minorMoney(minor: number, currency: Currency) {
  return new Intl.NumberFormat(currencyMeta[currency].locale, {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(minor / 100);
}

function Logo() {
  return <div className="brand" aria-label="IronCore"><span className="brand-mark"><i /><strong>IC</strong></span><span className="brand-word">IRONCORE</span></div>;
}

function Sidebar({ active, onSelect, onOpenProfile, open, onClose, operator, tenantViews, portalMode, activeGym }: { active: View; onSelect: (view: View) => void; onOpenProfile: () => void; open: boolean; onClose: () => void; operator: { name: string; role: string }; tenantViews?: View[]; portalMode: "platform" | "gym"; activeGym?: { id: string; name: string } }) {
  // Production tenant navigation contains API-backed modules only.
  const items = tenantViews ? navItems.filter((item) => tenantViews.includes(item.id)) : navItems.filter((item) => item.id !== "gym-dashboard");
  const tenantMode = portalMode === "gym";
  const initials = operator.name.split(" ").map((part) => part[0]).join("").slice(0, 2).toUpperCase();
  return <>
    <button className={`sidebar-scrim ${open ? "show" : ""}`} onClick={onClose} aria-label="Close navigation" />
    <aside className={`sidebar ${open ? "open" : ""}`}>
      <div className="sidebar-top"><Logo /><button className="icon-button sidebar-close" onClick={onClose} aria-label="Close menu"><X size={18} /></button></div>
      <p className="nav-eyebrow">{tenantMode ? activeGym?.name ?? "Gym workspace" : "Platform workspace"}</p>
      <nav aria-label="Primary navigation">{items.map((item) => { const Icon = item.icon; return <button className={`nav-item ${active === item.id ? "active" : ""}`} key={item.id} onClick={() => { onSelect(item.id); onClose(); }}><Icon size={19} /><span>{item.label}</span></button>; })}</nav>
      <div className="sidebar-bottom">
        {tenantMode ? <div className="tenant-security-card"><ShieldCheck size={18} /><span><strong>Tenant context active</strong><small>API, policy and database isolation enforced</small></span></div> : <div className="capacity-card"><div className="capacity-icon"><Sparkles size={18} /></div><div><strong>96 of 100 gyms</strong><span>Platform capacity</span></div><div className="capacity-track"><span /></div><button onClick={() => onSelect("billing")}>Review plans <ArrowUpRight size={14} /></button></div>}
        <button type="button" className="support-row" onClick={() => { onOpenProfile(); onClose(); }} aria-label="Open account and profile settings"><span className="support-avatar">{initials}</span><span><strong>{operator.name}</strong><small>{operator.role}</small></span><MoreHorizontal size={18} /></button>
      </div>
    </aside>
  </>;
}

function Header({ title, currency, setCurrency, onMenu, query, setQuery, operator, activeGym, gymOptions, onGymChange, onLogout, onAccountSecurity, onOpenProfile, portalMode, onPortalSwitch, portalSwitchLabel, searchable, representative }: { title: string; currency: Currency; setCurrency: (value: Currency) => void; onMenu: () => void; query: string; setQuery: (value: string) => void; operator: { name: string; role: string }; activeGym?: { id: string; name: string }; gymOptions?: Array<{ id: string; name: string }>; onGymChange?: (gymId: string) => void; onLogout?: () => void; onAccountSecurity?: () => void; onOpenProfile: () => void; portalMode: "platform" | "gym"; onPortalSwitch?: () => void; portalSwitchLabel?: string; searchable: boolean; representative: boolean }) {
  const [showNotes, setShowNotes] = useState(false);
  const [showProfile, setShowProfile] = useState(false);
  const initials = operator.name.split(" ").map((part) => part[0]).join("").slice(0, 2).toUpperCase();
  return <header className="topbar">
    <div className="topbar-title"><button className="icon-button menu-button" onClick={onMenu} aria-label="Open navigation"><Menu size={21} /></button><div><span>{portalMode === "gym" ? activeGym?.name ?? "Gym workspace" : "IronCore platform"}</span><h1>{title}</h1></div></div>
    <div className="topbar-actions">
      {searchable && <label className="search-box"><Search size={17} /><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder={portalMode === "gym" ? "Search this gym" : "Search gyms or members"} aria-label={portalMode === "gym" ? "Search this gym" : "Search gyms or members"} /></label>}
      {onPortalSwitch && <button className="portal-switch" onClick={onPortalSwitch}><Building2 size={15} /><span>{portalSwitchLabel ?? (portalMode === "gym" ? "Platform portal" : "Gym portal")}</span></button>}
      {activeGym && gymOptions && <label className="gym-context-select"><Building2 size={16} /><select value={activeGym.id} onChange={(event) => onGymChange?.(event.target.value)} aria-label="Active gym tenant">{gymOptions.map((gym) => <option key={gym.id} value={gym.id}>{gym.name}</option>)}</select><ChevronDown size={14} /></label>}
      {representative && <label className="currency-select"><CircleDollarSign size={17} /><select value={currency} onChange={(e) => setCurrency(e.target.value as Currency)} aria-label="Display currency">{(Object.keys(currencyMeta) as Currency[]).map((code) => <option key={code}>{code}</option>)}</select><ChevronDown size={14} /></label>}
      {representative && <div className="notification-wrap"><button className="icon-button notification-button" onClick={() => setShowNotes(!showNotes)} aria-label="Preview notifications"><Bell size={19} /><span /></button>{showNotes && <div className="notification-popover"><div><strong>Preview notifications</strong><span>3 examples</span></div><p><b>Payment retry needed</b><small>6 memberships at Forge Fitness</small></p><p><b>Trial ending soon</b><small>Northline Gym · 4 days left</small></p><p><b>Payout completed</b><small>Apex Athletics · AED 12,480</small></p></div>}</div>}
      <div className="profile-wrap"><button className="profile-button" onClick={() => setShowProfile((value) => !value)} aria-expanded={showProfile} aria-haspopup="menu" aria-label="Open account menu"><span>{initials}</span><span className="profile-copy"><strong>{operator.name}</strong><small>{operator.role}</small></span><ChevronDown size={15} /></button>{showProfile && <div className="profile-popover" role="menu"><button type="button" className="profile-summary" onClick={() => { setShowProfile(false); onOpenProfile(); }}><span className="profile-summary-avatar">{initials}</span><span><strong>{operator.name}</strong><small>{operator.role}</small></span></button>{onAccountSecurity && <button onClick={() => { setShowProfile(false); onAccountSecurity(); }}><ShieldCheck size={17} /> Account security</button>}{onLogout && <button className="danger" onClick={() => { setShowProfile(false); onLogout(); }}><LogOut size={17} /> Sign out</button>}</div>}</div>
    </div>
  </header>;
}

function MetricCard({ icon: Icon, label, value, change, detail, tone, reverse }: { icon: LucideIcon; label: string; value: string; change: string; detail: string; tone: string; reverse?: boolean }) {
  return <article className="metric-card"><div className={`metric-icon ${tone}`}><Icon size={20} /></div><div className="metric-label"><span>{label}</span><MoreHorizontal size={18} /></div><strong className="metric-value">{value}</strong><div className={`metric-change ${reverse ? "down" : "up"}`}>{reverse ? <ArrowDownRight size={14} /> : <ArrowUpRight size={14} />}<b>{change}</b><span>{detail}</span></div></article>;
}

function RevenueChart({ currency }: { currency: Currency }) {
  const [range, setRange] = useState("30D"); const values = chartByRange[range]; const max = Math.max(...values);
  const points = values.map((value, index) => `${(index / (values.length - 1)) * 100},${86 - (value / max) * 72}`).join(" ");
  return <article className="panel revenue-panel">
    <div className="panel-heading"><div><p className="eyebrow">Network performance</p><h2>Revenue overview</h2><span>Member payments collected across all gyms</span></div><div className="range-tabs">{Object.keys(chartByRange).map((item) => <button className={range === item ? "active" : ""} onClick={() => setRange(item)} key={item}>{item}</button>)}</div></div>
    <div className="chart-summary"><strong>{money(294680, currency, true)}</strong><span><ArrowUpRight size={14} /> 18.4%</span><small>vs previous period</small></div>
    <div className="chart-wrap"><div className="chart-y"><span>{money(120000, currency, true)}</span><span>{money(80000, currency, true)}</span><span>{money(40000, currency, true)}</span><span>{money(0, currency)}</span></div><div className="chart-grid"><span /><span /><span /><span /></div><svg className="revenue-chart" viewBox="0 0 100 92" preserveAspectRatio="none" role="img" aria-label="Upward revenue trend"><defs><linearGradient id="areaFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#7c3aed" stopOpacity=".28" /><stop offset="100%" stopColor="#7c3aed" stopOpacity="0" /></linearGradient></defs><polygon points={`0,86 ${points} 100,86`} fill="url(#areaFill)" /><polyline points={points} fill="none" stroke="#7c3aed" strokeWidth="1.8" vectorEffect="non-scaling-stroke" /></svg><div className="chart-x"><span>Week 1</span><span>Week 2</span><span>Week 3</span><span>Today</span></div></div>
  </article>;
}

function GymTable({ gyms, currency, query, expanded = false }: { gyms: Gym[]; currency: Currency; query: string; expanded?: boolean }) {
  const filtered = gyms.filter((gym) => `${gym.name} ${gym.location}`.toLowerCase().includes(query.toLowerCase()));
  return <div className="table-scroll"><table className="data-table"><thead><tr><th>Gym</th><th>Members</th><th>Plan</th><th>Revenue</th><th>Health</th></tr></thead><tbody>{filtered.slice(0, expanded ? 20 : 4).map((gym) => <tr key={gym.name}><td><div className="gym-cell"><span className={`gym-logo ${gym.accent}`}>{gym.initials}</span><span><strong>{gym.name}</strong><small>{gym.location}</small></span></div></td><td><strong>{gym.members.toLocaleString()}</strong></td><td><span className="plan-pill">{gym.plan}</span></td><td><strong>{money(gym.revenueGbp, currency)}</strong><small className="table-sub">this month</small></td><td><span className={`status ${gym.status.toLowerCase()}`}><i />{gym.status}</span></td></tr>)}</tbody></table>{filtered.length === 0 && <div className="empty-state"><Search size={24} /><strong>No gyms found</strong><span>Try another search term.</span></div>}</div>;
}

function Overview({ gyms, currency, query, onView, onAdd }: { gyms: Gym[]; currency: Currency; query: string; onView: (view: View) => void; onAdd: () => void }) {
  return <>
    <section className="welcome-row"><div><p className="eyebrow">Thursday, 6 August 2026</p><h2>Good morning, <span>Servion.</span></h2><p>Here’s what’s happening across your gym network today.</p></div><button className="primary-button" onClick={onAdd}><Plus size={18} /> Add new gym</button></section>
    <section className="metric-grid"><MetricCard icon={Building2} label="Active gyms" value="96" change="8.2%" detail="from last month" tone="purple" /><MetricCard icon={UsersRound} label="Total members" value="128,432" change="12.4%" detail="from last month" tone="blue" /><MetricCard icon={TrendingUp} label="Platform MRR" value={money(86420, currency, true)} change="18.4%" detail="from last month" tone="green" /><MetricCard icon={Gauge} label="Collection rate" value="94.8%" change="1.2%" detail="failed this week" tone="orange" reverse /></section>
    <section className="dashboard-grid"><RevenueChart currency={currency} /><article className="panel activity-panel"><div className="panel-heading compact"><div><p className="eyebrow">Live updates</p><h2>Recent activity</h2></div><button className="text-button" onClick={() => onView("payments")}>View all</button></div><div className="activity-list">{activities.map((item) => { const Icon = item.icon; return <div className="activity-item" key={item.title}><span className={`activity-icon ${item.tone}`}><Icon size={17} /></span><span><strong>{item.title}</strong><small>{item.detail}</small></span><time>{item.time}</time></div>; })}</div><div className="system-health"><span><i /><b>All systems operational</b></span><small>Updated 1 min ago</small></div></article></section>
    <section className="panel gym-panel"><div className="panel-heading compact"><div><p className="eyebrow">Tenant health</p><h2>Gym performance</h2><span>Your highest-priority accounts</span></div><button className="secondary-button" onClick={() => onView("gyms")}>All gyms <ArrowUpRight size={16} /></button></div><GymTable gyms={gyms} currency={currency} query={query} /></section>
  </>;
}

function ModuleShell({ eyebrow, title, description, action, children }: { eyebrow: string; title: string; description: string; action?: React.ReactNode; children: React.ReactNode }) {
  return <><section className="module-heading"><div><p className="eyebrow">{eyebrow}</p><h2>{title}</h2><p>{description}</p></div>{action}</section>{children}</>;
}
function MiniMetric({ label, value, detail }: { label: string; value: string; detail: string }) { return <article><span>{label}</span><strong>{value}</strong><small>{detail}</small></article>; }

function GymsView({ gyms, currency, query, onAdd }: { gyms: Gym[]; currency: Currency; query: string; onAdd: () => void }) {
  return <ModuleShell eyebrow="Tenant management" title="Gyms" description="Manage every location, subscription and operational health signal from one place." action={<button className="primary-button" onClick={onAdd}><Plus size={18} /> Add new gym</button>}><section className="mini-metrics"><MiniMetric label="Active" value="96" detail="4 spaces remaining" /><MiniMetric label="On trial" value="3" detail="2 ending this week" /><MiniMetric label="Need attention" value="7" detail="Payment or setup issue" /></section><section className="panel"><GymTable gyms={gyms} currency={currency} query={query} expanded /></section></ModuleShell>;
}

function MembersView({ query, live, onAdd }: { query: string; live?: LiveMembers; onAdd?: () => void }) {
  const filtered = live ? live.rows : members.filter((m) => `${m.name} ${m.gym}`.toLowerCase().includes(query.toLowerCase()));
  const total = live?.total ?? 128432;
  const [activationLink, setActivationLink] = useState<string | null>(null);
  const [inviteBusy, setInviteBusy] = useState<string | null>(null);
  const [inviteError, setInviteError] = useState<string | null>(null);
  const [editing, setEditing] = useState<DashboardMember | null>(null);
  const [viewing, setViewing] = useState<DashboardMemberProfile | null>(null);
  const [viewBusy, setViewBusy] = useState<string | null>(null);
  const [viewError, setViewError] = useState<string | null>(null);
  const [importOpen, setImportOpen] = useState(false);
  const [templateOpen, setTemplateOpen] = useState(false);
  const [exportBusy, setExportBusy] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  async function invite(member: DashboardMember) {
    if (!live?.onInvitePortal) return;
    setInviteBusy(member.id); setInviteError(null); setActivationLink(null);
    try { setActivationLink(await live.onInvitePortal(member.id)); }
    catch (reason) { setInviteError(reason instanceof Error ? reason.message : "The invitation could not be created."); }
    finally { setInviteBusy(null); }
  }

  async function viewProfile(member: DashboardMember) {
    if (!live?.onView) return;
    setViewBusy(member.id); setViewError(null);
    try { setViewing(await live.onView(member.id)); }
    catch (reason) { setViewError(reason instanceof Error ? reason.message : "The member profile could not be loaded."); }
    finally { setViewBusy(null); }
  }

  async function editProfile(member: DashboardMember) {
    if (!live?.onUpdate) return;
    if (!live.onView) { setEditing(member); return; }
    setViewBusy(member.id); setViewError(null);
    try { setEditing(await live.onView(member.id)); }
    catch (reason) { setViewError(reason instanceof Error ? reason.message : "The member details could not be loaded for editing."); }
    finally { setViewBusy(null); }
  }

  const actions = live && (onAdd || live.onPreviewImport || live.onExport || live.onDownloadTemplate) ? <div className="member-bulk-actions">{live.onDownloadTemplate && <button className="secondary-button" type="button" onClick={() => setTemplateOpen(true)}><FileSpreadsheet size={16} /> Download template</button>}{live.onExport && <button className="secondary-button" type="button" disabled={exportBusy} onClick={async () => { setExportBusy(true); setExportError(null); try { await live.onExport?.(); } catch (reason) { setExportError(reason instanceof Error ? reason.message : "The member export failed."); } finally { setExportBusy(false); } }}><Download size={16} /> {exportBusy ? "Exporting…" : "Export members"}</button>}{live.onPreviewImport && <button className="secondary-button" type="button" onClick={() => setImportOpen(true)}><Upload size={16} /> Import members</button>}{onAdd && <button className="primary-button" onClick={onAdd}><Plus size={18} /> Add member</button>}</div> : onAdd ? <button className="primary-button" onClick={onAdd}><Plus size={18} /> Add member</button> : undefined;

  return <ModuleShell eyebrow="Member operations" title="Members" description={live ? "Tenant-scoped member records loaded securely from the IronCore API." : "A network-wide view of membership status, plans and recent joins."} action={actions}>
    {live && <div className="live-scope-banner"><ShieldCheck size={17} /><span><strong>Live tenant data</strong><small>Searches and writes are checked against the selected gym by middleware, policy and PostgreSQL RLS.</small></span></div>}
    {inviteError && <div className="form-error member-invite-error" role="alert">{inviteError}</div>}
    {viewError && <div className="form-error member-invite-error" role="alert">{viewError}</div>}
    {exportError && <div className="form-error member-invite-error" role="alert">{exportError}</div>}
    {activationLink && <div className="member-activation-link" role="status"><ShieldCheck size={19} /><span><strong>One-time activation link ready</strong><small>Send this link to the member now. Creating another invitation revokes it.</small><input aria-label="Member activation link" readOnly value={activationLink} onFocus={(event) => event.currentTarget.select()} /></span><button className="secondary-button" onClick={() => void navigator.clipboard.writeText(activationLink)}>Copy link</button><button className="icon-button" aria-label="Dismiss activation link" onClick={() => setActivationLink(null)}><X size={17} /></button></div>}
    <section className="mini-metrics"><MiniMetric label="Total members" value={total.toLocaleString()} detail={live ? "In selected gym" : "Across gym network"} /><MiniMetric label={live ? "Loaded securely" : "New this month"} value={live ? String(filtered.length) : "2,438"} detail={live ? "Capped page size: 25" : "12.4% increase"} /><MiniMetric label={live ? "Tenant boundary" : "At risk"} value={live ? "Enforced" : "1,284"} detail={live ? "Route + header verified" : "Action recommended"} /></section>
    <section className="panel table-scroll">{live?.loading && <div className="table-state"><RefreshCw className="spin" size={20} /><span>Loading tenant members…</span></div>}{live?.error && <div className="table-state error" role="alert"><strong>Members could not be loaded</strong><span>{live.error}</span><button className="secondary-button" onClick={live.onReload}>Try again</button></div>}{!live?.loading && !live?.error && <><table className="data-table"><thead><tr><th>Member</th><th>Gym</th><th>{live ? "Member Code" : "Membership"}</th><th>Joined</th><th>Profile status</th>{live && <th>Portal account</th>}{(live?.onView || live?.onUpdate) && <th />}</tr></thead><tbody>{filtered.map((m) => <tr key={m.id}><td><div className="person-cell"><span>{m.name.split(" ").map((p) => p[0]).join("")}</span><strong>{m.name}</strong></div></td><td>{m.gym}</td><td><span className="plan-pill member-code-pill">{live ? (m.memberCode ?? "—") : m.membership}</span></td><td>{m.joined}</td><td><span className={`status ${m.status.toLowerCase()}`}><i />{m.status}</span></td>{live && <td>{m.accountLinked ? <span className="status active"><i />Linked</span> : m.email && live.onInvitePortal ? <button className="secondary-button member-invite-button" disabled={inviteBusy === m.id} onClick={() => void invite(m)}>{inviteBusy === m.id ? "Creating…" : "Invite portal"}</button> : <small className="member-email-needed">{m.email ? "Preview only" : "Email required"}</small>}</td>}{(live?.onView || live?.onUpdate) && <td><div className="table-action-group">{live?.onView && <button className="table-action" disabled={viewBusy === m.id} onClick={() => void viewProfile(m)} aria-label={`View ${m.name} profile`}><Eye size={13} /> {viewBusy === m.id ? "Opening…" : "View"}</button>}{live?.onUpdate && <button className="table-action" disabled={viewBusy === m.id} onClick={() => void editProfile(m)} aria-label={`Edit ${m.name}`}><Pencil size={13} /> Edit</button>}</div></td>}</tr>)}</tbody></table>{filtered.length === 0 && <div className="empty-state"><Search size={24} /><strong>No members found</strong><span>{query ? "Try a different prefix or email address." : "Add the first member to this gym."}</span></div>}</>}</section>
    {viewing && <MemberProfileModal member={viewing} onClose={() => setViewing(null)} />}
    {editing && live?.onUpdate && <EditMemberModal member={editing} onUpdate={live.onUpdate} onClose={() => setEditing(null)} />}
    {importOpen && live?.onPreviewImport && live.onConfirmImport && live.onPollImport && <MemberImportModal live={live} onClose={() => setImportOpen(false)} />}
    {templateOpen && live?.onDownloadTemplate && <TemplateDownloadModal onDownload={live.onDownloadTemplate} onClose={() => setTemplateOpen(false)} />}
  </ModuleShell>;
}

function MemberProfileModal({ member, onClose }: { member: DashboardMemberProfile; onClose: () => void }) {
  const membership = member.currentMembership;
  const accessReady = member.statusValue === "active" && membership?.status === "active" && membership.isInDate;
  const profileDate = (value: string | null) => value ? new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(value)) : "No fixed expiry";
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="member-profile-title"><button className="modal-scrim" onClick={onClose} aria-label="Close member profile" /><section className="modal-card member-profile-modal"><div className="modal-heading"><span><Eye size={21} /></span><div><p className="eyebrow">Live member profile</p><h2 id="member-profile-title">{member.name}</h2></div><button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div><div className="member-profile-summary"><div className="person-cell"><span>{member.name.split(" ").map((part) => part[0]).join("")}</span><strong>Member Code {member.memberCode ?? "—"}</strong></div><dl><div><dt>Member Code</dt><dd className="member-code-value">{member.memberCode ?? "—"}</dd></div><div><dt>Profile status</dt><dd>{member.status}</dd></div><div><dt>Email</dt><dd>{member.email ?? "Not set"}</dd></div><div><dt>Phone</dt><dd>{member.phone ?? "Not set"}</dd></div></dl></div>{membership ? <article className="member-current-membership"><header><div><small>Current membership</small><h3>{membership.planName}</h3><p>{membership.planCode}{membership.branchName ? ` · ${membership.branchName}` : " · All branches"}</p></div><span className={`status ${accessReady ? "active" : "inactive"}`}><i />{accessReady ? "Access ready" : "Access unavailable"}</span></header><dl><div><dt>Contract status</dt><dd>{membership.status}</dd></div><div><dt>Started</dt><dd>{profileDate(membership.startsAt)}</dd></div><div><dt>Expires</dt><dd>{profileDate(membership.endsAt)}</dd></div><div><dt>Next billing</dt><dd>{membership.nextBillingAt ? profileDate(membership.nextBillingAt) : "Not scheduled"}</dd></div><div><dt>Accepted price</dt><dd>{minorMoney(membership.priceMinor, membership.currency)}</dd></div><div><dt>Auto renew</dt><dd>{membership.autoRenew ? "Yes" : "No"}</dd></div></dl></article> : <div className="member-no-membership"><Dumbbell size={22} /><strong>No current membership</strong><span>This active member profile does not yet have a plan contract. Create one from Memberships.</span></div>}<div className="modal-note"><ShieldCheck size={17} />QR and reception check-in still require an active, in-date membership and valid branch access.</div><div className="modal-actions"><button type="button" className="primary-button" onClick={onClose}>Close profile</button></div></section></div>;
}

function MemberImportModal({ live, onClose }: { live: LiveMembers; onClose: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<MemberImportRecord | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const summary = preview?.preview_summary;

  async function createPreview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); if (!file || !live.onPreviewImport) return;
    setBusy(true); setError(null); setPreview(null);
    try { setPreview(await live.onPreviewImport(file)); }
    catch (reason) { setError(reason instanceof Error ? reason.message : "The member file could not be previewed."); }
    finally { setBusy(false); }
  }

  async function confirm() {
    if (!preview || !live.onConfirmImport || !live.onPollImport || (summary?.invalid_rows ?? 1) > 0) return;
    setBusy(true); setError(null);
    try {
      let current = await live.onConfirmImport(preview.id); setPreview(current);
      for (let attempt = 0; attempt < 60 && ["queued", "processing"].includes(current.status); attempt++) {
        await new Promise((resolve) => window.setTimeout(resolve, 1000));
        current = await live.onPollImport(preview.id); setPreview(current);
      }
      if (current.status === "completed") { live.onReload(); }
      else if (current.status === "failed") setError(current.errors?.[0]?.message ?? "The import failed during processing.");
      else setError("The import is still processing. Close this window and refresh Members shortly.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The import could not be confirmed."); }
    finally { setBusy(false); }
  }

  const metrics = summary ? [
    ["Total rows", summary.total_rows], ["Valid rows", summary.valid_rows], ["Invalid rows", summary.invalid_rows],
    ["Duplicates", summary.duplicate_rows], ["Missing required", summary.missing_required_fields],
    ["Invalid email", summary.invalid_email], ["Invalid phone", summary.invalid_phone],
    ["Invalid branch", summary.invalid_branch_references], ["Invalid membership", summary.invalid_membership_references],
  ] : [];

  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="member-import-title"><button className="modal-scrim" onClick={onClose} aria-label="Close member import" /><section className="modal-card member-import-modal"><div className="modal-heading"><span><Upload size={21} /></span><div><p className="eyebrow">Validated tenant import</p><h2 id="member-import-title">Import members</h2></div><button className="icon-button modal-close-control" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>{error && <div className="form-error" role="alert">{error}</div>}{!preview && <form onSubmit={createPreview}><label>Member file<input type="file" accept=".csv,.xls,.xlsx,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required onChange={(event) => setFile(event.target.files?.[0] ?? null)} /></label><div className="modal-note"><ShieldCheck size={17} />Nothing is imported until the server validates every row and you confirm a clean preview.</div><div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" disabled={busy || !file}>{busy ? "Checking…" : "Preview import"}</button></div></form>}{preview && <><div className="import-preview-metrics">{metrics.map(([label, value]) => <article key={String(label)}><small>{label}</small><strong>{value}</strong></article>)}</div>{preview.errors?.length ? <div className="import-error-list"><strong>Problems to fix</strong>{preview.errors.slice(0, 12).map((item, index) => <p key={`${item.line}-${index}`}><b>{item.line ? `Row ${item.line}` : "File"}</b><span>{item.message}</span></p>)}</div> : <div className="form-notice" role="status">All rows passed validation. Confirm to create the members.</div>}<div className="modal-actions"><button type="button" className="secondary-button" onClick={() => { setPreview(null); setFile(null); }}>Choose another file</button><button type="button" className="primary-button" disabled={busy || (summary?.invalid_rows ?? 1) > 0 || preview.status === "completed"} onClick={() => void confirm()}>{busy ? "Importing…" : preview.status === "completed" ? `Imported ${preview.success_rows} members` : "Confirm import"}</button></div></>}</section></div>;
}

function TemplateDownloadModal({ onDownload, onClose }: { onDownload: (format: "csv" | "xlsx") => Promise<void>; onClose: () => void }) {
  const [busy, setBusy] = useState<"csv" | "xlsx" | null>(null); const [error, setError] = useState<string | null>(null);
  async function download(format: "csv" | "xlsx") { setBusy(format); setError(null); try { await onDownload(format); onClose(); } catch (reason) { setError(reason instanceof Error ? reason.message : "The template could not be downloaded."); } finally { setBusy(null); } }
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="template-title"><button className="modal-scrim" onClick={onClose} aria-label="Close template download" /><section className="modal-card template-modal"><div className="modal-heading"><span><FileSpreadsheet size={21} /></span><div><p className="eyebrow">Exact import columns</p><h2 id="template-title">Download member template</h2></div><button className="icon-button modal-close-control" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>{error && <div className="form-error" role="alert">{error}</div>}<p>The template includes the exact supported headers and one safe example row.</p><div className="template-format-actions"><button className="secondary-button" disabled={Boolean(busy)} onClick={() => void download("csv")}><Download size={16} />{busy === "csv" ? "Downloading…" : "CSV template"}</button><button className="primary-button" disabled={Boolean(busy)} onClick={() => void download("xlsx")}><Download size={16} />{busy === "xlsx" ? "Downloading…" : "Excel XLSX template"}</button></div></section></div>;
}

function EditMemberModal({ member, onUpdate, onClose }: { member: DashboardMember; onUpdate: NonNullable<LiveMembers["onUpdate"]>; onClose: () => void }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget); setBusy(true); setError(null);
    try {
      await onUpdate(member.id, { first_name: String(form.get("first_name")), last_name: String(form.get("last_name")), email: String(form.get("email")), phone: String(form.get("phone")), status: String(form.get("status")) as UpdateDashboardMember["status"], reason: String(form.get("reason")) });
      onClose();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The member could not be updated."); }
    finally { setBusy(false); }
  }
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="edit-member-title"><button className="modal-scrim" onClick={onClose} aria-label="Close dialog" /><form className="modal-card" onSubmit={submit}><div className="modal-heading"><span><Pencil size={21} /></span><div><p className="eyebrow">Audited tenant update</p><h2 id="edit-member-title">Edit {member.name}</h2></div><button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>{error && <div className="form-error" role="alert">{error}</div>}<div className="field-pair"><label>First name<input name="first_name" required maxLength={100} defaultValue={member.firstName ?? member.name.split(" ")[0]} /></label><label>Last name<input name="last_name" required maxLength={100} defaultValue={member.lastName ?? member.name.split(" ").slice(1).join(" ")} /></label></div><label>Email address<input name="email" type="email" maxLength={254} defaultValue={member.email ?? ""} required /></label><label>Phone<input name="phone" type="tel" maxLength={40} defaultValue={member.phone ?? ""} required /></label><label>Status<select name="status" defaultValue={member.statusValue ?? "lead"}><option value="lead">Lead</option><option value="active">Active</option><option value="paused">Paused</option><option value="cancelled">Cancelled</option><option value="archived">Archived</option></select></label><label>Audit reason<textarea name="reason" required maxLength={500} /></label><div className="modal-note"><ShieldCheck size={17} />The selected gym, actor and reason are written to the audit trail.</div><div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" disabled={busy} type="submit">{busy ? "Saving…" : "Save changes"}</button></div></form></div>;
}

function PaymentsView({ currency }: { currency: Currency }) {
  return <ModuleShell eyebrow="Transactions" title="Payments" description="Representative payment activity for product review."><section className="mini-metrics"><MiniMetric label="Collected today" value={money(18420, currency)} detail="342 transactions" /><MiniMetric label="Online payments" value="78%" detail="94.8% success rate" /><MiniMetric label="Cash recorded" value={money(4052, currency)} detail="Verified by gym staff" /></section><section className="panel table-scroll"><table className="data-table"><thead><tr><th>Member</th><th>Gym</th><th>Method</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead><tbody>{payments.map((p) => <tr key={p.member}><td><strong>{p.member}</strong></td><td>{p.gym}</td><td>{p.method}</td><td><strong>{money(p.amount, currency)}</strong></td><td><span className={`status ${p.state.toLowerCase()}`}><i />{p.state}</span></td><td>{p.time}</td></tr>)}</tbody></table></section><div className="audit-note"><ShieldCheck size={20} /><span><strong>Read-only preview</strong><small>Sign in to a configured API deployment to record real payments.</small></span></div></ModuleShell>;
}

function BillingView({ currency }: { currency: Currency }) {
  const plans = [{ icon: Dumbbell, title: "Scale", price: money(149, currency), text: "For growing gym businesses with advanced automation.", features: ["Unlimited members", "Online + cash payments", "Multi-location reporting"], featured: true }, { icon: Building2, title: "Growth", price: money(79, currency), text: "Everything an independent gym needs to operate.", features: ["Up to 2,500 members", "Membership automation", "Core reporting"] }, { icon: Sparkles, title: "Enterprise", price: "Custom", text: "Tailored controls for multi-brand fitness networks.", features: ["Dedicated infrastructure", "SSO and custom roles", "Priority support"] }];
  return <ModuleShell eyebrow="SaaS revenue" title="Subscription billing" description="Control platform plans, invoices and multi-currency subscription revenue."><section className="billing-grid">{plans.map((plan) => { const Icon = plan.icon; return <article className={`panel plan-card ${plan.featured ? "featured" : ""}`} key={plan.title}>{plan.featured && <span className="tag">Most popular</span>}<Icon size={24} /><h3>{plan.title}</h3><strong>{plan.price}{plan.price !== "Custom" && <small>/gym/month</small>}</strong><p>{plan.text}</p><ul>{plan.features.map((feature) => <li key={feature}><Check size={15} />{feature}</li>)}</ul></article>; })}</section></ModuleShell>;
}

function StandardModule({ view }: { view: View }) {
  const map: Record<string, { eyebrow: string; title: string; description: string; cards: [LucideIcon, string, string][] }> = {
    reports: { eyebrow: "Business intelligence", title: "Reports", description: "Decision-ready reporting for revenue, retention, attendance and gym performance.", cards: [[BarChart3, "Revenue intelligence", "Compare subscriptions, collections, refunds and unpaid balances."], [Activity, "Member retention", "Spot churn risk and monitor membership lifecycle trends."], [CalendarDays, "Scheduled reports", "Deliver gym-level summaries automatically to the right people."]] },
    staff: { eyebrow: "Gym team", title: "Staff / Trainers", description: "Create trainers, assign their branch and manage tenant-safe team access.", cards: [[ShieldCheck, "Role-based access", "Super admin, gym owner, manager, trainer, receptionist and member roles."], [UsersRound, "Tenant isolation", "Each gym can access only its own members, staff and financial records."], [Clock3, "Audit history", "Track sensitive actions, permission changes and manual payment edits."]] },
    settings: { eyebrow: "Platform controls", title: "Settings", description: "Configure global currencies, payment providers, notifications and policies.", cards: [[CircleDollarSign, "Five currencies", "GBP, USD, PKR, AED and SAR with immutable transaction history."], [CreditCard, "Payment routing", "Provider adapters support cards, cash and region-specific gateways."], [Bell, "Communication rules", "Control receipts, renewals, failed payment reminders and alerts."]] },
  }; const item = map[view] ?? map.settings;
  return <ModuleShell eyebrow={item.eyebrow} title={item.title} description={item.description}><section className="feature-grid">{item.cards.map(([Icon, title, text]) => <article className="panel feature-card" key={title}><span><Icon size={21} /></span><h3>{title}</h3><p>{text}</p></article>)}</section></ModuleShell>;
}

function AddGymModal({ onClose, onAdd }: { onClose: () => void; onAdd: (gym: Gym) => void }) {
  function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); const data = new FormData(event.currentTarget); const name = String(data.get("name")); onAdd({ name, location: String(data.get("location")), initials: name.split(" ").map((w) => w[0]).join("").slice(0, 2).toUpperCase(), members: 0, plan: "Trial", revenueGbp: 0, status: "Trial", accent: "violet" }); }
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="add-gym-title"><button className="modal-scrim" onClick={onClose} aria-label="Close dialog" /><form className="modal-card" onSubmit={submit}><div className="modal-heading"><span><Building2 size={21} /></span><div><p className="eyebrow">Tenant onboarding</p><h2 id="add-gym-title">Add a new gym</h2></div><button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div><label>Gym name<input name="name" required placeholder="e.g. Iron House Fitness" autoFocus /></label><label>Primary location<input name="location" required placeholder="City, country" /></label><label>Base currency<select name="currency" defaultValue="GBP"><option>GBP — British pound</option><option>USD — US dollar</option><option>PKR — Pakistani rupee</option><option>AED — UAE dirham</option><option>SAR — Saudi riyal</option></select></label><div className="modal-note"><ShieldCheck size={17} />This gym will receive an isolated workspace and a 14-day Scale trial.</div><div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" type="submit">Create gym <ArrowUpRight size={16} /></button></div></form></div>;
}

function AddMemberModal({ onClose, onAdd }: { onClose: () => void; onAdd?: (member: NewDashboardMember) => Promise<void> }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const data = new FormData(event.currentTarget);
    if (!onAdd) { onClose(); return; }
    setBusy(true); setError(null);
    try {
      await onAdd({ first_name: String(data.get("first_name")), last_name: String(data.get("last_name")), email: String(data.get("email")), phone: String(data.get("phone")), status: String(data.get("status")) as "lead" | "active" });
      onClose();
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The member could not be created."); }
    finally { setBusy(false); }
  }
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="add-member-title"><button className="modal-scrim" onClick={onClose} aria-label="Close dialog" /><form className="modal-card" onSubmit={submit}><div className="modal-heading"><span><UsersRound size={21} /></span><div><p className="eyebrow">Tenant member</p><h2 id="add-member-title">Add a member</h2></div><button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>{error && <div className="form-error" role="alert">{error}</div>}<div className="field-pair"><label>First name<input name="first_name" required maxLength={100} autoFocus /></label><label>Last name<input name="last_name" required maxLength={100} /></label></div><label>Email address<input name="email" type="email" maxLength={254} placeholder="member@example.com" required /></label><label>Phone<input name="phone" type="tel" maxLength={40} required /></label><label>Starting status<select name="status" defaultValue="lead"><option value="lead">Lead</option><option value="active">Active</option></select></label><div className="modal-note"><ShieldCheck size={17} />Name, email and phone are required by both this form and the tenant-safe API.</div><div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>Cancel</button><button className="primary-button" disabled={busy} type="submit">{busy ? "Creating…" : "Create member"} <ArrowUpRight size={16} /></button></div></form></div>;
}

function GymSettingsView({ data, onSecurity }: { data: GymSettingsData; onSecurity?: () => void }) {
  const [busy, setBusy] = useState(false); const [notice, setNotice] = useState<string | null>(null); const [error, setError] = useState<string | null>(null);
  const [bankBusy, setBankBusy] = useState(false); const [bankNotice, setBankNotice] = useState<string | null>(null); const [bankError, setBankError] = useState<string | null>(null); const [bankEnabledChoice, setBankEnabledChoice] = useState<{ gymId: string; value: boolean } | null>(null);
  const normalizedCountryCode = normalizeCountryCode(data.gym.country_code);
  const [countryChoice, setCountryChoice] = useState<{ gymId: string; value: string } | null>(null);
  const [timezoneChoice, setTimezoneChoice] = useState<{ gymId: string; value: string } | null>(null);
  const countryCode = countryChoice?.gymId === data.gym.id ? countryChoice.value : normalizedCountryCode;
  const timezone = timezoneChoice?.gymId === data.gym.id ? timezoneChoice.value : normalizeTimezone(data.gym.timezone, normalizedCountryCode);
  function changeCountry(value: string) {
    setCountryChoice({ gymId: data.gym.id, value });
    setTimezoneChoice({ gymId: data.gym.id, value: defaultTimezoneForCountry(value) });
  }
  const bankEnabled = bankEnabledChoice?.gymId === data.gym.id ? bankEnabledChoice.value : (data.bankTransferSetting?.enabled ?? false);
  const setBankEnabled = (value: boolean) => setBankEnabledChoice({ gymId: data.gym.id, value });
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget); setBusy(true); setError(null); setNotice(null);
    try {
      await data.onUpdate({ name: String(form.get("name")), legal_name: String(form.get("legal_name")) || null, base_currency: String(form.get("base_currency")) as Currency, country_code: String(form.get("country_code")).toUpperCase(), timezone: String(form.get("timezone")), reason: String(form.get("reason")) });
      setNotice("Gym settings saved for this gym only.");
    } catch (reason) { setError(reason instanceof Error ? reason.message : "Settings could not be saved."); }
    finally { setBusy(false); }
  }
  async function submitBank(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget); setBankBusy(true); setBankError(null); setBankNotice(null);
    try { await data.onUpdateBankTransferSetting({ enabled: bankEnabled, account_name: String(form.get("account_name")), bank_name: String(form.get("bank_name")), account_number_or_iban: String(form.get("account_number_or_iban")), routing_details: String(form.get("routing_details")) || undefined, payment_instructions: String(form.get("payment_instructions")) || undefined, reason: String(form.get("reason")) }); setBankEnabledChoice(null); setBankNotice("Member bank-transfer details saved for this gym only."); }
    catch (reason) { setBankError(reason instanceof Error ? reason.message : "Bank-transfer details could not be saved."); }
    finally { setBankBusy(false); }
  }
  return <section className="settings-workspace"><div className="module-heading"><div><p className="eyebrow">Tenant settings</p><h2>{data.gym.name}</h2><p>Current business settings apply only to this gym. Historical memberships, invoices and payments keep their original currency.</p></div></div>{error && <div className="form-error" role="alert">{error}</div>}{notice && <div className="form-notice" role="status">{notice}</div>}<div className="settings-grid"><article className="panel settings-card"><CircleDollarSign size={21} /><h3>Gym business settings</h3>{data.canManage ? <form onSubmit={submit}><div className="field-pair"><label>Gym name<input name="name" required maxLength={160} defaultValue={data.gym.name} /></label><label>Legal name<input name="legal_name" maxLength={200} defaultValue={data.gym.legal_name ?? ""} /></label></div><div className="field-trio"><label>Current currency<select name="base_currency" defaultValue={data.gym.base_currency}>{(["GBP", "USD", "PKR", "AED", "SAR"] as Currency[]).map((item) => <option key={item}>{item}</option>)}</select></label><SearchableSelect label="Country & calling code" name="country_code" options={countryOptions} value={countryCode} onChange={changeCountry} placeholder="Search country or calling code" /><SearchableSelect label="Timezone" name="timezone" options={timezoneOptions} value={timezone} onChange={(value) => setTimezoneChoice({ gymId: data.gym.id, value })} placeholder="Search IANA timezone" /></div><label>Audit reason<textarea name="reason" required minLength={5} maxLength={500} placeholder="Explain this business settings change" /></label><div className="modal-note"><ShieldCheck size={16} />The backend resolves this tenant and records the actor, reason and before/after state.</div><button className="primary-button" disabled={busy}>{busy ? "Saving…" : "Save settings"}</button></form> : <dl className="platform-detail-grid"><div><dt>Currency</dt><dd>{data.gym.base_currency}</dd></div><div><dt>Timezone</dt><dd>{data.gym.timezone}</dd></div><div><dt>Country</dt><dd>{data.gym.country_code}</dd></div><div><dt>Status</dt><dd>{data.gym.status}</dd></div></dl>}</article><article className="panel settings-card bank-settings-card"><Landmark size={21} /><h3>Member bank transfers</h3><p>These details appear only to signed-in members of this gym when they have an open membership invoice.</p>{bankError && <div className="form-error" role="alert">{bankError}</div>}{bankNotice && <div className="form-notice" role="status">{bankNotice}</div>}<form onSubmit={submitBank}><label className="bank-setting-toggle"><span><strong>Enable Pay by Bank Transfer</strong><small>Members can copy these details and submit private proof.</small></span><input type="checkbox" checked={bankEnabled} onChange={(event) => setBankEnabled(event.target.checked)} /></label><div className="field-pair"><label>Account name<input name="account_name" required={bankEnabled} maxLength={180} defaultValue={data.bankTransferSetting?.account_name ?? ""} /></label><label>Bank name<input name="bank_name" required={bankEnabled} maxLength={180} defaultValue={data.bankTransferSetting?.bank_name ?? ""} /></label></div><label>Account number / IBAN<input name="account_number_or_iban" required={bankEnabled} maxLength={180} autoComplete="off" defaultValue={data.bankTransferSetting?.account_number_or_iban ?? ""} /></label><label>Sort code / routing details<input name="routing_details" maxLength={180} defaultValue={data.bankTransferSetting?.routing_details ?? ""} placeholder="Optional where not applicable" /></label><label>Member instructions<textarea name="payment_instructions" maxLength={500} rows={3} defaultValue={data.bankTransferSetting?.payment_instructions ?? ""} placeholder="Optional transfer instructions" /></label><label>Audit reason<textarea name="reason" required minLength={5} maxLength={500} placeholder="Explain this bank settings change" /></label><div className="modal-note"><ShieldCheck size={16} />Bank fields are encrypted at rest. Audit history records configuration changes without copying account identifiers.</div><button className="primary-button" disabled={bankBusy}>{bankBusy ? "Saving…" : "Save bank details"}</button></form></article><article className="panel settings-card"><ShieldCheck size={21} /><h3>Account security</h3><p>Password and multi-factor authentication belong to your login account, not to the gym&apos;s currency or billing history.</p>{onSecurity && <button className="secondary-button" onClick={onSecurity}>Open account security</button>}</article></div></section>;
}

function TrainerProfileView({ data, onSecurity }: { data: TrainerProfileData; onSecurity?: () => void }) {
  const [busy, setBusy] = useState(false); const [notice, setNotice] = useState<string | null>(null); const [error, setError] = useState<string | null>(null);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); const form = new FormData(event.currentTarget); setBusy(true); setError(null); setNotice(null);
    try { await data.onUpdate({ display_name: String(form.get("display_name")), contact_email: String(form.get("contact_email")), phone: String(form.get("phone")), reason: String(form.get("reason")) }); setNotice("Trainer profile saved."); }
    catch (reason) { setError(reason instanceof Error ? reason.message : "Your trainer profile could not be saved."); }
    finally { setBusy(false); }
  }
  if (data.loading) return <div className="panel table-state">Loading trainer profile…</div>;
  if (!data.profile) return <div className="panel report-state error" role="alert"><ShieldCheck size={22} /><strong>Trainer profile unavailable</strong><span>{data.error ?? "Ask a Gym Admin to verify this trainer account."}</span></div>;
  return <section className="settings-workspace"><div className="module-heading"><div><p className="eyebrow">Trainer workspace</p><h2>Profile & settings</h2><p>Manage professional contact details. Role, gym, branch and access remain controlled by your Gym Admin.</p></div></div>{error && <div className="form-error" role="alert">{error}</div>}{notice && <div className="form-notice" role="status">{notice}</div>}<div className="settings-grid"><article className="panel settings-card"><Users size={21} /><h3>{data.profile.user.name}</h3><form onSubmit={submit}><div className="field-pair"><label>Display name<input name="display_name" required maxLength={160} defaultValue={data.profile.user.name} /></label><label>Contact email<input name="contact_email" type="email" required maxLength={254} defaultValue={data.profile.user.email} /></label></div><label>Phone<input name="phone" type="tel" required maxLength={40} defaultValue={data.profile.phone ?? ""} /></label><dl className="platform-detail-grid"><div><dt>Role</dt><dd>Trainer</dd></div><div><dt>Status</dt><dd>{data.profile.status}</dd></div><div><dt>Branch</dt><dd>{data.branchName ?? "Not assigned"}</dd></div><div><dt>Employee no.</dt><dd>{data.profile.employee_number}</dd></div></dl><label>Audit reason<textarea name="reason" required minLength={5} maxLength={500} placeholder="Explain this profile change" /></label><button className="primary-button" disabled={busy}>{busy ? "Saving…" : "Save profile"}</button></form></article><article className="panel settings-card"><ShieldCheck size={21} /><h3>Access & security</h3><p>You see only members assigned to you, your classes and authorised workout/progress records. Gym Admin controls branch and role access.</p>{onSecurity && <button className="secondary-button" onClick={onSecurity}>Open account security</button>}</article></div></section>;
}

export function IronCoreDashboard({ portalMode, operator = { name: "Servion Soft", role: "Super Admin" }, activeGym, gymOptions, onGymChange, onLogout, onChangePassword, mfa, liveMembers, liveOperations, liveStaff, liveFinance, liveSaasBilling, liveEngagement, liveCoaching, liveReports, gymSettings, trainerProfile, tenantViews, onPortalSwitch, portalSwitchLabel, onCreateMember }: DashboardProps = {}) {
  const resolvedPortalMode = portalMode ?? (tenantViews ? "gym" : "platform");
  const [view, setView] = useState<View>(tenantViews?.[0] ?? "overview"); const [currency, setCurrency] = useState<Currency>("GBP"); const [sidebar, setSidebar] = useState(false); const [query, setQuery] = useState(""); const [gymModal, setGymModal] = useState(false); const [memberModal, setMemberModal] = useState(false); const [securityModal, setSecurityModal] = useState(false); const [gyms, setGyms] = useState(startingGyms);
  const title = useMemo(() => navItems.find((item) => item.id === view)?.label ?? "Overview", [view]);
  const gymOverview = useMemo<GymOverviewData | null>(() => {
    if (resolvedPortalMode !== "gym" || !activeGym) return null;
    const financeCurrency = (liveFinance?.summary.currency ?? liveOperations?.baseCurrency ?? "GBP") as Currency;
    // This read model only composes collections that were already loaded for
    // the selected gym. It never accepts a gym ID or makes authorization
    // decisions; Laravel policy checks and PostgreSQL RLS remain authoritative.
    return {
      gymName: activeGym.name,
      timezone: liveEngagement?.timezone ?? "Europe/London",
      actorRole: operator.role,
      memberTotal: liveMembers?.total ?? liveOperations?.members.length ?? 0,
      // Active-member totals come from the bounded server aggregate rather
      // than counting a capped membership page and overstating its accuracy.
      activeMembers: liveReports?.report?.summary.active_members ?? null,
      branchCount: liveOperations?.branches.filter((branch) => branch.status === "active").length ?? 0,
      presentNow: liveEngagement?.attendance.filter((attendance) => attendance.status === "checked_in").length ?? 0,
      activeStaff: liveStaff?.rows.filter((staff) => staff.status === "active").length ?? 0,
      netCollectedMinor: liveFinance?.summary.netMinor ?? 0,
      outstandingMinor: liveFinance?.summary.outstandingMinor ?? 0,
      pendingMinor: liveFinance?.summary.pendingMinor ?? 0,
      currency: financeCurrency,
      upcomingClasses: (liveEngagement?.sessions ?? []).filter((session) => session.status === "scheduled").map((session) => ({
        id: session.id, title: session.title, branch: session.branch?.name ?? "Gym branch", startsAt: session.starts_at,
        booked: session.booked_count, capacity: session.capacity,
      })),
      recentPayments: (liveFinance?.payments ?? []).map((payment) => ({
        id: payment.id,
        member: liveFinance?.members.find((member) => member.id === payment.memberId)?.name ?? "Gym member",
        method: payment.method,
        amountMinor: payment.amountMinor,
        status: payment.status,
        paidAt: payment.paidAt,
      })),
      subscription: liveSaasBilling?.subscription ? {
        planName: liveSaasBilling.subscription.plan_name,
        status: liveSaasBilling.subscription.status,
        renewsAt: liveSaasBilling.subscription.current_period_end ?? liveSaasBilling.subscription.trial_ends_at,
      } : null,
      loading: Boolean(liveMembers?.loading || liveOperations?.loading || liveStaff?.loading || liveFinance?.loading || liveSaasBilling?.loading || liveEngagement?.loading),
      warnings: [liveMembers?.error, liveOperations?.error, liveStaff?.error, liveFinance?.error, liveSaasBilling?.error, liveEngagement?.error].filter((error): error is string => Boolean(error)),
      preview: Boolean(liveOperations?.preview),
    };
  }, [activeGym, liveEngagement, liveFinance, liveMembers, liveOperations, liveReports, liveSaasBilling, liveStaff, operator.role, resolvedPortalMode]);
  function addGym(gym: Gym) { setGyms((current) => [gym, ...current]); setGymModal(false); setView("gyms"); }
  function updateQuery(value: string) { setQuery(value); if (view === "members") liveMembers?.onSearch(value); }
  function selectView(next: View) { setView(next); setQuery(""); if (next === "members") liveMembers?.onSearch(""); }
  const searchable = ["overview", "gyms", "members", "branches", "plans", "memberships", "payments", "staff"].includes(view);
  const representative = Boolean(liveOperations?.preview) || resolvedPortalMode === "platform";
  const openProfile = onChangePassword ? () => setSecurityModal(true) : () => selectView("settings");
  return <div className="app-shell"><Sidebar active={view} onSelect={selectView} onOpenProfile={openProfile} open={sidebar} onClose={() => setSidebar(false)} operator={operator} tenantViews={tenantViews} portalMode={resolvedPortalMode} activeGym={activeGym} /><div className="app-main"><Header title={title} currency={currency} setCurrency={setCurrency} onMenu={() => setSidebar(true)} query={query} setQuery={updateQuery} operator={operator} activeGym={activeGym} gymOptions={gymOptions} onGymChange={onGymChange} onLogout={onLogout} onAccountSecurity={onChangePassword ? () => setSecurityModal(true) : undefined} onOpenProfile={openProfile} portalMode={resolvedPortalMode} onPortalSwitch={onPortalSwitch} portalSwitchLabel={portalSwitchLabel} searchable={searchable} representative={representative} /><main className="content">{view === "overview" && <Overview gyms={gyms} currency={currency} query={query} onView={setView} onAdd={() => setGymModal(true)} />}{view === "gym-dashboard" && gymOverview && <GymClientOverview data={gymOverview} availableViews={tenantViews ?? []} onView={selectView} />}{view === "gyms" && <GymsView gyms={gyms} currency={currency} query={query} onAdd={() => setGymModal(true)} />}{view === "members" && <MembersView query={query} live={liveMembers} onAdd={onCreateMember ? () => setMemberModal(true) : undefined} />}{view === "branches" && liveOperations && <BranchesView data={liveOperations} query={query} />}{view === "plans" && liveOperations && <PlansView data={liveOperations} query={query} />}{view === "memberships" && liveOperations && <MembershipsView data={liveOperations} query={query} />}{view === "attendance" && (liveEngagement ? <EngagementManagement data={liveEngagement} /> : <StandardModule view="reports" />)}{view === "coaching" && (liveCoaching ? <CoachingManagement data={liveCoaching} /> : <StandardModule view="reports" />)}{view === "staff" && (liveStaff ? <StaffManagement data={liveStaff} query={query} /> : <StandardModule view={view} />)}{view === "trainer-profile" && trainerProfile && <TrainerProfileView data={trainerProfile} onSecurity={onChangePassword ? () => setSecurityModal(true) : undefined} />}{view === "payments" && (liveFinance ? <FinancialManagement data={liveFinance} query={query} /> : <PaymentsView currency={currency} />)}{view === "billing" && (liveSaasBilling ? <SaasBillingManagement data={liveSaasBilling} /> : <BillingView currency={currency} />)}{view === "reports" && (liveReports ? <ReportManagement data={liveReports} /> : <StandardModule view="reports" />)}{view === "settings" && (gymSettings ? <GymSettingsView data={gymSettings} onSecurity={onChangePassword ? () => setSecurityModal(true) : undefined} /> : trainerProfile ? <TrainerProfileView data={trainerProfile} onSecurity={onChangePassword ? () => setSecurityModal(true) : undefined} /> : <StandardModule view="settings" />)}</main></div>{gymModal && <AddGymModal onClose={() => setGymModal(false)} onAdd={addGym} />}{memberModal && onCreateMember && <AddMemberModal onClose={() => setMemberModal(false)} onAdd={onCreateMember} />}{securityModal && onChangePassword && <AccountSecurityDialog onClose={() => setSecurityModal(false)} onChangePassword={onChangePassword} mfa={mfa} />}</div>;
}
