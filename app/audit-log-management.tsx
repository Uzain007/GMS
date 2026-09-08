"use client";

import { Download, FileClock, LoaderCircle, Search, ShieldCheck } from "lucide-react";
import { FormEvent, useEffect, useState } from "react";
import type { AuditLogFilters, AuditLogRecord, GymSummary, Paginated } from "./lib/ironcore-api";

export function AuditLogManagement({ gyms, onLoad, onExport }: {
  gyms: GymSummary[];
  onLoad: (filters: AuditLogFilters) => Promise<Paginated<AuditLogRecord>>;
  onExport: (format: "csv" | "xlsx" | "pdf", filters: AuditLogFilters) => Promise<void>;
}) {
  const [filters, setFilters] = useState<AuditLogFilters>({ per_page: 25, page: 1 });
  const [page, setPage] = useState<Paginated<AuditLogRecord> | null>(null);
  const [busy, setBusy] = useState(true);
  const [exporting, setExporting] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    void onLoad(filters).then((result) => { if (active) setPage(result); }).catch((reason) => {
      if (active) setError(reason instanceof Error ? reason.message : "Audit history could not be loaded.");
    }).finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
  }, [filters, onLoad]);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    setFilters({
      from: String(form.get("from")) || undefined, to: String(form.get("to")) || undefined,
      gym_id: String(form.get("gym_id")) || undefined, role: String(form.get("role")) || undefined,
      action: String(form.get("action")) || undefined, resource: String(form.get("resource")) || undefined,
      search: String(form.get("search")) || undefined, per_page: 25, page: 1,
    });
  }

  async function exportData(format: "csv" | "xlsx" | "pdf") {
    setExporting(format); setError(null);
    try { await onExport(format, filters); }
    catch (reason) { setError(reason instanceof Error ? reason.message : "Audit export failed."); }
    finally { setExporting(null); }
  }

  return <>
    <section className="module-heading"><div><p className="eyebrow">Security and accountability</p><h2>Audit Log</h2><p>Search the existing, protected IronCore activity history for the last 365 days.</p></div><div className="audit-export-actions">{(["csv", "xlsx", "pdf"] as const).map((format) => <button key={format} className="secondary-button" disabled={exporting !== null} onClick={() => void exportData(format)}><Download size={15} /> {exporting === format ? "Preparing…" : format.toUpperCase()}</button>)}</div></section>
    <form className="panel audit-filters" onSubmit={submit}>
      <label className="audit-search"><span>Search</span><span className="search-box"><Search size={16} /><input name="search" defaultValue={filters.search} placeholder="User, action, reason or resource" /></span></label>
      <label>From<input name="from" type="date" defaultValue={filters.from} /></label><label>To<input name="to" type="date" defaultValue={filters.to} /></label>
      <label>Gym<select name="gym_id" defaultValue={filters.gym_id}><option value="">All gyms</option>{gyms.map((gym) => <option key={gym.id} value={gym.id}>{gym.name}</option>)}</select></label>
      <label>Role<select name="role" defaultValue={filters.role}><option value="">All roles</option><option value="super_admin">Super Admin</option><option value="gym_owner">Gym Owner</option><option value="gym_manager">Gym Manager</option><option value="receptionist">Reception</option><option value="trainer">Trainer</option><option value="member">Member</option></select></label>
      <label>Action<input name="action" defaultValue={filters.action} placeholder="e.g. gym.updated" /></label><label>Resource<input name="resource" defaultValue={filters.resource} placeholder="e.g. Payment" /></label>
      <button className="primary-button" type="submit">Apply filters</button>
    </form>
    {error && <div className="form-error" role="alert">{error}</div>}
    <section className="panel table-scroll audit-table">{busy ? <div className="table-state"><LoaderCircle className="spin" size={20} /> Loading protected history…</div> : <><table className="data-table"><thead><tr><th>Date/time</th><th>User / role</th><th>Gym</th><th>Action</th><th>Resource</th><th>Changes</th><th>Reason</th><th>IP / device</th></tr></thead><tbody>{page?.data.map((entry) => <tr key={entry.id}><td>{new Date(entry.created_at).toLocaleString()}</td><td><strong>{entry.user?.name ?? "System"}</strong><small className="table-sub">{entry.role?.replaceAll("_", " ") ?? "system"}</small></td><td>{entry.gym?.name ?? "Platform"}</td><td>{entry.action}</td><td>{entry.resource?.split("\\").at(-1) ?? "—"}<small className="table-sub">{entry.resource_id ?? ""}</small></td><td><details><summary>View</summary><pre>{JSON.stringify({ old: entry.old_value, new: entry.new_value }, null, 2)}</pre></details></td><td>{entry.reason ?? "—"}</td><td>{entry.ip_address ?? "—"}<small className="table-sub audit-device">{entry.device ?? "No device metadata"}</small></td></tr>)}</tbody></table>{!page?.data.length && <div className="empty-state"><FileClock size={24} /><strong>No matching activity</strong><span>Change the filters or date range.</span></div>}</>}</section>
    {page && page.meta.last_page > 1 && <div className="pagination-controls"><button className="secondary-button" disabled={page.meta.current_page <= 1 || busy} onClick={() => { setBusy(true); setFilters((value) => ({ ...value, page: (value.page ?? 1) - 1 })); }}>Previous</button><span>Page {page.meta.current_page} of {page.meta.last_page} · {page.meta.total} records</span><button className="secondary-button" disabled={page.meta.current_page >= page.meta.last_page || busy} onClick={() => { setBusy(true); setFilters((value) => ({ ...value, page: (value.page ?? 1) + 1 })); }}>Next</button></div>}
    <div className="live-scope-banner"><ShieldCheck size={17} /><span><strong>Protected history</strong><small>Encrypted before/after values are decrypted only after Super Admin authorization and PostgreSQL policy checks.</small></span></div>
  </>;
}
