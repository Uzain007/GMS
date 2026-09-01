"use client";

import { FormEvent, useState } from "react";
import { Banknote, CheckCircle2, CircleDollarSign, CreditCard, Download, FileCheck2, FilePlus2, Link2, Plus, ReceiptText, RefreshCw, RotateCcw, Search, ShieldCheck, WalletCards, X } from "lucide-react";
import type { IronCoreRole } from "./lib/ironcore-api";
import { decimalToMinor } from "./tenant-operations";

export type FinancePayment = { id: string; memberId: string; invoiceId: string | null; branchId: string | null; receipt: string; provider: "manual" | "stripe"; method: "cash" | "card" | "bank_transfer" | "online_card" | "other"; status: "pending" | "paid" | "rejected" | "partially_refunded" | "refunded" | "voided"; amountMinor: number; refundedMinor: number; currency: string; paidAt: string | null; notes: string | null; bankReceipt: { originalName: string; bankReference: string | null; transferredOn: string | null; reviewedAt: string | null; reviewReason: string | null } | null };
export type FinanceInvoice = { id: string; memberId: string; membershipId: string | null; branchId: string | null; number: string; status: "draft" | "open" | "paid" | "void" | "uncollectible"; currency: string; totalMinor: number; paidMinor: number; dueMinor: number; issuedAt: string; dueAt: string | null; notes: string | null };
export type FinanceGateway = { status: "pending" | "restricted" | "active" | "disabled"; chargesEnabled: boolean; payoutsEnabled: boolean; detailsSubmitted: boolean; accountId: string | null; requirements: string[] } | null;
export type FinanceSummary = { grossMinor: number; refundedMinor: number; netMinor: number; pendingMinor: number; outstandingMinor: number; currency: string };
export type NewFinanceInvoice = { member_id: string; membership_id?: string; branch_id?: string; currency: "GBP" | "USD" | "PKR" | "AED" | "SAR"; due_at?: string; notes?: string; items: Array<{ description: string; quantity: number; unit_amount_minor: number; tax_amount_minor?: number }> };
export type NewFinancePayment = { member_id: string; membership_id?: string; invoice_id?: string; branch_id?: string; method: FinancePayment["method"]; amount_minor: number; currency: "GBP" | "USD" | "PKR" | "AED" | "SAR"; idempotency_key: string; notes?: string; bank_reference?: string; transferred_on?: string; receipt?: File };
export type FinanceData = {
  payments: FinancePayment[];
  invoices: FinanceInvoice[];
  members: Array<{ id: string; name: string }>;
  memberships: Array<{ id: string; memberId: string; label: string }>;
  branches: Array<{ id: string; name: string }>;
  summary: FinanceSummary;
  gateway: FinanceGateway;
  stripeConfigured: boolean;
  stripeAvailable: boolean;
  actorRole: IronCoreRole;
  readOnly?: boolean;
  loading: boolean;
  error: string | null;
  onReload: () => void;
  onCreateInvoice: (input: NewFinanceInvoice) => Promise<void>;
  onCreatePayment: (input: NewFinancePayment) => Promise<string | null>;
  onRefund: (paymentId: string, amountMinor: number, reason: string) => Promise<void>;
  onReviewBankTransfer: (paymentId: string, decision: "approve" | "reject", reason: string) => Promise<void>;
  onLoadReceipt: (paymentId: string) => Promise<Blob>;
  onConnectStripe: () => Promise<string>;
  onRefreshStripe: () => Promise<void>;
};

const money = (minor: number, currency: string) => new Intl.NumberFormat("en", { style: "currency", currency }).format(minor / 100);
const shownDate = (value: string | null) => value ? new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(value)) : "Not set";
const methodLabel: Record<FinancePayment["method"], string> = { cash: "Cash", card: "Card terminal", bank_transfer: "Bank transfer", online_card: "Online card", other: "Other" };
const settled = new Set<FinancePayment["status"]>(["paid", "partially_refunded"]);
const paymentStatusLabel = (payment: FinancePayment) => payment.method === "bank_transfer" && payment.status === "pending" ? "Pending Verification" : payment.status.replaceAll("_", " ");

function paymentRequestKey(): string {
  if (typeof globalThis.crypto?.randomUUID === "function") return globalThis.crypto.randomUUID();
  if (typeof globalThis.crypto?.getRandomValues !== "function") throw new Error("Secure payment request identifiers are unavailable in this browser.");
  // Some local HTTP origins omit randomUUID; getRandomValues preserves the
  // cryptographic uniqueness needed for tenant-local payment idempotency.
  const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
  return `web-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("")}`;
}

export function FinancialManagement({ data, query }: { data: FinanceData; query: string }) {
  const [tab, setTab] = useState<"payments" | "invoices">("payments");
  const [invoiceOpen, setInvoiceOpen] = useState(false);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [selectedInvoice, setSelectedInvoice] = useState<string | null>(null);
  const [refund, setRefund] = useState<FinancePayment | null>(null);
  const [bankReview, setBankReview] = useState<FinancePayment | null>(null);
  const member = (id: string) => data.members.find((row) => row.id === id)?.name ?? "Unknown member";
  const canRefund = !data.readOnly && ["super_admin", "gym_owner"].includes(data.actorRole);
  const canManageGateway = !data.readOnly && ["super_admin", "gym_owner"].includes(data.actorRole);
  const canReviewBank = !data.readOnly && ["super_admin", "gym_owner", "gym_manager"].includes(data.actorRole);
  const filteredPayments = data.payments.filter((row) => `${row.receipt} ${member(row.memberId)} ${methodLabel[row.method]} ${row.status}`.toLowerCase().includes(query.toLowerCase()));
  const filteredInvoices = data.invoices.filter((row) => `${row.number} ${member(row.memberId)} ${row.status}`.toLowerCase().includes(query.toLowerCase()));

  function payInvoice(invoiceId: string) { setSelectedInvoice(invoiceId); setPaymentOpen(true); }

  return <>
    <section className="module-heading"><div><p className="eyebrow">Tenant finance</p><h2>Payments & invoices</h2><p>{data.readOnly ? "Representative ledger records for product review." : "Collect online payments, record front-desk transactions and preserve a complete refund trail."}</p></div>{!data.readOnly && <div className="finance-actions"><button className="secondary-button" onClick={() => setInvoiceOpen(true)}><FilePlus2 size={17} /> Create invoice</button><button className="primary-button" onClick={() => { setSelectedInvoice(null); setPaymentOpen(true); }}><Plus size={17} /> Record payment</button></div>}</section>
    <div className="live-scope-banner"><ShieldCheck size={17} /><span><strong>Live tenant ledger</strong><small>Every invoice, payment and refund is isolated by route, policy, gym_id and PostgreSQL RLS.</small></span></div>
    <section className="mini-metrics finance-metrics"><article><span>Net collected</span><strong>{money(data.summary.netMinor, data.summary.currency)}</strong><small>{money(data.summary.grossMinor, data.summary.currency)} gross</small></article><article><span>Outstanding</span><strong>{money(data.summary.outstandingMinor, data.summary.currency)}</strong><small>Open invoice balances</small></article><article><span>Pending payments</span><strong>{money(data.summary.pendingMinor, data.summary.currency)}</strong><small>Awaiting confirmation or review</small></article><article><span>Refunded</span><strong>{money(data.summary.refundedMinor, data.summary.currency)}</strong><small>Approved returns</small></article></section>
    <GatewayCard data={data} canManage={canManageGateway} />
    <div className="finance-tabs" role="tablist"><button className={tab === "payments" ? "active" : ""} onClick={() => setTab("payments")}><WalletCards size={16} /> Payments <span>{data.payments.length}</span></button><button className={tab === "invoices" ? "active" : ""} onClick={() => setTab("invoices")}><ReceiptText size={16} /> Invoices <span>{data.invoices.length}</span></button></div>
    {data.loading ? <div className="table-state"><RefreshCw className="spin" size={20} /> Loading tenant finance…</div> : data.error ? <div className="table-state error" role="alert"><strong>Finance could not be loaded</strong><span>{data.error}</span><button className="secondary-button" onClick={data.onReload}>Try again</button></div> : tab === "payments" ? <PaymentsTable rows={filteredPayments} member={member} canRefund={canRefund} canReviewBank={canReviewBank} onRefund={setRefund} onReview={setBankReview} onReceipt={data.onLoadReceipt} /> : <InvoicesTable rows={filteredInvoices} member={member} onPay={data.readOnly ? undefined : payInvoice} />}
    {invoiceOpen && <InvoiceModal data={data} close={() => setInvoiceOpen(false)} />}
    {paymentOpen && <PaymentModal data={data} invoiceId={selectedInvoice} close={() => { setPaymentOpen(false); setSelectedInvoice(null); }} />}
    {refund && <RefundModal payment={refund} data={data} close={() => setRefund(null)} />}
    {bankReview && <BankReviewModal payment={bankReview} data={data} close={() => setBankReview(null)} />}
  </>;
}

function GatewayCard({ data, canManage }: { data: FinanceData; canManage: boolean }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null);
  const active = data.stripeAvailable;
  async function connect() { setBusy(true); setError(null); try { const url = await data.onConnectStripe(); window.location.assign(url); } catch (reason) { setError(reason instanceof Error ? reason.message : "Stripe onboarding could not start."); setBusy(false); } }
  async function refresh() { setBusy(true); setError(null); try { await data.onRefreshStripe(); } catch (reason) { setError(reason instanceof Error ? reason.message : "Stripe status could not be refreshed."); } finally { setBusy(false); } }
  return <section className={`gateway-card ${active ? "connected" : ""}`}><span className="gateway-icon">{active ? <CheckCircle2 size={21} /> : <Link2 size={21} />}</span><div><strong>{active ? "Stripe connected" : data.stripeConfigured ? "Stripe available · not connected" : "Stripe · Not configured"}</strong><small>{active ? `Direct charges enabled${data.gateway?.accountId ? ` · ${data.gateway.accountId}` : ""}` : data.gateway?.status === "restricted" ? "Stripe needs more business information before charges can start." : data.stripeConfigured ? "Connect this gym to accept online cards. Cash and bank transfer remain available." : "Membership plans, cash and bank transfer work normally without Stripe."}</small>{error && <span className="gateway-error">{error}</span>}</div><div className="gateway-actions">{data.gateway && data.stripeConfigured && !data.readOnly && <button className="secondary-button" disabled={busy} onClick={refresh}><RefreshCw className={busy ? "spin" : ""} size={15} /> Refresh</button>}{data.stripeConfigured && !active && canManage && <button className="primary-button" disabled={busy} onClick={connect}><CreditCard size={16} /> {data.gateway ? "Continue setup" : "Connect Stripe"}</button>}</div></section>;
}

function PaymentsTable({ rows, member, canRefund, canReviewBank, onRefund, onReview, onReceipt }: { rows: FinancePayment[]; member: (id: string) => string; canRefund: boolean; canReviewBank: boolean; onRefund: (row: FinancePayment) => void; onReview: (row: FinancePayment) => void; onReceipt: (paymentId: string) => Promise<Blob> }) {
  if (!rows.length) return <div className="panel empty-state"><Search size={24} /><strong>No payments yet</strong><span>Record cash, terminal, bank or online card payments.</span></div>;
  return <section className="panel table-scroll"><table className="data-table"><thead><tr><th>Receipt</th><th>Member</th><th>Method</th><th>Amount</th><th>Refunded</th><th>Paid</th><th>Status</th><th /></tr></thead><tbody>{rows.map((row) => { const remaining = row.amountMinor - row.refundedMinor; return <tr key={row.id}><td><strong>{row.receipt}</strong><small className="table-sub">{row.provider === "stripe" ? "Stripe direct charge" : row.method === "bank_transfer" ? row.bankReceipt?.bankReference ?? "Receipt submitted" : "Recorded by staff"}</small></td><td>{member(row.memberId)}</td><td><span className={`method-chip ${row.method}`}>{row.method === "cash" ? <Banknote size={13} /> : <CreditCard size={13} />}{methodLabel[row.method]}</span></td><td><strong>{money(row.amountMinor, row.currency)}</strong></td><td>{row.refundedMinor ? money(row.refundedMinor, row.currency) : "—"}</td><td>{shownDate(row.paidAt)}</td><td><span className={`status ${row.status}`}><i />{paymentStatusLabel(row)}</span></td><td><div className="payment-row-actions">{row.bankReceipt && <button className="table-action" onClick={() => void openReceipt(onReceipt, row.id)}><Download size={14} /> Receipt</button>}{canReviewBank && row.method === "bank_transfer" && row.status === "pending" && <button className="table-action" onClick={() => onReview(row)}><FileCheck2 size={14} /> Review</button>}{canRefund && settled.has(row.status) && remaining > 0 && <button className="table-action" onClick={() => onRefund(row)}><RotateCcw size={14} /> Refund</button>}</div></td></tr>; })}</tbody></table></section>;
}

async function openReceipt(load: (paymentId: string) => Promise<Blob>, paymentId: string): Promise<void> {
  const blob = await load(paymentId);
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = "bank-transfer-receipt";
  anchor.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
}

function InvoicesTable({ rows, member, onPay }: { rows: FinanceInvoice[]; member: (id: string) => string; onPay?: (id: string) => void }) {
  if (!rows.length) return <div className="panel empty-state"><Search size={24} /><strong>No invoices yet</strong><span>Create the first member invoice for this gym.</span></div>;
  return <section className="panel table-scroll"><table className="data-table"><thead><tr><th>Invoice</th><th>Member</th><th>Total</th><th>Paid</th><th>Balance</th><th>Due</th><th>Status</th><th /></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td><strong>{row.number}</strong><small className="table-sub">Issued {shownDate(row.issuedAt)}</small></td><td>{member(row.memberId)}</td><td>{money(row.totalMinor, row.currency)}</td><td>{money(row.paidMinor, row.currency)}</td><td><strong>{money(row.dueMinor, row.currency)}</strong></td><td>{shownDate(row.dueAt)}</td><td><span className={`status ${row.status}`}><i />{row.status}</span></td><td>{row.status === "open" && onPay && <button className="table-action" onClick={() => onPay(row.id)}><CircleDollarSign size={14} /> Pay</button>}</td></tr>)}</tbody></table></section>;
}

function Modal({ title, icon: Icon, close, submit, submitLabel, children }: { title: string; icon: typeof CreditCard; close: () => void; submit: (form: HTMLFormElement) => Promise<void>; submitLabel: string; children: React.ReactNode }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null);
  async function handle(event: FormEvent<HTMLFormElement>) { event.preventDefault(); setBusy(true); setError(null); try { await submit(event.currentTarget); close(); } catch (reason) { setError(reason instanceof Error ? reason.message : "The finance action failed."); } finally { setBusy(false); } }
  return <div className="modal-layer" role="dialog" aria-modal="true"><button className="modal-scrim" onClick={close} aria-label="Close dialog" /><form className="modal-card finance-modal" onSubmit={handle}><div className="modal-heading"><span><Icon size={21} /></span><div><p className="eyebrow">Tenant ledger</p><h2>{title}</h2></div><button type="button" className="icon-button" onClick={close}><X size={19} /></button></div>{error && <div className="form-error" role="alert">{error}</div>}{children}<div className="modal-note"><ShieldCheck size={17} />Amounts are submitted as exact minor units; Laravel recalculates and validates all linked tenant records.</div><div className="modal-actions"><button type="button" className="secondary-button" onClick={close}>Cancel</button><button disabled={busy} className="primary-button">{busy ? "Saving…" : submitLabel}</button></div></form></div>;
}

function InvoiceModal({ data, close }: { data: FinanceData; close: () => void }) {
  const [memberId, setMemberId] = useState(data.members[0]?.id ?? "");
  const memberships = data.memberships.filter((row) => row.memberId === memberId);
  return <Modal title="Create invoice" icon={FilePlus2} close={close} submitLabel="Create invoice" submit={async (form) => { const value = new FormData(form); await data.onCreateInvoice({ member_id: memberId, membership_id: String(value.get("membership")) || undefined, branch_id: String(value.get("branch")) || undefined, currency: data.summary.currency as NewFinanceInvoice["currency"], due_at: String(value.get("due")) || undefined, notes: String(value.get("notes")) || undefined, items: [{ description: String(value.get("description")), quantity: Number(value.get("quantity")), unit_amount_minor: decimalToMinor(String(value.get("price"))), tax_amount_minor: decimalToMinor(String(value.get("tax") || "0")) }] }); }}><label>Member<select value={memberId} onChange={(event) => setMemberId(event.target.value)} required>{data.members.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label><label>Membership<select name="membership"><option value="">No linked membership</option>{memberships.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}</select></label><label>Description<input name="description" required maxLength={240} autoFocus /></label><div className="field-pair"><label>Quantity<input name="quantity" type="number" min="1" max="1000" defaultValue="1" required /></label><label>Unit price ({data.summary.currency})<input name="price" inputMode="decimal" required /></label></div><div className="field-pair"><label>Tax amount<input name="tax" inputMode="decimal" defaultValue="0" /></label><label>Due date<input name="due" type="date" /></label></div><label>Branch<select name="branch"><option value="">Member/default branch</option>{data.branches.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label><label>Notes<textarea name="notes" rows={3} maxLength={2000} /></label></Modal>;
}

function PaymentModal({ data, invoiceId, close }: { data: FinanceData; invoiceId: string | null; close: () => void }) {
  const initialInvoice = data.invoices.find((row) => row.id === invoiceId);
  const [memberId, setMemberId] = useState(initialInvoice?.memberId ?? data.members[0]?.id ?? "");
  const [selectedInvoiceId, setSelectedInvoiceId] = useState(invoiceId ?? "");
  const [method, setMethod] = useState<FinancePayment["method"]>("cash");
  const invoices = data.invoices.filter((row) => row.memberId === memberId && row.status === "open");
  const memberships = data.memberships.filter((row) => row.memberId === memberId);
  const selected = data.invoices.find((row) => row.id === selectedInvoiceId);
  const defaultAmount = selected ? (selected.dueMinor / 100).toFixed(2) : "";
  return <Modal title="Record payment" icon={CreditCard} close={close} submitLabel={method === "online_card" ? "Open secure checkout" : method === "bank_transfer" ? "Submit for review" : "Record payment"} submit={async (form) => { const value = new FormData(form); const receipt = value.get("receipt"); const url = await data.onCreatePayment({ member_id: memberId, membership_id: String(value.get("membership")) || undefined, invoice_id: selectedInvoiceId || undefined, branch_id: String(value.get("branch")) || undefined, method, amount_minor: decimalToMinor(String(value.get("amount"))), currency: data.summary.currency as NewFinancePayment["currency"], // A cryptographic request key prevents double-click duplicate payments inside this gym.
    idempotency_key: paymentRequestKey(), notes: String(value.get("notes")) || undefined, bank_reference: String(value.get("bank_reference")) || undefined, receipt: receipt instanceof File && receipt.size > 0 ? receipt : undefined }); if (url) window.location.assign(url); }}><label>Member<select value={memberId} onChange={(event) => { setMemberId(event.target.value); setSelectedInvoiceId(""); }} required>{data.members.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label><label>Invoice<select value={selectedInvoiceId} onChange={(event) => setSelectedInvoiceId(event.target.value)}><option value="">Unallocated payment</option>{invoices.map((row) => <option key={row.id} value={row.id}>{row.number} · {money(row.dueMinor, row.currency)} due</option>)}</select></label><label>Membership<select name="membership" required={method === "bank_transfer"}><option value="">No linked membership</option>{memberships.map((row) => <option key={row.id} value={row.id}>{row.label}</option>)}</select></label><div className="field-pair"><label>Method<select value={method} onChange={(event) => setMethod(event.target.value as FinancePayment["method"])}><option value="cash">Cash</option><option value="card">Card terminal</option><option value="bank_transfer">Bank transfer</option><option value="online_card" disabled={!data.stripeAvailable}>Online card (Stripe{data.stripeAvailable ? "" : " · Not configured"})</option><option value="other">Other</option></select></label><label>Amount ({data.summary.currency})<input key={`${selectedInvoiceId}-${defaultAmount}`} name="amount" inputMode="decimal" defaultValue={defaultAmount} required /></label></div><label>Branch<select name="branch"><option value="">Member/default branch</option>{data.branches.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label>{method === "bank_transfer" && <div className="bank-receipt-fields"><label>Bank reference<input name="bank_reference" maxLength={160} placeholder="Transfer reference (optional)" /></label><label>Payment receipt<input name="receipt" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" required /><small>Private PDF or image, maximum 10 MB.</small></label></div>}<label>Notes<textarea name="notes" rows={3} maxLength={2000} /></label>{method === "card" && <div className="payment-safety"><CreditCard size={16} /><span><strong>Terminal record only</strong><small>Process the card on your payment terminal. Never enter a card number in IronCore.</small></span></div>}{method === "bank_transfer" && <div className="payment-safety"><FileCheck2 size={16} /><span><strong>Approval required</strong><small>The receipt stays pending until a gym administrator verifies it.</small></span></div>}{method === "online_card" && <div className="payment-safety"><Link2 size={16} /><span><strong>Stripe-hosted payment</strong><small>The member completes card entry on Stripe; IronCore stores only settlement references.</small></span></div>}</Modal>;
}

function RefundModal({ payment, data, close }: { payment: FinancePayment; data: FinanceData; close: () => void }) {
  const remaining = payment.amountMinor - payment.refundedMinor;
  return <Modal title={`Refund ${payment.receipt}`} icon={RotateCcw} close={close} submitLabel="Issue refund" submit={async (form) => { const value = new FormData(form); await data.onRefund(payment.id, decimalToMinor(String(value.get("amount"))), String(value.get("reason"))); }}><div className="refund-summary"><span>Refundable balance</span><strong>{money(remaining, payment.currency)}</strong></div><label>Refund amount ({payment.currency})<input name="amount" inputMode="decimal" defaultValue={(remaining / 100).toFixed(2)} required /></label><label>Audit reason<textarea name="reason" rows={4} required minLength={5} maxLength={1000} placeholder="Why is this refund being issued?" /></label><div className="payment-safety warning"><RotateCcw size={16} /><span><strong>Financial action</strong><small>This creates an immutable refund record and adjusts the linked invoice balance.</small></span></div></Modal>;
}

function BankReviewModal({ payment, data, close }: { payment: FinancePayment; data: FinanceData; close: () => void }) {
  return <Modal title={`Review ${payment.receipt}`} icon={FileCheck2} close={close} submitLabel="Save decision" submit={async (form) => { const value = new FormData(form); await data.onReviewBankTransfer(payment.id, String(value.get("decision")) as "approve" | "reject", String(value.get("reason"))); }}><div className="refund-summary"><span>Transfer amount</span><strong>{money(payment.amountMinor, payment.currency)}</strong></div>{payment.bankReceipt && <><dl className="platform-detail-grid"><div><dt>Payment reference</dt><dd>{payment.bankReceipt.bankReference || "Not provided"}</dd></div><div><dt>Transfer date</dt><dd>{payment.bankReceipt.transferredOn || "Not provided"}</dd></div></dl><button type="button" className="secondary-button receipt-download" onClick={() => void openReceipt(data.onLoadReceipt, payment.id)}><Download size={15} /> Download {payment.bankReceipt.originalName}</button></>}<label>Decision<select name="decision" defaultValue="approve"><option value="approve">Approve and mark Paid</option><option value="reject">Reject payment</option></select></label><label>Audit reason<textarea name="reason" rows={4} required minLength={5} maxLength={1000} placeholder="What did you verify?" /></label><div className="payment-safety warning"><ShieldCheck size={16} /><span><strong>Protected review</strong><small>Approval settles the linked invoice. Rejection leaves its balance outstanding.</small></span></div></Modal>;
}
