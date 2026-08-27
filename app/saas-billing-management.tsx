"use client";

import { useMemo, useState, type FormEvent } from "react";
import {
  ArrowUpRight, Building2, CalendarDays, Check, CircleDollarSign,
  Banknote, CreditCard, Download, FileText, FileUp, LoaderCircle, Plus,
  RefreshCw, ShieldCheck, Sparkles, X,
} from "lucide-react";
import type {
  GymSubscriptionRecord, IronCoreRole, NewSaasPlan, SaasBillingInvoiceRecord,
  SaasPaymentOptions, SaasPlanRecord, SaasSubscriptionPaymentRecord,
} from "./lib/ironcore-api";

type Currency = "GBP" | "USD" | "PKR" | "AED" | "SAR";

export type SaasBillingData = {
  plans: SaasPlanRecord[];
  subscription: GymSubscriptionRecord | null;
  invoices: SaasBillingInvoiceRecord[];
  payments: SaasSubscriptionPaymentRecord[];
  paymentOptions: SaasPaymentOptions;
  baseCurrency: Currency;
  actorRole: IronCoreRole;
  readOnly?: boolean;
  loading: boolean;
  error: string | null;
  onReload: () => void;
  onCheckout: (priceId: string, idempotencyKey: string) => Promise<string>;
  onManualPayment: (priceId: string, method: "cash" | "bank_transfer", reference: string, idempotencyKey: string, receipt?: File) => Promise<void>;
  onReviewManualPayment: (paymentId: string, decision: "approve" | "reject", reason: string) => Promise<void>;
  onLoadManualReceipt: (paymentId: string) => Promise<Blob>;
  onPortal: () => Promise<string>;
  onCreatePlan?: (input: NewSaasPlan) => Promise<void>;
};

function money(minor: number, currency: Currency): string {
  return new Intl.NumberFormat("en-GB", {
    style: "currency", currency, minimumFractionDigits: 0, maximumFractionDigits: 2,
  }).format(minor / 100);
}

function readable(value: string): string {
  return value.replaceAll("_", " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function date(value: string | null): string {
  return value ? new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(value)) : "—";
}

function requestKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") return crypto.randomUUID();
  if (typeof crypto !== "undefined" && typeof crypto.getRandomValues === "function") {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    return Array.from(bytes, (value) => value.toString(16).padStart(2, "0")).join("");
  }
  throw new Error("A secure browser random generator is required.");
}

function featureList(plan: SaasPlanRecord): string[] {
  const limits = plan.feature_limits;
  return [
    `${limits.members.toLocaleString()} members`,
    `${limits.branches.toLocaleString()} branches · ${limits.staff.toLocaleString()} staff`,
    limits.advanced_reports ? "Advanced reporting" : "Core reporting",
    limits.priority_support ? "Priority support" : "Standard support",
  ];
}

function PlanModal({ currency, onClose, onCreate }: { currency: Currency; onClose: () => void; onCreate: (input: NewSaasPlan) => Promise<void> }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(null);
    const data = new FormData(event.currentTarget);
    const amountMinor = Math.round(Number(data.get("amount")) * 100);
    try {
      if (!Number.isSafeInteger(amountMinor) || amountMinor < 1) throw new Error("Enter a valid recurring price.");
      await onCreate({
        code: String(data.get("code")).trim().toLowerCase(),
        name: String(data.get("name")).trim(),
        description: String(data.get("description")).trim() || undefined,
        sort_order: Number(data.get("sort_order")),
        currency: String(data.get("currency")) as Currency,
        billing_interval: String(data.get("billing_interval")) as "monthly" | "yearly",
        amount_minor: amountMinor,
        trial_days: Number(data.get("trial_days")),
        feature_limits: {
          members: Number(data.get("members")), branches: Number(data.get("branches")),
          staff: Number(data.get("staff")), advanced_reports: data.get("advanced_reports") === "on",
          priority_support: data.get("priority_support") === "on",
        },
        payment_methods: [
          data.get("bank_transfer") === "on" ? "bank_transfer" : null,
          data.get("cash") === "on" ? "cash" : null,
          data.get("stripe") === "on" ? "stripe" : null,
        ].filter((method): method is "bank_transfer" | "cash" | "stripe" => method !== null),
      });
      onClose();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The SaaS plan could not be created.");
    } finally { setBusy(false); }
  }
  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="saas-plan-title">
    <button className="modal-scrim" onClick={onClose} aria-label="Close plan dialog" />
    <form className="modal-card saas-plan-modal" onSubmit={submit}>
      <div className="modal-heading"><span><Sparkles size={21} /></span><div><p className="eyebrow">Platform catalogue</p><h2 id="saas-plan-title">Create SaaS plan</h2></div><button className="icon-button" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>
      {error && <div className="form-error" role="alert">{error}</div>}
      <div className="field-pair"><label>Plan name<input name="name" required maxLength={160} autoFocus placeholder="Growth" /></label><label>Code<input name="code" required maxLength={60} pattern="[A-Za-z0-9_-]+" placeholder="growth" /></label></div>
      <label>Description<textarea name="description" maxLength={2000} rows={2} placeholder="For independent gyms ready to scale." /></label>
      <div className="field-pair"><label>Price<input name="amount" required type="number" min="0.01" step="0.01" placeholder="79.00" /></label><label>Currency<select name="currency" defaultValue={currency}>{["GBP", "USD", "PKR", "AED", "SAR"].map((code) => <option key={code}>{code}</option>)}</select></label></div>
      <div className="field-pair"><label>Billing interval<select name="billing_interval" defaultValue="monthly"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></label><label>Trial days<input name="trial_days" type="number" min="0" max="90" defaultValue="14" /></label></div>
      <div className="field-triple"><label>Members<input name="members" required type="number" min="1" defaultValue="2500" /></label><label>Branches<input name="branches" required type="number" min="1" defaultValue="3" /></label><label>Staff<input name="staff" required type="number" min="1" defaultValue="25" /></label></div>
      <div className="check-row"><label><input name="advanced_reports" type="checkbox" /> Advanced reports</label><label><input name="priority_support" type="checkbox" /> Priority support</label></div>
      <div className="check-row payment-method-checks"><label><input name="bank_transfer" type="checkbox" defaultChecked /> Bank transfer</label><label><input name="cash" type="checkbox" defaultChecked /> Cash</label><label><input name="stripe" type="checkbox" /> Debit/Credit Card · Stripe</label></div>
      <input name="sort_order" type="hidden" value="100" />
      <div className="modal-safety"><ShieldCheck size={17} /><span><strong>Immutable pricing</strong><small>Publishing stores the plan in IronCore. Stripe is synchronized only when a gym chooses card payment.</small></span></div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose}>Cancel</button><button className="primary-button" disabled={busy} type="submit">{busy ? "Publishing…" : "Publish plan"} <ArrowUpRight size={16} /></button></div>
    </form>
  </div>;
}

type ManualChoice = {
  priceId: string;
  planName: string;
  method: "cash" | "bank_transfer";
};

function ManualPaymentModal({ choice, onClose, onSubmit }: {
  choice: ManualChoice;
  onClose: () => void;
  onSubmit: SaasBillingData["onManualPayment"];
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    const receipt = form.get("receipt");
    setBusy(true); setError(null);
    try {
      await onSubmit(
        choice.priceId,
        choice.method,
        String(form.get("reference")).trim(),
        requestKey(),
        receipt instanceof File && receipt.size > 0 ? receipt : undefined,
      );
      onClose();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The payment could not be submitted.");
    } finally { setBusy(false); }
  }

  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="saas-manual-payment-title">
    <button className="modal-scrim" onClick={onClose} aria-label="Close payment dialog" />
    <form className="modal-card" onSubmit={submit}>
      <div className="modal-heading"><span>{choice.method === "cash" ? <Banknote size={21} /> : <FileUp size={21} />}</span><div><p className="eyebrow">Platform subscription</p><h2 id="saas-manual-payment-title">{readable(choice.method)} · {choice.planName}</h2></div><button className="icon-button" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>
      {error && <div className="form-error" role="alert">{error}</div>}
      <label>{choice.method === "cash" ? "Cash receipt/reference" : "Bank transfer reference"}<input name="reference" required minLength={2} maxLength={160} autoFocus /></label>
      {choice.method === "bank_transfer" && <label>Payment receipt<input name="receipt" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" required /><small>Private PDF or image, maximum 10 MB.</small></label>}
      <div className="modal-safety"><ShieldCheck size={17} /><span><strong>Platform review required</strong><small>The subscription activates only after a Super Admin verifies this payment. Member-payment records remain separate.</small></span></div>
      <div className="modal-actions"><button className="secondary-button" type="button" onClick={onClose}>Cancel</button><button className="primary-button" disabled={busy}>{busy ? "Submitting…" : "Submit for review"}</button></div>
    </form>
  </div>;
}

function ManualReviewModal({ payment, onClose, onSubmit }: {
  payment: SaasSubscriptionPaymentRecord;
  onClose: () => void;
  onSubmit: SaasBillingData["onReviewManualPayment"];
}) {
  const [busy, setBusy] = useState<"approve" | "reject" | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function review(formElement: HTMLFormElement, decision: "approve" | "reject") {
    const form = new FormData(formElement);
    setBusy(decision); setError(null);
    try {
      await onSubmit(payment.id, decision, String(form.get("reason")).trim());
      onClose();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The review could not be saved.");
    } finally { setBusy(null); }
  }

  return <div className="modal-layer" role="dialog" aria-modal="true" aria-labelledby="saas-payment-review-title">
    <button className="modal-scrim" onClick={onClose} aria-label="Close review dialog" />
    <form className="modal-card" onSubmit={(event) => { event.preventDefault(); void review(event.currentTarget, "approve"); }}>
      <div className="modal-heading"><span><ShieldCheck size={21} /></span><div><p className="eyebrow">Super Admin review</p><h2 id="saas-payment-review-title">Review {readable(payment.method)} payment</h2></div><button className="icon-button" type="button" onClick={onClose} aria-label="Close"><X size={19} /></button></div>
      {error && <div className="form-error" role="alert">{error}</div>}
      <p>{payment.plan?.name ?? "SaaS plan"} · {money(payment.amount_minor, payment.currency)}</p>
      <label>Audit reason<textarea name="reason" required minLength={5} maxLength={1000} rows={3} autoFocus /></label>
      <div className="modal-actions"><button className="secondary-button" type="button" disabled={busy !== null} onClick={(event) => { if (event.currentTarget.form) void review(event.currentTarget.form, "reject"); }}>{busy === "reject" ? "Rejecting…" : "Reject"}</button><button className="primary-button" disabled={busy !== null}>{busy === "approve" ? "Approving…" : "Approve and activate"}</button></div>
    </form>
  </div>;
}

export function SaasBillingManagement({ data }: { data: SaasBillingData }) {
  const [interval, setInterval] = useState<"monthly" | "yearly">("monthly");
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [modal, setModal] = useState(false);
  const [manualChoice, setManualChoice] = useState<ManualChoice | null>(null);
  const [reviewing, setReviewing] = useState<SaasSubscriptionPaymentRecord | null>(null);
  const canManage = !data.readOnly && ["super_admin", "gym_owner"].includes(data.actorRole);
  const current = data.subscription;
  const nextRenewal = current?.current_period_end ?? current?.trial_ends_at ?? null;
  const visiblePlans = useMemo(() => data.plans.filter((plan) => plan.status === "active"), [data.plans]);

  async function checkout(priceId: string) {
    setBusy(priceId); setNotice(null);
    try {
      const url = await data.onCheckout(priceId, requestKey());
      if (url.startsWith("https://")) window.open(url, "_blank", "noopener,noreferrer");
      setNotice("Secure subscription checkout is ready in the Stripe-hosted page.");
    } catch (reason) { setNotice(reason instanceof Error ? reason.message : "Checkout could not be started."); }
    finally { setBusy(null); }
  }

  async function portal() {
    setBusy("portal"); setNotice(null);
    try {
      const url = await data.onPortal();
      if (url.startsWith("https://")) window.open(url, "_blank", "noopener,noreferrer");
      setNotice("The secure Stripe billing portal is ready.");
    } catch (reason) { setNotice(reason instanceof Error ? reason.message : "The billing portal could not be opened."); }
    finally { setBusy(null); }
  }

  async function downloadReceipt(payment: SaasSubscriptionPaymentRecord) {
    setBusy(`receipt-${payment.id}`); setNotice(null);
    try {
      const blob = await data.onLoadManualReceipt(payment.id);
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = payment.receipt_original_name ?? "saas-bank-transfer-receipt";
      link.click();
      URL.revokeObjectURL(url);
    } catch (reason) {
      setNotice(reason instanceof Error ? reason.message : "The receipt could not be downloaded.");
    } finally { setBusy(null); }
  }

  return <section className="saas-workspace">
    <div className="module-heading"><div><p className="eyebrow">Platform subscription</p><h1>IronCore SaaS billing</h1><p>{data.readOnly ? "Representative subscription records for product review." : "Manage the selected gym&apos;s plan, recurring invoices and payment recovery separately from member collections."}</p></div>{!data.readOnly && <div className="finance-actions">{data.actorRole === "super_admin" && data.onCreatePlan && <button className="secondary-button" onClick={() => setModal(true)}><Plus size={17} /> New plan</button>}<button className="secondary-button" onClick={data.onReload}><RefreshCw size={16} /> Refresh</button>{current?.provider === "stripe" && canManage && <button className="primary-button" disabled={busy === "portal"} onClick={() => void portal()}><CreditCard size={17} /> Manage billing</button>}</div>}</div>
    <div className="billing-separation"><ShieldCheck size={19} /><span><strong>Money flows are isolated</strong><small>Gym-member payments use the gym&apos;s connected account. This subscription is collected only by IronCore&apos;s platform account.</small></span></div>
    {data.error && <div className="form-error" role="alert">{data.error}</div>}
    {notice && <div className="form-notice" role="status">{notice}</div>}
    {data.loading && <div className="table-state"><LoaderCircle className="spin" size={21} /><span>Loading protected billing records…</span></div>}

    <section className="saas-metrics">
      <article className="panel saas-current"><span className="saas-icon violet"><Building2 size={20} /></span><div><small>Current plan</small><strong>{current?.plan_name ?? "No subscription"}</strong><p>{current ? `${money(current.amount_minor, current.currency)} / ${current.billing_interval === "monthly" ? "month" : "year"}` : "Choose a plan and an available payment method."}</p></div><span className={`status ${current?.status ?? "inactive"}`}><i />{readable(current?.status ?? "Not subscribed")}</span></article>
      <article className="panel saas-stat"><span className="saas-icon green"><CalendarDays size={20} /></span><small>{current?.status === "trialing" ? "Trial ends" : "Next renewal"}</small><strong>{date(nextRenewal)}</strong><p>{current?.cancel_at_period_end ? "Cancels at period end" : current?.provider === "stripe" ? "Automatic collection" : current ? "Manual settlement" : "No active billing"}</p></article>
      <article className="panel saas-stat"><span className="saas-icon amber"><FileText size={20} /></span><small>Billing history</small><strong>{data.invoices.length}</strong><p>{data.invoices.filter((invoice) => invoice.status === "paid").length} paid invoices loaded</p></article>
    </section>

    <div className="billing-catalogue-head"><div><p className="eyebrow">Available tiers</p><h2>Choose the right plan</h2></div><div className="billing-toggle" role="group" aria-label="Billing interval"><button className={interval === "monthly" ? "active" : ""} onClick={() => setInterval("monthly")}>Monthly</button><button className={interval === "yearly" ? "active" : ""} onClick={() => setInterval("yearly")}>Yearly</button></div></div>
    <section className="billing-grid">{visiblePlans.map((plan, index) => {
      const price = plan.prices.find((item) => item.active && item.currency === data.baseCurrency && item.billing_interval === interval);
      const selected = current?.plan_code === plan.code;
      const methods = plan.payment_methods ?? ["stripe"];
      return <article className={`panel plan-card saas-plan-card ${selected || index === 1 ? "featured" : ""}`} key={plan.id}>
        {selected && <span className="tag">Current plan</span>}
        <span className="saas-icon violet"><CircleDollarSign size={20} /></span>
        <h3>{plan.name}</h3>
        <strong>{price ? money(price.amount_minor, price.currency) : "Not priced"}{price && <small>/{interval === "monthly" ? "month" : "year"}</small>}</strong>
        <p>{plan.description}</p>
        <ul>{featureList(plan).map((feature) => <li key={feature}><Check size={15} />{feature}</li>)}</ul>
        <div className="saas-plan-methods"><small>Payment options</small><span>{methods.map(readable).join(" · ")}</span></div>
        {canManage && !current && <div className="saas-plan-actions">
          {!price && <button className="secondary-button" disabled>No {data.baseCurrency} price</button>}
          {price && methods.includes("bank_transfer") && <button className="secondary-button" onClick={() => setManualChoice({ priceId: price.id, planName: plan.name, method: "bank_transfer" })}><FileUp size={15} /> Bank transfer</button>}
          {price && methods.includes("cash") && <button className="secondary-button" onClick={() => setManualChoice({ priceId: price.id, planName: plan.name, method: "cash" })}><Banknote size={15} /> Cash</button>}
          {price && methods.includes("stripe") && <button className="primary-button" disabled={!data.paymentOptions.stripe_configured || busy === price.id} onClick={() => void checkout(price.id)}><CreditCard size={15} /> {busy === price.id ? "Opening…" : data.paymentOptions.stripe_configured ? "Debit/Credit Card" : "Stripe · Not configured"}</button>}
        </div>}
      </article>;
    })}</section>
    {!data.loading && visiblePlans.length === 0 && <div className="empty-state panel"><Sparkles size={25} /><strong>No active SaaS plans</strong><span>A super administrator can publish the first immutable platform price.</span></div>}

    <section className="panel table-scroll saas-manual-payments"><div className="panel-title"><div><p className="eyebrow">Cash and bank transfer</p><h3>Manual payment reviews</h3></div><small>Separate platform ledger</small></div><table className="data-table"><thead><tr><th>Plan</th><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th><th /></tr></thead><tbody>{data.payments.map((payment) => <tr key={payment.id}><td><strong>{payment.plan?.name ?? "SaaS plan"}</strong><small className="table-sub">{date(payment.created_at)}</small></td><td>{readable(payment.method)}</td><td>{payment.reference ?? "—"}</td><td><strong>{money(payment.amount_minor, payment.currency)}</strong></td><td><span className={`status ${payment.status}`}><i />{readable(payment.status)}</span></td><td><div className="table-actions">{payment.has_receipt && <button className="table-action" disabled={busy === `receipt-${payment.id}`} onClick={() => void downloadReceipt(payment)}><Download size={13} /> Receipt</button>}{data.actorRole === "super_admin" && payment.status === "pending" && <button className="table-action" onClick={() => setReviewing(payment)}>Review</button>}</div></td></tr>)}</tbody></table>{data.payments.length === 0 && <div className="empty-state"><Banknote size={24} /><strong>No manual SaaS payments</strong><span>Cash and bank-transfer requests appear here without entering the member-payment ledger.</span></div>}</section>

    <section className="panel table-scroll saas-invoices"><div className="panel-title"><div><p className="eyebrow">Recurring invoices</p><h3>Billing history</h3></div><small>Platform subscription records</small></div><table className="data-table"><thead><tr><th>Invoice</th><th>Period end</th><th>Amount</th><th>Status</th><th>Document</th></tr></thead><tbody>{data.invoices.map((invoice) => <tr key={invoice.id}><td><strong>{invoice.number ?? "Pending number"}</strong></td><td>{date(invoice.period_end)}</td><td><strong>{money(invoice.amount_due_minor, invoice.currency)}</strong></td><td><span className={`status ${invoice.status}`}><i />{readable(invoice.status)}</span></td><td>{invoice.hosted_invoice_url?.startsWith("https://") ? <a className="table-link" href={invoice.hosted_invoice_url} target="_blank" rel="noreferrer">View <ArrowUpRight size={13} /></a> : "—"}</td></tr>)}</tbody></table>{data.invoices.length === 0 && <div className="empty-state"><FileText size={24} /><strong>No recurring invoices yet</strong><span>Invoices appear after a cash, bank-transfer or Stripe subscription is approved.</span></div>}</section>
    {modal && data.onCreatePlan && <PlanModal currency={data.baseCurrency} onClose={() => setModal(false)} onCreate={data.onCreatePlan} />}
    {manualChoice && <ManualPaymentModal choice={manualChoice} onClose={() => setManualChoice(null)} onSubmit={data.onManualPayment} />}
    {reviewing && <ManualReviewModal payment={reviewing} onClose={() => setReviewing(null)} onSubmit={data.onReviewManualPayment} />}
  </section>;
}
