"use client";

import { BarChart3, Building2, Download, LoaderCircle, RefreshCw, Search, UsersRound } from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";
import type { GymSummary, PlatformAnalyticsRecord, PlatformBillingRecord, PlatformMemberPage, SaasPlanRecord } from "./lib/ironcore-api";

function moneyByCurrency(values: Record<string, number>): string {
  const rows = Object.entries(values);
  return rows.length ? rows.map(([currency, minor]) => new Intl.NumberFormat("en-GB", { style: "currency", currency }).format(minor / 100)).join(" · ") : "—";
}

function readable(value: string | null): string {
  return value ? value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase()) : "—";
}

export function PlatformMemberDirectory({ gyms, load, exportRows }: {
  gyms: GymSummary[];
  load: (params: URLSearchParams) => Promise<PlatformMemberPage>;
  exportRows: (params: URLSearchParams) => Promise<Blob>;
}) {
  const [filters, setFilters] = useState({ search: "", gym_id: "", status: "", membership_status: "", plan_id: "", branch_id: "" });
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<PlatformMemberPage | null>(null);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [allFiltered, setAllFiltered] = useState(false);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const params = useMemo(() => { const p = new URLSearchParams({ page: String(page), per_page: "25" }); Object.entries(filters).forEach(([key, value]) => { if (value.trim()) p.set(key, value.trim()); }); return p; }, [filters, page]);
  const reload = useCallback(async () => { setBusy(true); setError(null); try { setResult(await load(params)); } catch (reason) { setError(reason instanceof Error ? reason.message : "Members could not be loaded."); } finally { setBusy(false); } }, [load, params]);
  useEffect(() => {
    let active = true;
    void load(params).then((value) => { if (active) { setResult(value); setError(null); } })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : "Members could not be loaded."); })
      .finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
  }, [load, params]);
  async function download(format: "csv" | "xlsx") { setBusy(true); setError(null); try { const p = new URLSearchParams(params); p.delete("page"); p.set("format", format); if (!allFiltered) selected.forEach((id) => p.append("member_ids[]", id)); const blob = await exportRows(p); const url = URL.createObjectURL(blob); const link = document.createElement("a"); link.href = url; link.download = `ironcore-global-members.${format}`; link.click(); URL.revokeObjectURL(url); } catch (reason) { setError(reason instanceof Error ? reason.message : "Export failed."); } finally { setBusy(false); } }
  const pageIds = result?.data.map((member) => member.id) ?? [];
  const pageSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));
  function toggleMember(id: string) { setAllFiltered(false); setSelected((current) => { const next = new Set(current); if (next.has(id)) next.delete(id); else next.add(id); return next; }); }
  function togglePage() { setAllFiltered(false); setSelected((current) => { const next = new Set(current); if (pageSelected) pageIds.forEach((id) => next.delete(id)); else pageIds.forEach((id) => next.add(id)); return next; }); }
  const facets = result?.meta.facets;
  return <>
    <section className="module-heading">
      <div><p className="eyebrow">Platform directory</p><h2>Global members</h2><p>Search authorised member records across gyms while every tenant query remains explicitly scoped.</p></div>
      <div className="finance-actions"><button className="secondary-button" disabled={busy} onClick={() => void download("csv")}><Download size={16} /> CSV</button><button className="primary-button" disabled={busy} onClick={() => void download("xlsx")}><Download size={16} /> XLSX</button></div>
    </section>
    <section className="panel platform-insight-filters">
      <label className="search-box"><Search size={16} /><input value={filters.search} onChange={(event) => { setFilters({ ...filters, search: event.target.value }); setPage(1); setSelected(new Set()); setAllFiltered(false); }} placeholder="Name, email, phone or code" aria-label="Search global members" /></label>
      <select value={filters.gym_id} onChange={(event) => { setFilters({ ...filters, gym_id: event.target.value, plan_id: "", branch_id: "" }); setPage(1); setSelected(new Set()); setAllFiltered(false); }} aria-label="Filter by gym"><option value="">All gyms</option>{gyms.map((gym) => <option key={gym.id} value={gym.id}>{gym.name}</option>)}</select>
      <select value={filters.status} onChange={(event) => { setFilters({ ...filters, status: event.target.value }); setPage(1); setSelected(new Set()); setAllFiltered(false); }} aria-label="Filter member status"><option value="">All profiles</option><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select>
      <select value={filters.membership_status} onChange={(event) => { setFilters({ ...filters, membership_status: event.target.value }); setPage(1); setSelected(new Set()); setAllFiltered(false); }} aria-label="Filter membership status"><option value="">All memberships</option><option value="active">Active</option><option value="pending">Pending</option><option value="paused">Paused</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select>
      <select value={filters.plan_id} onChange={(event) => { setFilters({ ...filters, plan_id: event.target.value }); setPage(1); setSelected(new Set()); setAllFiltered(false); }} aria-label="Filter by membership plan"><option value="">All plans</option>{facets?.plans.map((plan) => <option key={plan.id} value={plan.id}>{filters.gym_id ? plan.name : `${plan.gym_name} · ${plan.name}`}</option>)}</select>
      <select value={filters.branch_id} onChange={(event) => { setFilters({ ...filters, branch_id: event.target.value }); setPage(1); setSelected(new Set()); setAllFiltered(false); }} aria-label="Filter by branch"><option value="">All branches</option>{facets?.branches.map((branch) => <option key={branch.id} value={branch.id}>{filters.gym_id ? branch.name : `${branch.gym_name} · ${branch.name}`}</option>)}</select>
      <button className="icon-button" onClick={() => void reload()} aria-label="Refresh directory"><RefreshCw className={busy ? "spin" : ""} size={17} /></button>
    </section>
    {error && <div className="form-error" role="alert">{error}</div>}
    {result && result.meta.total > 0 && <div className="directory-selection-bar">
      <span>{allFiltered ? `All ${result.meta.total} filtered members selected` : `${selected.size} selected`}</span>
      <button className="secondary-button" type="button" onClick={togglePage}>{pageSelected ? "Clear current page" : "Select current page"}</button>
      <button className="secondary-button" type="button" onClick={() => { setSelected(new Set()); setAllFiltered(true); }}>Select filtered results</button>
      {(selected.size > 0 || allFiltered) && <button className="secondary-button" type="button" onClick={() => { setSelected(new Set()); setAllFiltered(false); }}>Clear selection</button>}
    </div>}
    <section className="panel table-scroll platform-member-directory">
      {busy && !result ? <div className="table-state"><LoaderCircle className="spin" size={20} /> Loading members…</div> : <table className="data-table"><thead><tr><th className="selection-cell"><input type="checkbox" aria-label="Select current page" checked={pageSelected} onChange={togglePage} /></th><th>Member</th><th>Gym</th><th>Code</th><th>Branch</th><th>Plan</th><th>Status</th></tr></thead><tbody>{result?.data.map((member) => <tr key={`${member.gym_id}-${member.id}`}><td className="selection-cell"><input type="checkbox" aria-label={`Select ${member.name}`} checked={allFiltered || selected.has(member.id)} onChange={() => toggleMember(member.id)} /></td><td><strong>{member.name}</strong><small className="table-sub">{member.email ?? "No email"} · {member.phone ?? "No phone"}</small></td><td>{member.gym_name}</td><td><strong>{member.member_code}</strong></td><td>{member.branch?.name ?? "All branches"}</td><td>{member.plan?.name ?? "No membership"}</td><td><span className={`status ${member.status}`}><i />{readable(member.membership_status ?? member.status)}</span></td></tr>)}</tbody></table>}
      {!busy && !result?.data.length && <div className="empty-state"><UsersRound size={24} /><strong>No matching members</strong><span>Change the search or filters.</span></div>}
    </section>
    {result && result.meta.last_page > 1 && <div className="pagination-controls"><button className="secondary-button" disabled={page <= 1 || busy} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {page} of {result.meta.last_page}</span><button className="secondary-button" disabled={page >= result.meta.last_page || busy} onClick={() => setPage((value) => value + 1)}>Next</button></div>}
  </>;
}

export function PlatformBillingDashboard({ gyms, plans, load, onOpenGym }: { gyms: GymSummary[]; plans: SaasPlanRecord[]; load: (params: URLSearchParams) => Promise<PlatformBillingRecord>; onOpenGym: (gym: GymSummary) => void }) {
  const [filters, setFilters] = useState({ search: "", gym_id: "", plan_id: "", status: "", currency: "", from: "", to: "" });
  const [page, setPage] = useState(1); const [data, setData] = useState<PlatformBillingRecord | null>(null); const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(true);
  const reload = useCallback(async () => { setBusy(true); setError(null); try { const p = new URLSearchParams({ per_page: "25", page: String(page) }); Object.entries(filters).forEach(([key, value]) => { if (value) p.set(key, value); }); setData(await load(p)); } catch (reason) { setError(reason instanceof Error ? reason.message : "Billing data could not be loaded."); } finally { setBusy(false); } }, [filters, load, page]);
  useEffect(() => {
    let active = true;
    const params = new URLSearchParams({ per_page: "25", page: String(page) });
    Object.entries(filters).forEach(([key, value]) => { if (value) params.set(key, value); });
    void load(params).then((value) => { if (active) { setData(value); setError(null); } })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : "Billing data could not be loaded."); })
      .finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
  }, [filters, load, page]);
  const metrics = data?.metrics;
  function change(key: keyof typeof filters, value: string) { setFilters((current) => ({ ...current, [key]: value })); setPage(1); }
  return <><section className="module-heading"><div><p className="eyebrow">Platform billing</p><h2>SaaS billing dashboard</h2><p>Recurring revenue, payment recovery and renewal visibility across IronCore gyms.</p></div><button className="secondary-button" onClick={() => void reload()}><RefreshCw className={busy ? "spin" : ""} size={16} /> Refresh</button></section>
    <section className="panel billing-dashboard-filters"><label>Search<input value={filters.search} onChange={(event) => change("search", event.target.value)} placeholder="Gym, plan or invoice" /></label><label>Gym<select value={filters.gym_id} onChange={(event) => change("gym_id", event.target.value)}><option value="">All gyms</option>{gyms.map((gym) => <option key={gym.id} value={gym.id}>{gym.name}</option>)}</select></label><label>SaaS plan<select value={filters.plan_id} onChange={(event) => change("plan_id", event.target.value)}><option value="">All plans</option>{plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}</select></label><label>Status<select value={filters.status} onChange={(event) => change("status", event.target.value)}><option value="">All statuses</option>{["upcoming", "due", "past_due", "paid", "void", "cancelled", "uncollectible"].map((status) => <option key={status} value={status}>{readable(status)}</option>)}</select></label><label>Currency<select value={filters.currency} onChange={(event) => change("currency", event.target.value)}><option value="">All currencies</option>{["GBP", "USD", "PKR", "AED", "SAR"].map((currency) => <option key={currency}>{currency}</option>)}</select></label><label>From<input type="date" value={filters.from} onChange={(event) => change("from", event.target.value)} /></label><label>To<input type="date" min={filters.from} value={filters.to} onChange={(event) => change("to", event.target.value)} /></label></section>
    {error && <div className="form-error">{error}</div>}
    <section className="platform-metrics insight-metrics">{[["Total gyms", metrics?.total_gyms], ["Active gyms", metrics?.active_gyms], ["Trial gyms", metrics?.trial_gyms], ["Paid gyms", metrics?.paid_gyms], ["Unpaid gyms", metrics?.unpaid_gyms], ["Past due", metrics?.past_due_gyms], ["Billing suspended", metrics?.billing_suspended_gyms], ["Cancelled / archived", metrics?.cancelled_archived_gyms], ["Upcoming renewals", metrics?.upcoming_renewals], ["MRR", moneyByCurrency(metrics?.mrr_by_currency ?? {})], ["Revenue this month", moneyByCurrency(metrics?.revenue_this_month_by_currency ?? {})], ["Outstanding", moneyByCurrency(metrics?.outstanding_by_currency ?? {})], ["Overdue", moneyByCurrency(metrics?.overdue_by_currency ?? {})]].map(([label, value]) => <article key={String(label)}><Building2 /><span><small>{label}</small><strong>{value ?? 0}</strong></span></article>)}</section>
    <section className="panel table-scroll"><div className="panel-title"><div><p className="eyebrow">Tenant subscriptions</p><h3>Subscription status</h3></div></div><table className="data-table"><thead><tr><th>Gym</th><th>Plan</th><th>Status</th><th>Recurring price</th><th>Next renewal</th><th /></tr></thead><tbody>{data?.subscriptions.map((row) => <tr key={row.id}><td><strong>{row.gym_name}</strong></td><td>{row.plan_name}</td><td><span className={`status ${row.status}`}><i />{readable(row.status)}</span></td><td>{moneyByCurrency({ [row.currency]: row.billing_interval === "yearly" ? Math.floor(row.amount_minor / 12) : row.amount_minor })}</td><td>{row.next_billing_at ? new Date(row.next_billing_at).toLocaleDateString() : "—"}</td><td><button className="table-action" onClick={() => { const gym = gyms.find((item) => item.id === row.gym_id); if (gym) onOpenGym(gym); }}>Open gym</button></td></tr>)}</tbody></table>{!busy && !data?.subscriptions.length && <div className="empty-state"><Building2 size={24} /><strong>No subscriptions found</strong></div>}</section>
    <section className="panel table-scroll"><div className="panel-title"><div><p className="eyebrow">Invoice ledger</p><h3>Recent invoices</h3></div></div><table className="data-table"><thead><tr><th>Invoice</th><th>Gym</th><th>Due</th><th>Amount</th><th>Status</th><th /></tr></thead><tbody>{data?.invoices.data.map((row) => <tr key={row.id}><td>{row.number ?? "Pending"}</td><td>{row.gym_name}</td><td>{row.due_at ? new Date(row.due_at).toLocaleDateString() : "—"}</td><td>{moneyByCurrency({ [row.currency]: row.amount_remaining_minor })}</td><td><span className={`status ${row.status}`}><i />{readable(row.status)}</span></td><td><button className="table-action" onClick={() => { const gym = gyms.find((item) => item.id === row.gym_id); if (gym) onOpenGym(gym); }}>Open gym</button></td></tr>)}</tbody></table></section>
    {data && data.invoices.meta.last_page > 1 && <div className="pagination-controls"><button className="secondary-button" disabled={page <= 1 || busy} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {page} of {data.invoices.meta.last_page}</span><button className="secondary-button" disabled={page >= data.invoices.meta.last_page || busy} onClick={() => setPage((value) => value + 1)}>Next</button></div>}
  </>;
}

export function PlatformAnalytics({ load }: { load: (params: URLSearchParams) => Promise<PlatformAnalyticsRecord> }) {
  const [range, setRange] = useState(() => { const to = new Date(); const from = new Date(to.getFullYear(), to.getMonth() - 11, 1); return { from: from.toISOString().slice(0, 10), to: to.toISOString().slice(0, 10) }; });
  const [data, setData] = useState<PlatformAnalyticsRecord | null>(null); const [error, setError] = useState<string | null>(null); const [busy, setBusy] = useState(true);
  const reload = useCallback(async () => { setBusy(true); setError(null); try { setData(await load(new URLSearchParams(range))); } catch (reason) { setError(reason instanceof Error ? reason.message : "Analytics could not be loaded."); } finally { setBusy(false); } }, [load, range]);
  useEffect(() => {
    let active = true;
    void load(new URLSearchParams(range)).then((value) => { if (active) { setData(value); setError(null); } })
      .catch((reason: unknown) => { if (active) setError(reason instanceof Error ? reason.message : "Analytics could not be loaded."); })
      .finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
  }, [load, range]);
  const max = Math.max(1, ...(data?.timeline.map((row) => row.total_gyms) ?? [1]));
  const metrics = data?.billing_metrics;
  const total = Math.max(1, metrics?.total_gyms ?? 0);
  const conversion = metrics ? Math.round((metrics.trial_conversions / Math.max(1, metrics.trial_conversions + metrics.trial_gyms)) * 100) : 0;
  return <><section className="module-heading"><div><p className="eyebrow">Platform growth</p><h2>Analytics</h2><p>Gym growth, member adoption, plan distribution and currency-separated revenue.</p></div><div className="analytics-range"><label>From<input type="date" value={range.from} onChange={(event) => setRange({ ...range, from: event.target.value })} /></label><label>To<input type="date" min={range.from} value={range.to} onChange={(event) => setRange({ ...range, to: event.target.value })} /></label><button className="secondary-button" onClick={() => void reload()}><RefreshCw className={busy ? "spin" : ""} size={16} /> Refresh</button></div></section>{error && <div className="form-error">{error}</div>}
    <section className="platform-metrics insight-metrics analytics-metrics">{[["MRR", moneyByCurrency(metrics?.mrr_by_currency ?? {})], ["Monthly revenue", moneyByCurrency(metrics?.revenue_this_month_by_currency ?? {})], ["Trial gyms", metrics?.trial_gyms ?? 0], ["Trial conversion", `${conversion}%`], ["Past-due rate", `${Math.round(((metrics?.past_due_gyms ?? 0) / total) * 100)}%`], ["Churn / cancelled", `${Math.round(((metrics?.cancelled_archived_gyms ?? 0) / total) * 100)}%`], ["Members this month", data?.comparison.current_month.members_added ?? 0], ["Previous month", data?.comparison.previous_month.members_added ?? 0]].map(([label, value]) => <article key={String(label)}><BarChart3 /><span><small>{label}</small><strong>{value}</strong></span></article>)}</section>
    <section className="panel platform-growth-chart"><div className="panel-title"><div><p className="eyebrow">Selected period</p><h3>Total, active and new gyms</h3></div><BarChart3 size={21} /></div><div className="growth-bars">{data?.timeline.map((row) => <div key={row.month}><span style={{ height: `${Math.max(5, (row.total_gyms / max) * 100)}%` }} title={`${row.total_gyms} total · ${row.active_gyms} active · ${row.new_gyms} new`} /><small>{row.month.slice(5)}</small><b>{row.active_gyms} active · {row.new_gyms} new<br />{row.members_added} members</b></div>)}</div></section>
    <section className="platform-grid analytics-grid"><article className="panel"><div className="panel-title"><h3>Plan distribution</h3></div>{data?.plan_distribution.map((row) => <div className="insight-row" key={row.plan}><strong>{row.plan}</strong><span>{row.gyms} gyms</span></div>)}{!data?.plan_distribution.length && <div className="empty-state"><Building2 size={22} /><strong>No plan data</strong></div>}</article><article className="panel"><div className="panel-title"><h3>Revenue by SaaS plan</h3></div>{data?.revenue_by_plan.map((row) => <div className="insight-row" key={row.plan}><strong>{row.plan}</strong><span>{moneyByCurrency(row.revenue_by_currency)}</span></div>)}{!data?.revenue_by_plan.length && <div className="empty-state"><Building2 size={22} /><strong>No paid plan revenue</strong></div>}</article><article className="panel"><div className="panel-title"><h3>Invoice status</h3></div>{data?.invoice_statuses.map((row) => <div className="insight-row" key={row.status}><strong>{readable(row.status)}</strong><span>{row.count}</span></div>)}</article><article className="panel"><div className="panel-title"><h3>Monthly SaaS revenue</h3></div>{data?.timeline.slice(-6).map((row) => <div className="insight-row" key={row.month}><strong>{row.month}</strong><span>{moneyByCurrency(row.revenue_by_currency)}</span></div>)}</article></section>
  </>;
}
