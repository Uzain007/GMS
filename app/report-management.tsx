"use client";

import {
  Activity, CalendarRange, CircleDollarSign, CreditCard, RefreshCw,
  ShieldCheck, TicketCheck, TrendingDown, TrendingUp, UserMinus, UsersRound,
} from "lucide-react";
import { FormEvent, useMemo, useState } from "react";
import type { GymSummary, ReportOverviewRecord } from "./lib/ironcore-api";

type Currency = GymSummary["base_currency"];

export type ReportData = {
  report: ReportOverviewRecord | null;
  from: string;
  to: string;
  currency: Currency;
  loading: boolean;
  error: string | null;
  onApply: (from: string, to: string, currency: Currency) => void;
  onReload: () => void;
};

const currencies: Currency[] = ["GBP", "USD", "PKR", "AED", "SAR"];

function money(minor: number, currency: Currency): string {
  return new Intl.NumberFormat("en-GB", {
    style: "currency",
    currency,
    maximumFractionDigits: 0,
  }).format(minor / 100);
}

function changeLabel(basisPoints: number | null): string {
  if (basisPoints === null) return "New baseline";
  const sign = basisPoints > 0 ? "+" : "";
  return `${sign}${(basisPoints / 100).toFixed(1)}%`;
}

function changeTone(value: number | null): "positive" | "negative" | "neutral" {
  if (value === null || value === 0) return "neutral";
  return value > 0 ? "positive" : "negative";
}

function Change({ value, inverse = false }: { value: number | null; inverse?: boolean }) {
  const tone = changeTone(value === null || !inverse ? value : -value);
  const Icon = value !== null && value < 0 ? TrendingDown : TrendingUp;
  return <span className={`report-change ${tone}`}><Icon size={12} />{changeLabel(value)}</span>;
}

function Metric({ icon: Icon, label, value, detail, change, tone }: {
  icon: typeof CircleDollarSign; label: string; value: string; detail: string;
  change?: number | null; tone: string;
}) {
  return <article className="panel report-metric">
    <span className={`report-metric-icon ${tone}`}><Icon size={19} /></span>
    <div><small>{label}</small><strong>{value}</strong><p>{detail}</p></div>
    {change !== undefined && <Change value={change} />}
  </article>;
}

function linePoints(values: number[]): string {
  const max = Math.max(...values, 1);
  const min = Math.min(...values, 0);
  const range = Math.max(max - min, 1);
  return values.map((value, index) => {
    const x = values.length === 1 ? 50 : (index / (values.length - 1)) * 100;
    const y = 82 - ((value - min) / range) * 66;
    return `${x.toFixed(2)},${y.toFixed(2)}`;
  }).join(" ");
}

function RevenueTrend({ report }: { report: ReportOverviewRecord }) {
  const values = report.daily.map((row) => row.net_revenue_minor);
  const points = linePoints(values);
  const first = report.daily[0]?.date;
  const middle = report.daily[Math.floor(report.daily.length / 2)]?.date;
  const last = report.daily.at(-1)?.date;
  const date = (value?: string) => value ? new Intl.DateTimeFormat("en-GB", { day: "numeric", month: "short", timeZone: "UTC" }).format(new Date(`${value}T00:00:00Z`)) : "—";

  return <article className="panel report-chart-card">
    <div className="report-panel-heading"><div><p className="eyebrow">Collection trend</p><h3>Net revenue</h3></div><strong>{money(report.summary.net_revenue_minor, report.period.currency)}</strong></div>
    <svg className="report-line-chart" viewBox="0 0 100 90" preserveAspectRatio="none" role="img" aria-label="Net revenue by day">
      <defs><linearGradient id="reportRevenueFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#7048d6" stopOpacity=".25" /><stop offset="100%" stopColor="#7048d6" stopOpacity="0" /></linearGradient></defs>
      {[20, 40, 60, 80].map((y) => <line key={y} x1="0" x2="100" y1={y} y2={y} stroke="#ece9f2" strokeWidth=".5" vectorEffect="non-scaling-stroke" />)}
      <polygon points={`0,84 ${points} 100,84`} fill="url(#reportRevenueFill)" />
      <polyline points={points} fill="none" stroke="#7048d6" strokeWidth="2" vectorEffect="non-scaling-stroke" />
    </svg>
    <div className="report-axis"><span>{date(first)}</span><span>{date(middle)}</span><span>{date(last)}</span></div>
  </article>;
}

function AttendanceTrend({ report }: { report: ReportOverviewRecord }) {
  const max = Math.max(...report.daily.map((row) => row.attendance_visits), 1);
  const visible = report.daily.length > 31
    ? report.daily.filter((_, index) => index % Math.ceil(report.daily.length / 31) === 0)
    : report.daily;

  return <article className="panel report-chart-card attendance-chart-card">
    <div className="report-panel-heading"><div><p className="eyebrow">Member activity</p><h3>Attendance</h3></div><strong>{report.summary.attendance_visits.toLocaleString()}</strong></div>
    <div className="attendance-bars" role="img" aria-label="Attendance visits by day">
      {visible.map((row) => <span key={row.date} title={`${row.date}: ${row.attendance_visits} visits`} style={{ height: `${Math.max(6, (row.attendance_visits / max) * 100)}%` }} />)}
    </div>
    <div className="report-chart-foot"><span>Daily check-ins</span><Change value={report.summary.attendance_change_bps} /></div>
  </article>;
}

function EmptyReport({ error, loading, onReload }: Pick<ReportData, "error" | "loading" | "onReload">) {
  return <section className={`panel report-state ${error ? "error" : ""}`} role={error ? "alert" : undefined}>
    <RefreshCw className={loading ? "spin" : ""} size={23} />
    <strong>{loading ? "Building your tenant report…" : error ? "Report could not be loaded" : "No report is available"}</strong>
    <span>{error ?? "Choose a bounded date range to load operational performance."}</span>
    {!loading && <button className="secondary-button" onClick={onReload}>Try again</button>}
  </section>;
}

export function ReportManagement({ data }: { data: ReportData }) {
  const [draftFrom, setDraftFrom] = useState(data.from);
  const [draftTo, setDraftTo] = useState(data.to);
  const [draftCurrency, setDraftCurrency] = useState<Currency>(data.currency);
  const report = data.report;
  const totalStatuses = useMemo(() => report?.member_status.reduce((sum, row) => sum + row.count, 0) ?? 0, [report]);

  function apply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    data.onApply(draftFrom, draftTo, draftCurrency);
  }

  return <section className="report-workspace">
    <div className="module-heading report-heading"><div><p className="eyebrow">Business intelligence</p><h2>Operational reports</h2><p>Revenue, member growth, attendance and class performance for this gym only.</p></div>
      <form className="report-filters" onSubmit={apply}>
        <label>From<input type="date" value={draftFrom} max={draftTo} onChange={(event) => setDraftFrom(event.target.value)} required /></label>
        <label>To<input type="date" value={draftTo} min={draftFrom} onChange={(event) => setDraftTo(event.target.value)} required /></label>
        <label>Currency<select value={draftCurrency} onChange={(event) => setDraftCurrency(event.target.value as Currency)}>{currencies.map((currency) => <option key={currency}>{currency}</option>)}</select></label>
        <button className="primary-button" disabled={data.loading} type="submit"><CalendarRange size={16} /> Apply</button>
      </form>
    </div>

    <div className="live-scope-banner"><ShieldCheck size={17} /><span><strong>Tenant-safe live report</strong><small>Laravel validates this gym, caps the date range and keeps every currency separate. Cached aggregates expire after 60 seconds.</small></span><button className="secondary-button" onClick={data.onReload} disabled={data.loading}><RefreshCw className={data.loading ? "spin" : ""} size={14} /> Refresh</button></div>

    {!report ? <EmptyReport error={data.error} loading={data.loading} onReload={data.onReload} /> : <>
      {data.error && <div className="form-error" role="alert">{data.error}</div>}
      <div className="report-metrics">
        <Metric icon={CircleDollarSign} label="Net collected" value={money(report.summary.net_revenue_minor, report.period.currency)} detail={`${money(report.summary.outstanding_minor, report.period.currency)} outstanding`} change={report.summary.net_revenue_change_bps} tone="violet" />
        <Metric icon={UsersRound} label="Active members" value={report.summary.active_members.toLocaleString()} detail={`${report.summary.new_members.toLocaleString()} new in period`} change={report.summary.new_members_change_bps} tone="blue" />
        <Metric icon={Activity} label="Attendance visits" value={report.summary.attendance_visits.toLocaleString()} detail={`${report.period.days} day reporting window`} change={report.summary.attendance_change_bps} tone="green" />
        <Metric icon={TicketCheck} label="Class utilisation" value={`${(report.summary.class_utilization_bps / 100).toFixed(1)}%`} detail={`${report.class_performance.attended.toLocaleString()} attended`} change={report.summary.class_utilization_change_bps} tone="amber" />
      </div>

      <div className="report-chart-grid"><RevenueTrend report={report} /><AttendanceTrend report={report} /></div>

      <div className="report-insight-grid">
        <article className="panel report-insight-card">
          <div className="report-panel-heading"><div><p className="eyebrow">Member health</p><h3>Status distribution</h3></div><span className="report-chip"><UsersRound size={13} />{totalStatuses.toLocaleString()}</span></div>
          <div className="status-distribution">{report.member_status.map((row) => {
            const width = totalStatuses ? (row.count / totalStatuses) * 100 : 0;
            return <div key={row.status}><span><strong>{row.status.replaceAll("_", " ")}</strong><b>{row.count.toLocaleString()}</b></span><i><em style={{ width: `${width}%` }} /></i></div>;
          })}{report.member_status.length === 0 && <p className="report-empty-copy">No member profiles in this tenant.</p>}</div>
          <div className="report-callout"><UserMinus size={17} /><span><strong>{report.summary.membership_cancellations} cancellations</strong><small>Recorded during the selected period</small></span></div>
        </article>

        <article className="panel report-insight-card">
          <div className="report-panel-heading"><div><p className="eyebrow">Collection mix</p><h3>Payment methods</h3></div><CreditCard size={18} /></div>
          <div className="payment-method-list">{report.payment_methods.map((row) => <div key={row.method}><span className={`method-dot ${row.method}`} /><span><strong>{row.method.replaceAll("_", " ")}</strong><small>{row.count.toLocaleString()} settled payments</small></span><b>{money(row.net_minor, report.period.currency)}</b></div>)}{report.payment_methods.length === 0 && <p className="report-empty-copy">No settled payments in this period.</p>}</div>
        </article>

        <article className="panel report-insight-card class-funnel-card">
          <div className="report-panel-heading"><div><p className="eyebrow">Class operations</p><h3>Capacity funnel</h3></div><span className="report-chip">{report.class_performance.sessions} sessions</span></div>
          <div className="class-funnel">
            {[
              ["Available capacity", report.class_performance.capacity],
              ["Booked places", report.class_performance.booked],
              ["Attended", report.class_performance.attended],
            ].map(([label, value], index) => {
              const numeric = Number(value); const base = Math.max(report.class_performance.capacity, 1);
              return <div key={String(label)}><span><strong>{label}</strong><b>{numeric.toLocaleString()}</b></span><i><em className={`funnel-${index}`} style={{ width: `${Math.min(100, (numeric / base) * 100)}%` }} /></i></div>;
            })}
          </div>
          <div className="report-callout"><TicketCheck size={17} /><span><strong>{report.class_performance.waitlisted} waitlisted</strong><small>{(report.class_performance.utilization_bps / 100).toFixed(1)}% capacity attended</small></span></div>
        </article>
      </div>

      <p className="report-generated">Generated {new Intl.DateTimeFormat("en-GB", { dateStyle: "medium", timeStyle: "short" }).format(new Date(report.meta.generated_at))} · {report.period.timezone} · {report.period.currency} only</p>
    </>}
  </section>;
}
