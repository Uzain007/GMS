"use client";

import {
  Activity, ArrowUpRight, Building2, CalendarDays, CheckCircle2, CircleDollarSign,
  Clock3, CreditCard, ReceiptText, ShieldCheck, Sparkles, UsersRound, WalletCards,
  type LucideIcon,
} from "lucide-react";
import type { View } from "./ironcore-dashboard";
import { formatGymDateTime } from "./lib/gym-time";

type Currency = "GBP" | "USD" | "PKR" | "AED" | "SAR";

export type GymOverviewData = {
  gymName: string;
  timezone: string;
  actorRole: string;
  memberTotal: number;
  activeMembers: number | null;
  branchCount: number;
  presentNow: number;
  activeStaff: number;
  netCollectedMinor: number;
  outstandingMinor: number;
  pendingMinor: number;
  currency: Currency;
  upcomingClasses: Array<{
    id: string;
    title: string;
    branch: string;
    startsAt: string;
    booked: number;
    capacity: number;
  }>;
  recentPayments: Array<{
    id: string;
    member: string;
    method: string;
    amountMinor: number;
    status: string;
    paidAt: string | null;
  }>;
  subscription: { planName: string; status: string; renewsAt: string | null } | null;
  loading: boolean;
  warnings: string[];
  preview?: boolean;
};

type Props = {
  data: GymOverviewData;
  availableViews: View[];
  onView: (view: View) => void;
};

const money = (minor: number, currency: Currency) => new Intl.NumberFormat("en-GB", {
  style: "currency", currency, minimumFractionDigits: 0, maximumFractionDigits: 2,
}).format(minor / 100);

const shownDate = (value: string | null, timeZone: string) => value ? new Intl.DateTimeFormat("en-GB", {
  timeZone, day: "2-digit", month: "short", year: "numeric",
}).format(new Date(value)) : "Not scheduled";

const readable = (value: string) => value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());

function Kpi({ icon: Icon, label, value, detail, tone }: { icon: LucideIcon; label: string; value: string; detail: string; tone: string }) {
  return <article className="gym-kpi panel"><span className={`gym-kpi-icon ${tone}`}><Icon size={20} /></span><div><small>{label}</small><strong>{value}</strong><p>{detail}</p></div></article>;
}

export function GymClientOverview({ data, availableViews, onView }: Props) {
  const canOpen = (view: View) => availableViews.includes(view);
  const actions: Array<{ view: View; label: string; detail: string; icon: LucideIcon }> = [
    { view: "members", label: "Add or find a member", detail: "Member profiles and memberships", icon: UsersRound },
    { view: "attendance", label: "Open front desk", detail: "Check-ins, QR access and classes", icon: Activity },
    { view: "payments", label: "Record a payment", detail: "Cash, terminal or online collection", icon: WalletCards },
    { view: "reports", label: "Review performance", detail: "Revenue, attendance and retention", icon: ReceiptText },
  ];
  const visibleActions = actions.filter((action) => canOpen(action.view));

  return <section className="gym-overview">
    <div className="gym-welcome">
      <div><div className="gym-welcome-label"><span>{data.preview ? "Representative gym preview" : "Live gym workspace"}</span><i /></div><h2>Good morning, {data.gymName}.</h2><p>Members, revenue and today&apos;s operations in one tenant-isolated view.</p></div>
      {canOpen("attendance") && <button className="primary-button" onClick={() => onView("attendance")}><Activity size={17} /> Open front desk</button>}
    </div>

    <div className="gym-scope-note"><ShieldCheck size={17} /><span><strong>{data.preview ? "Preview records only" : "Selected-gym data only"}</strong><small>{data.preview ? "This workspace demonstrates the client portal without using authenticated tenant data." : "These cards compose already authorised API responses; Laravel policies and PostgreSQL RLS remain authoritative."}</small></span></div>
    {data.warnings.length > 0 && <div className="gym-overview-warning" role="status"><Clock3 size={16} /><span>Some live sections are temporarily unavailable. The available tenant data remains isolated and safe.</span></div>}

    <div className="gym-kpi-grid" aria-busy={data.loading}>
      <Kpi icon={UsersRound} label="Members" value={data.memberTotal.toLocaleString()} detail={data.activeMembers === null ? "Selected gym member total" : `${data.activeMembers.toLocaleString()} active members`} tone="violet" />
      <Kpi icon={Activity} label="Visible check-ins" value={data.presentNow.toLocaleString()} detail="Current bounded attendance window" tone="green" />
      <Kpi icon={CircleDollarSign} label="Net collected" value={money(data.netCollectedMinor, data.currency)} detail={`${money(data.pendingMinor, data.currency)} pending`} tone="blue" />
      <Kpi icon={ReceiptText} label="Outstanding" value={money(data.outstandingMinor, data.currency)} detail="Open member invoice balances" tone="amber" />
    </div>

    <div className="gym-overview-grid">
      <article className="panel gym-quick-panel">
        <div className="gym-panel-title"><div><p className="eyebrow">Start here</p><h3>Quick actions</h3></div><Sparkles size={18} /></div>
        <div className="gym-quick-list">{visibleActions.map(({ view, label, detail, icon: Icon }) => <button key={view} onClick={() => onView(view)}><span><Icon size={17} /></span><span><strong>{label}</strong><small>{detail}</small></span><ArrowUpRight size={15} /></button>)}</div>
      </article>

      <article className="panel gym-classes-panel">
        <div className="gym-panel-title"><div><p className="eyebrow">Schedule</p><h3>Upcoming classes</h3></div>{canOpen("attendance") && <button onClick={() => onView("attendance")}>View schedule</button>}</div>
        <div className="gym-class-list">{data.upcomingClasses.length > 0 ? data.upcomingClasses.slice(0, 3).map((session) => <div key={session.id}><span className="gym-class-date"><CalendarDays size={16} /></span><span><strong>{session.title}</strong><small>{session.branch} · {formatGymDateTime(session.startsAt, data.timezone)}</small></span><span className="gym-capacity"><b>{session.booked}/{session.capacity}</b><small>booked</small></span></div>) : <div className="gym-inline-empty"><CalendarDays size={20} /><span><strong>No upcoming classes</strong><small>New scheduled sessions will appear here.</small></span></div>}</div>
      </article>
    </div>

    <div className="gym-overview-grid lower">
      <article className="panel gym-payments-panel">
        <div className="gym-panel-title"><div><p className="eyebrow">Collections</p><h3>Recent payments</h3></div>{canOpen("payments") && <button onClick={() => onView("payments")}>Open ledger</button>}</div>
        <div className="gym-payment-list">{data.recentPayments.length > 0 ? data.recentPayments.slice(0, 4).map((payment) => <div key={payment.id}><span className="gym-payment-icon"><CreditCard size={16} /></span><span><strong>{payment.member}</strong><small>{readable(payment.method)} · {payment.paidAt ? shownDate(payment.paidAt, data.timezone) : "Pending"}</small></span><span><b>{money(payment.amountMinor, data.currency)}</b><small className={`gym-payment-status ${payment.status}`}>{readable(payment.status)}</small></span></div>) : <div className="gym-inline-empty"><WalletCards size={20} /><span><strong>No recent payments</strong><small>New collections will appear here.</small></span></div>}</div>
      </article>

      <aside className="gym-side-stack">
        <article className="panel gym-health-card"><div className="gym-panel-title"><div><p className="eyebrow">Operations</p><h3>Today at a glance</h3></div><CheckCircle2 size={18} /></div><dl><div><dt><Building2 size={15} /> Loaded branches</dt><dd>{data.branchCount}</dd></div><div><dt><UsersRound size={15} /> Loaded active team</dt><dd>{data.activeStaff}</dd></div><div><dt><CalendarDays size={15} /> Scheduled classes</dt><dd>{data.upcomingClasses.length}</dd></div></dl></article>
        <article className="panel gym-plan-card"><span><Sparkles size={17} /></span><div><small>IronCore subscription</small><strong>{data.subscription?.planName ?? "No active plan"}</strong><p>{data.subscription ? `${readable(data.subscription.status)} · renews ${shownDate(data.subscription.renewsAt, data.timezone)}` : "Ask the gym owner to review billing."}</p></div>{canOpen("billing") && <button onClick={() => onView("billing")} aria-label="Open SaaS billing"><ArrowUpRight size={16} /></button>}</article>
      </aside>
    </div>
  </section>;
}
