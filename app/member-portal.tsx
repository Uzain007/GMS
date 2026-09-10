"use client";

import QRCode from "qrcode";
import Image from "next/image";
import {
  Bell,
  CalendarDays,
  Banknote,
  ChartNoAxesCombined,
  Check,
  CircleUserRound,
  CreditCard,
  Copy,
  Dumbbell,
  Download,
  FileUp,
  Home,
  Landmark,
  LockKeyhole,
  LogOut,
  QrCode,
  RefreshCw,
  ShieldCheck,
  TicketCheck,
  UserRound,
  WalletCards,
  X,
  type LucideIcon,
} from "lucide-react";
import { type ChangeEvent, type FormEvent, useEffect, useMemo, useState } from "react";
import type {
  AttendanceRecord,
  ClassBookingRecord,
  ClassSessionRecord,
  GymSummary,
  InvoiceRecord,
  MemberSelfCredentialRecord,
  MemberSelfRecord,
  MemberPaymentOptions,
  MembershipRecord,
  NewProgressMeasurement,
  NewWorkoutSession,
  NotificationPreferenceRecord,
  PaymentRecord,
  ProgressMeasurementRecord,
  UpdateMemberSelf,
  UpdateNotificationPreference,
  WorkoutPlanRecord,
  WorkoutSessionRecord,
} from "./lib/ironcore-api";
import { AccountSecurityDialog, type MfaActions } from "./account-security";
import { formatGymDateTime, formatGymDay, formatGymMonth } from "./lib/gym-time";

type MemberView = "home" | "pass" | "classes" | "training" | "progress" | "account";

export type MemberPortalData = {
  gym: Pick<GymSummary, "id" | "name" | "base_currency" | "timezone">;
  profile: MemberSelfRecord | null;
  membership: MembershipRecord | null;
  invoices: InvoiceRecord[];
  payments: PaymentRecord[];
  paymentOptions: MemberPaymentOptions;
  attendance: AttendanceRecord[];
  classes: ClassSessionRecord[];
  bookings: ClassBookingRecord[];
  workoutPlans: WorkoutPlanRecord[];
  workoutSessions: WorkoutSessionRecord[];
  measurements: ProgressMeasurementRecord[];
  preference: NotificationPreferenceRecord | null;
  credential: MemberSelfCredentialRecord | null;
  loading: boolean;
  error: string | null;
};

export type MemberPortalActions = {
  readOnly?: boolean;
  onReload: () => void;
  onLogout: () => void;
  onChangePassword: (currentPassword: string, password: string) => Promise<void>;
  mfa?: MfaActions;
  onPortalSwitch?: () => void;
  portalSwitchLabel?: string;
  onUpdateProfile: (input: UpdateMemberSelf) => Promise<void>;
  onEnsureCredential: () => Promise<MemberSelfCredentialRecord>;
  onRotateCredential: () => Promise<MemberSelfCredentialRecord>;
  onSubmitPayment: (input: { invoice_id: string; method: "bank_transfer" | "online_card"; idempotency_key: string; bank_reference?: string; transferred_on?: string; receipt?: File }) => Promise<string | null>;
  onLoadPaymentReceipt: (paymentId: string) => Promise<Blob>;
  onBookClass: (sessionId: string) => Promise<void>;
  onCancelBooking: (bookingId: string, reason: string) => Promise<void>;
  onLogWorkout: (input: NewWorkoutSession) => Promise<void>;
  onRecordProgress: (input: NewProgressMeasurement) => Promise<void>;
  onUpdatePreferences: (input: UpdateNotificationPreference) => Promise<void>;
};

const navigation: Array<{ view: MemberView; label: string; icon: LucideIcon }> = [
  { view: "home", label: "Home", icon: Home },
  { view: "pass", label: "My pass", icon: QrCode },
  { view: "classes", label: "Classes", icon: CalendarDays },
  { view: "training", label: "Training", icon: Dumbbell },
  { view: "progress", label: "Progress", icon: ChartNoAxesCombined },
  { view: "account", label: "Account", icon: CircleUserRound },
];

function initials(profile: MemberSelfRecord | null): string {
  return `${profile?.first_name?.[0] ?? "M"}${profile?.last_name?.[0] ?? ""}`.toUpperCase();
}

function shortDate(value: string | null | undefined): string {
  if (!value) return "—";
  return new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(value));
}

function dateTime(value: string | null | undefined, timeZone: string): string {
  if (!value) return "—";
  return formatGymDateTime(value, timeZone);
}

function money(amountMinor: number, currency: GymSummary["base_currency"]): string {
  return new Intl.NumberFormat("en-GB", { style: "currency", currency }).format(amountMinor / 100);
}

function paymentRequestKey(): string {
  if (typeof globalThis.crypto?.randomUUID === "function") return globalThis.crypto.randomUUID();
  if (typeof globalThis.crypto?.getRandomValues !== "function") throw new Error("Secure payment request identifiers are unavailable in this browser.");
  const bytes = globalThis.crypto.getRandomValues(new Uint8Array(16));
  return `member-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("")}`;
}

function Brand() {
  return <div className="member-brand"><span>IC</span><strong>IRONCORE</strong></div>;
}

function Empty({ icon: Icon, title, copy }: { icon: LucideIcon; title: string; copy: string }) {
  return <div className="member-empty"><Icon size={25} /><strong>{title}</strong><span>{copy}</span></div>;
}

function MemberPaymentsCard({ data, actions }: { data: MemberPortalData; actions: MemberPortalActions }) {
  const openInvoices = data.invoices.filter((invoice) => invoice.status === "open" && invoice.due_amount_minor > 0 && invoice.membership_id);
  const [invoiceId, setInvoiceId] = useState(openInvoices[0]?.id ?? "");
  const [method, setMethod] = useState<"bank_transfer" | "online_card">("bank_transfer");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);
  const bankDetails = data.paymentOptions.bank_transfer_details;
  const firstOpenInvoiceId = openInvoices[0]?.id ?? "";
  const selectedInvoiceId = openInvoices.some((invoice) => invoice.id === invoiceId) ? invoiceId : firstOpenInvoiceId;
  const transferDetails = bankDetails?.invoices.find((invoice) => invoice.invoice_id === selectedInvoiceId) ?? null;

  async function copyValue(label: string, value: string) {
    try { await navigator.clipboard.writeText(value); setCopied(label); window.setTimeout(() => setCopied(null), 1800); }
    catch { setError("Copy was blocked by your browser. Select and copy the details manually."); }
  }

  function copyAll() {
    if (!bankDetails || !transferDetails) return;
    const lines = [
      `Account name: ${bankDetails.account_name}`,
      `Bank name: ${bankDetails.bank_name}`,
      `Account number / IBAN: ${bankDetails.account_number_or_iban}`,
      bankDetails.routing_details ? `Sort code / routing: ${bankDetails.routing_details}` : null,
      `Payment reference: ${transferDetails.payment_reference}`,
      `Amount due: ${money(transferDetails.amount_minor, transferDetails.currency)}`,
      `Currency: ${transferDetails.currency}`,
    ].filter(Boolean).join("\n");
    void copyValue("all", lines);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(null); setNotice(null);
    try {
      const form = new FormData(event.currentTarget);
      const receipt = form.get("receipt");
      const checkout = await actions.onSubmitPayment({
        invoice_id: selectedInvoiceId,
        method,
        idempotency_key: paymentRequestKey(),
        bank_reference: String(form.get("bank_reference")) || undefined,
        transferred_on: String(form.get("transferred_on")) || undefined,
        receipt: receipt instanceof File && receipt.size > 0 ? receipt : undefined,
      });
      if (checkout) window.location.assign(checkout);
      else setNotice("Receipt submitted. Payment status is now Pending Verification.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The payment could not be submitted.");
    } finally { setBusy(false); }
  }

  async function download(payment: PaymentRecord) {
    setError(null);
    try {
      const blob = await actions.onLoadPaymentReceipt(payment.id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url; anchor.download = payment.bank_transfer_receipt?.original_name ?? "bank-transfer-receipt"; anchor.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
    } catch (reason) { setError(reason instanceof Error ? reason.message : "The receipt could not be downloaded."); }
  }

  return <article className="member-card member-payments-card"><div className="member-card-title"><div><small>Payments</small><h3>Membership payments</h3></div><CreditCard /></div>{!actions.readOnly && openInvoices.length > 0 && <form className="member-payment-form" onSubmit={submit}><label>Invoice<select value={invoiceId} onChange={(event) => setInvoiceId(event.target.value)} required>{openInvoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.number} · {money(invoice.due_amount_minor, invoice.currency)}</option>)}</select></label><div className="member-payment-methods"><button type="button" className={method === "bank_transfer" ? "active" : ""} disabled={!data.paymentOptions.bank_transfer_available} onClick={() => setMethod("bank_transfer")}><Banknote size={16} /> Pay by Bank Transfer</button><button type="button" className={method === "online_card" ? "active" : ""} disabled={!data.paymentOptions.stripe_available} onClick={() => setMethod("online_card")}><CreditCard size={16} /> Stripe {data.paymentOptions.stripe_available ? "" : "· Not configured"}</button></div>{method === "bank_transfer" ? data.paymentOptions.bank_transfer_available && bankDetails && transferDetails ? <><section className="member-bank-details" aria-label="Bank transfer payment details"><div className="member-bank-heading"><span><Landmark size={18} /><strong>Transfer details</strong></span><button type="button" onClick={copyAll}><Copy size={15} /> {copied === "all" ? "Copied" : "Copy all payment details"}</button></div>{[
    ["Account name", bankDetails.account_name], ["Bank name", bankDetails.bank_name], ["Account number / IBAN", bankDetails.account_number_or_iban], ["Sort code / routing", bankDetails.routing_details], ["Payment reference", transferDetails.payment_reference], ["Amount due", money(transferDetails.amount_minor, transferDetails.currency)], ["Currency", transferDetails.currency],
  ].filter((item): item is [string, string] => Boolean(item[1])).map(([label, value]) => <div className="member-bank-field" key={label}><span><small>{label}</small><strong>{value}</strong></span><button type="button" aria-label={`Copy ${label}`} onClick={() => void copyValue(label, value)}>{copied === label ? <Check size={15} /> : <Copy size={15} />}<span>{copied === label ? "Copied" : "Copy"}</span></button></div>)}{bankDetails.payment_instructions && <p>{bankDetails.payment_instructions}</p>}</section><label>Payment reference<input name="bank_reference" key={transferDetails.payment_reference} maxLength={160} defaultValue={transferDetails.payment_reference} /></label><label>Transfer date<input name="transferred_on" type="date" max={new Date().toISOString().slice(0, 10)} required /></label><label>Payment receipt<input name="receipt" type="file" accept="application/pdf,image/jpeg,image/png,image/webp" required /><small>Private PDF or image, maximum 10 MB.</small></label><button className="member-primary" disabled={busy}><FileUp size={16} /> {busy ? "Submitting…" : "Submit for verification"}</button></> : <div className="member-payment-unavailable"><Banknote size={15} /><span><strong>Bank transfer · Not configured</strong><small>Ask your gym or pay cash at reception.</small></span></div> : <><div className="member-payment-safe"><ShieldCheck size={16} /><span>Card details are entered only on Stripe’s hosted checkout.</span></div><button className="member-primary" disabled={busy || !data.paymentOptions.stripe_available}>{busy ? "Opening…" : "Pay securely with Stripe"}</button></>}{error && <div className="member-inline-error" role="alert">{error}</div>}{notice && <div className="member-inline-notice" role="status">{notice}</div>}</form>}{!actions.readOnly && openInvoices.length === 0 && <p className="member-payment-copy">You have no open membership invoice to pay. Cash can be recorded safely by reception.</p>}{!data.paymentOptions.stripe_configured && <div className="member-payment-unavailable"><CreditCard size={15} /><span><strong>Stripe · Not configured</strong><small>Configured bank transfer and cash at reception remain available.</small></span></div>}<div className="member-payment-list">{data.payments.slice(0, 6).map((payment) => <div key={payment.id}><span><strong>{payment.receipt_number}</strong><small>{payment.method.replaceAll("_", " ")} · {payment.method === "bank_transfer" && payment.status === "pending" ? "Pending Verification" : payment.status.replaceAll("_", " ")}</small>{payment.bank_transfer_receipt?.review_reason && <small>{payment.bank_transfer_receipt.review_reason}{payment.status === "rejected" ? " You may submit new proof for the open invoice." : ""}</small>}</span><span className="member-payment-amount"><b>{money(payment.amount_minor, payment.currency)}</b>{payment.bank_transfer_receipt && <button onClick={() => void download(payment)} aria-label={`Download receipt ${payment.receipt_number}`}><Download size={14} /></button>}</span></div>)}{data.payments.length === 0 && <Empty icon={WalletCards} title="No payments" copy="Payments and pending bank receipts will appear here." />}</div></article>;
}

export function MemberPortal({ data, actions }: { data: MemberPortalData; actions: MemberPortalActions }) {
  const [view, setView] = useState<MemberView>("home");
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [renderedCredential, setRenderedCredential] = useState<string | null>(null);
  const [qrRender, setQrRender] = useState<{ credential: string; attempt: number; imageUrl: string | null; error: string | null } | null>(null);
  const [qrRenderAttempt, setQrRenderAttempt] = useState(0);
  const [profileOpen, setProfileOpen] = useState(false);
  const [securityOpen, setSecurityOpen] = useState(false);
  const qrPlaintext = renderedCredential ?? data.credential?.credential ?? null;
  const membershipAccessReady = data.profile?.status === "active"
    && data.membership?.status === "active"
    && data.membership?.is_in_date === true;
  const displayCredential = membershipAccessReady ? qrPlaintext : null;
  const currentQrRender = qrRender?.credential === displayCredential && qrRender.attempt === qrRenderAttempt ? qrRender : null;
  const qrImageUrl = currentQrRender?.imageUrl ?? null;
  const qrRenderError = currentQrRender?.error ?? null;

  function navigate(nextView: MemberView) {
    setNotice(null);
    setActionError(null);
    setView(nextView);
  }

  useEffect(() => {
    let current = true;
    if (!displayCredential) return () => { current = false; };

    // Generate independently of page visibility so opening "My pass" after
    // initial data load cannot leave an undrawn canvas in the pass card.
    void QRCode.toDataURL(displayCredential, {
      width: 256,
      margin: 2,
      errorCorrectionLevel: "M",
      color: { dark: "#25183f", light: "#ffffff" },
    }).then((url) => {
      if (current) setQrRender({ credential: displayCredential, attempt: qrRenderAttempt, imageUrl: url, error: null });
    }).catch(() => {
      if (current) setQrRender({ credential: displayCredential, attempt: qrRenderAttempt, imageUrl: null, error: "Your secure QR could not be displayed. Try again or use your Member Code at reception." });
    });
    return () => { current = false; };
  }, [displayCredential, qrRenderAttempt]);

  const bookingBySession = useMemo(() => {
    const map = new Map<string, ClassBookingRecord>();
    data.bookings.filter((booking) => booking.status !== "cancelled").forEach((booking) => map.set(booking.class_session_id, booking));
    return map;
  }, [data.bookings]);
  const activePlan = data.workoutPlans.find((plan) => plan.status === "active") ?? data.workoutPlans[0] ?? null;
  const nextClass = data.classes.find((session) => session.status === "scheduled") ?? data.classes[0] ?? null;
  const lastVisit = data.attendance[0] ?? null;
  const latestMeasurement = data.measurements[0] ?? null;

  async function act(key: string, success: string, task: () => Promise<void>): Promise<boolean> {
    setBusy(key); setNotice(null); setActionError(null);
    try { await task(); setNotice(success); return true; }
    catch (error) { setActionError(error instanceof Error ? error.message : "That action could not be completed."); return false; }
    finally { setBusy(null); }
  }

  async function rotatePass() {
    setRenderedCredential(null);
    await act("pass", "Your replacement QR pass is ready and will remain available after reload.", async () => {
      const result = await actions.onRotateCredential();
      if (!result.credential) throw new Error("The replacement pass was not returned.");
      setRenderedCredential(result.credential);
    });
  }

  async function ensurePass() {
    await act("pass", "Your QR pass is ready.", async () => {
      const result = await actions.onEnsureCredential();
      if (!result.credential) throw new Error("The gym pass was not returned.");
      setRenderedCredential(result.credential);
    });
  }

  async function submitProfile(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    await act("profile", "Your profile has been updated.", async () => {
      await actions.onUpdateProfile({
        first_name: String(form.get("first_name") ?? ""),
        last_name: String(form.get("last_name") ?? ""),
        email: String(form.get("email") ?? ""),
        phone: String(form.get("phone") ?? ""),
        date_of_birth: String(form.get("date_of_birth") ?? "") || null,
      });
      setProfileOpen(false);
    });
  }

  async function submitProgress(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    const value = Number(form.get("value"));
    const saved = await act("progress", "Progress recorded.", async () => actions.onRecordProgress({
      metric: String(form.get("metric")) as NewProgressMeasurement["metric"],
      value_milli: Math.round(value * 1000),
      unit: String(form.get("unit")) as NewProgressMeasurement["unit"],
      measured_at: new Date().toISOString(),
      note: String(form.get("note") ?? "") || undefined,
    }));
    if (saved) formElement.reset();
  }

  async function submitWorkout(event: FormEvent<HTMLFormElement>, plan: WorkoutPlanRecord) {
    event.preventDefault();
    const formElement = event.currentTarget;
    const form = new FormData(formElement);
    const saved = await act("workout", "Workout logged.", async () => actions.onLogWorkout({
      workout_plan_id: plan.id,
      performed_at: new Date().toISOString(),
      duration_seconds: Number(form.get("minutes")) * 60,
      notes: String(form.get("notes") ?? "") || undefined,
      sets: plan.exercises.map((exercise) => ({ workout_plan_exercise_id: exercise.id, set_number: 1, reps: exercise.target_reps_min ?? undefined })),
    }));
    if (saved) formElement.reset();
  }

  function updatePreference(event: ChangeEvent<HTMLInputElement>) {
    void act("preferences", "Preference updated.", () => actions.onUpdatePreferences({ [event.target.name]: event.target.checked }));
  }

  const nav = <nav>{navigation.map(({ view: target, label, icon: Icon }) => <button key={target} className={view === target ? "active" : ""} onClick={() => navigate(target)}><Icon size={19} /><span>{label}</span></button>)}</nav>;

  return <main className="member-shell">
    <aside className="member-sidebar"><Brand />{nav}<div className="member-sidebar-foot"><button onClick={actions.onLogout}><LogOut size={18} /> Sign out</button><small>Tenant-isolated member access</small></div></aside>
    <header className="member-mobile-top"><Brand /><button aria-label="Account" onClick={() => navigate("account")}><span>{initials(data.profile)}</span></button></header>
    <section className="member-main">
      <header className="member-page-top"><div><p>{data.gym.name}</p><h1>{navigation.find((item) => item.view === view)?.label}</h1></div><div className="member-top-actions">{actions.onPortalSwitch && <button className="member-switch" onClick={actions.onPortalSwitch}>{actions.portalSwitchLabel ?? "Switch portal"}</button>}{!actions.readOnly && <button className="member-icon-button" aria-label="Reload" onClick={actions.onReload}><RefreshCw size={17} /></button>}<button className="member-avatar" onClick={() => navigate("account")} aria-label="Open member profile and settings" title="Profile and settings">{initials(data.profile)}</button></div></header>
      {data.error && <div className="member-alert error" role="alert">{data.error}<button onClick={actions.onReload}>Try again</button></div>}
      {actionError && <div className="member-alert error" role="alert">{actionError}<button onClick={() => setActionError(null)}><X size={15} /></button></div>}
      {notice && <div className="member-alert success"><Check size={16} />{notice}<button onClick={() => setNotice(null)}><X size={15} /></button></div>}
      {data.loading && !data.profile ? <div className="member-loading"><RefreshCw className="spin" size={24} /> Preparing your member space…</div> : <div className="member-view">
        {view === "home" && <>
          <section className="member-hero"><div><span className="member-kicker">Welcome back</span><h2>{data.profile?.first_name ?? "Member"}, you’re all set.</h2><p>Your membership, classes and training plan are together in one private space.</p><div><button className="member-primary" disabled={!membershipAccessReady} onClick={() => navigate("pass")}><QrCode size={18} /> {membershipAccessReady ? "Open gym pass" : "Membership required"}</button><button className="member-secondary" onClick={() => navigate("classes")}><CalendarDays size={18} /> Find a class</button></div></div><Dumbbell size={94} /></section>
          <section className="member-stat-grid">
            <article><TicketCheck /><span><small>Membership</small><strong>{data.membership?.plan?.name ?? "No active plan"}</strong><em className={membershipAccessReady ? "good" : ""}>{membershipAccessReady ? "active and in date" : data.membership?.status ?? "unavailable"}</em></span></article>
            <article><CalendarDays /><span><small>Next class</small><strong>{nextClass?.title ?? "Nothing booked"}</strong><em>{nextClass ? dateTime(nextClass.starts_at, data.gym.timezone) : "Browse the schedule"}</em></span></article>
            <article><ShieldCheck /><span><small>Last visit</small><strong>{lastVisit ? shortDate(lastVisit.checked_in_at) : "No visits yet"}</strong><em>{lastVisit?.branch?.name ?? data.gym.name}</em></span></article>
            <article><ChartNoAxesCombined /><span><small>Latest progress</small><strong>{latestMeasurement ? `${latestMeasurement.value_milli / 1000} ${latestMeasurement.unit}` : "Start tracking"}</strong><em>{latestMeasurement ? shortDate(latestMeasurement.measured_at) : "Record a measurement"}</em></span></article>
          </section>
          <section className="member-two-col"><article className="member-card"><div className="member-card-title"><div><small>Up next</small><h3>Your schedule</h3></div><CalendarDays /></div>{nextClass ? <div className="member-feature-row"><span className="member-date-tile"><b>{formatGymDay(nextClass.starts_at, data.gym.timezone)}</b><small>{formatGymMonth(nextClass.starts_at, data.gym.timezone)}</small></span><div><strong>{nextClass.title}</strong><small>{dateTime(nextClass.starts_at, data.gym.timezone)} · {nextClass.branch?.name ?? "Main gym"}</small></div><button onClick={() => navigate("classes")}>View</button></div> : <Empty icon={CalendarDays} title="Your schedule is clear" copy="Browse classes and reserve a place." />}</article><article className="member-card"><div className="member-card-title"><div><small>Plan</small><h3>Training focus</h3></div><Dumbbell /></div>{activePlan ? <><h4>{activePlan.title}</h4><p>{activePlan.goal ?? "Your coach has prepared this programme for you."}</p><button className="member-text-button" onClick={() => navigate("training")}>View {activePlan.exercises.length} exercises</button></> : <Empty icon={Dumbbell} title="No training plan yet" copy="Your assigned coach can publish one here." />}</article></section>
        </>}

        {view === "pass" && <section className="member-pass-layout"><article className="member-pass-card"><div className="member-pass-head"><Brand /><span className={membershipAccessReady ? "active" : ""}>{membershipAccessReady ? "Access ready" : "Access unavailable"}</span></div><div className="member-pass-person"><span>{initials(data.profile)}</span><div><small>Member</small><h2>{data.profile?.first_name} {data.profile?.last_name}</h2><p>Member Code {data.profile?.member_code ?? "——"}</p></div></div><div className="member-qr-zone">{displayCredential ? <>{qrImageUrl ? <Image className="member-qr-image" src={qrImageUrl} alt="Persistent gym access QR code" width={256} height={256} draggable={false} unoptimized /> : qrRenderError ? <div className="member-qr-error" role="alert"><QrCode size={52} /><strong>QR could not load</strong><small>{qrRenderError}</small><button className="member-secondary" type="button" onClick={() => setQrRenderAttempt((attempt) => attempt + 1)}><RefreshCw size={17} /> Retry QR</button></div> : <div className="member-qr-loading" role="status"><RefreshCw className="spin" size={36} /><strong>Loading secure QR…</strong></div>}<div className="member-code-display"><small>Member Code</small><strong>{data.profile?.member_code ?? "——"}</strong></div><strong>Your secure gym pass</strong><small>This QR remains available after reload and sign-in. It is the same secure credential verified by reception&apos;s camera scanner.</small></> : <><QrCode size={62} /><div className="member-code-display"><small>Member Code</small><strong>{data.profile?.member_code ?? "——"}</strong></div><strong>{membershipAccessReady ? "Create your gym pass" : "An active membership is required"}</strong><small>{membershipAccessReady ? "Only tenant-scoped SHA-256 evidence is stored; bearer plaintext is reconstructed only for you." : "Contact your gym if your membership should be active."}</small></>}{!actions.readOnly && (displayCredential ? <button className="member-secondary" disabled={busy === "pass"} onClick={() => void rotatePass()}><RefreshCw size={17} /> Replace pass</button> : membershipAccessReady && !qrPlaintext ? <button className="member-primary" disabled={busy === "pass"} onClick={() => void ensurePass()}><QrCode size={17} /> Create pass</button> : null)}</div><div className="member-pass-foot"><span><ShieldCheck size={16} /> {data.gym.name}</span><span>Valid membership required</span></div></article><aside className="member-card member-pass-help"><small>How it works</small><h3>Private by design</h3><ol><li><span>1</span><div><strong>Open your pass</strong><small>Your normal QR is available after secure sign-in.</small></div></li><li><span>2</span><div><strong>Show reception</strong><small>Staff scan the opaque QR, not your visible Member Code.</small></div></li><li><span>3</span><div><strong>Verified by IronCore</strong><small>The backend checks gym, membership, branch and duplicate status.</small></div></li></ol></aside></section>}

        {view === "classes" && <section className="member-card-grid">{data.classes.map((session) => { const booking = bookingBySession.get(session.id); const full = session.booked_count >= session.capacity; return <article className="member-class-card" key={session.id}><div className="member-class-art"><CalendarDays size={32} /><span>{session.branch?.name ?? "Main gym"}</span></div><div className="member-class-body"><small>{dateTime(session.starts_at, data.gym.timezone)}</small><h3>{session.title}</h3><p>{session.description ?? "Instructor-led session at your gym."}</p><div><span><UserRound size={14} /> {session.trainer?.name ?? "Gym team"}</span><span>{session.booked_count}/{session.capacity} places</span></div>{!actions.readOnly && (booking ? <button className="member-secondary" disabled={busy === booking.id} onClick={() => void act(booking.id, "Booking cancelled.", () => actions.onCancelBooking(booking.id, "Cancelled by member"))}>{booking.status === "waitlisted" ? "Leave waitlist" : "Cancel booking"}</button> : <button className="member-primary" disabled={busy === session.id || session.status !== "scheduled"} onClick={() => void act(session.id, full ? "You joined the waitlist." : "Class booked.", () => actions.onBookClass(session.id))}>{full && session.waitlist_enabled ? "Join waitlist" : "Book class"}</button>)}</div></article>; })}{data.classes.length === 0 && <Empty icon={CalendarDays} title="No classes available" copy="Your gym has not published an upcoming schedule." />}</section>}

        {view === "training" && <section className="member-training-layout">{data.workoutPlans.map((plan) => <article className="member-card member-plan" key={plan.id}><div className="member-card-title"><div><small>{plan.status} plan</small><h3>{plan.title}</h3></div><Dumbbell /></div><p>{plan.goal ?? "A programme prepared by your coach."}</p><div className="member-exercises">{plan.exercises.map((exercise, index) => <div key={exercise.id}><span>{index + 1}</span><div><strong>{exercise.name}</strong><small>{exercise.target_sets ?? "—"} sets · {exercise.target_reps_min ?? "—"}{exercise.target_reps_max ? `–${exercise.target_reps_max}` : ""} reps</small></div></div>)}</div>{!actions.readOnly && <form onSubmit={(event) => void submitWorkout(event, plan)}><label>Minutes<input name="minutes" type="number" min="1" max="600" required placeholder="45" /></label><label>Session notes<input name="notes" placeholder="Felt strong today" /></label><button className="member-primary" disabled={busy === "workout"}>Log workout</button></form>}</article>)}{data.workoutPlans.length === 0 && <Empty icon={Dumbbell} title="No workout plan" copy="Your coach can assign a plan that will appear here." />}</section>}

        {view === "progress" && <section className="member-progress-layout"><article className="member-card"><div className="member-card-title"><div><small>History</small><h3>Your measurements</h3></div><ChartNoAxesCombined /></div><div className="member-measurements">{data.measurements.map((row) => <div key={row.id}><span><strong>{row.metric.replaceAll("_", " ")}</strong><small>{shortDate(row.measured_at)}</small></span><b>{row.value_milli / 1000} {row.unit}</b></div>)}{data.measurements.length === 0 && <Empty icon={ChartNoAxesCombined} title="No progress recorded" copy="Add your first measurement to begin." />}</div></article>{!actions.readOnly && <article className="member-card member-progress-form"><small>New entry</small><h3>Record progress</h3><form onSubmit={(event) => void submitProgress(event)}><label>Metric<select name="metric" defaultValue="body_weight"><option value="body_weight">Body weight</option><option value="body_fat">Body fat</option><option value="waist">Waist</option><option value="chest">Chest</option></select></label><div><label>Value<input name="value" type="number" step="0.001" min="0.001" required /></label><label>Unit<select name="unit" defaultValue="kg"><option value="kg">kg</option><option value="percent">percent</option><option value="cm">cm</option></select></label></div><label>Note<input name="note" placeholder="Optional note" /></label><button className="member-primary" disabled={busy === "progress"}>Save measurement</button></form></article>}</section>}

        {view === "account" && <section className="member-account-layout"><article className="member-card member-profile-card"><span className="member-large-avatar">{initials(data.profile)}</span><h2>{data.profile?.first_name} {data.profile?.last_name}</h2><div className="member-code-display member-profile-code"><small>Member Code</small><strong>{data.profile?.member_code ?? "——"}</strong></div>{!actions.readOnly && <button className="member-primary" onClick={() => setProfileOpen(true)}>Edit profile</button>}</article><div className="member-account-stack"><article className="member-card"><div className="member-card-title"><div><small>Membership</small><h3>{data.membership?.plan?.name ?? "No active plan"}</h3></div><TicketCheck /></div><dl><div><dt>Status</dt><dd>{membershipAccessReady ? "Active · access ready" : data.membership?.status ?? "—"}</dd></div><div><dt>Started</dt><dd>{shortDate(data.membership?.starts_at)}</dd></div><div><dt>Expires</dt><dd>{data.membership?.ends_at ? shortDate(data.membership.ends_at) : "No fixed expiry"}</dd></div><div><dt>Next billing</dt><dd>{shortDate(data.membership?.next_billing_at)}</dd></div><div><dt>Amount</dt><dd>{data.membership ? money(data.membership.price_amount_minor, data.membership.currency) : "—"}</dd></div></dl></article>{!actions.readOnly && <article className="member-card member-security-card"><div className="member-card-title"><div><small>Account security</small><h3>Password and authenticator</h3></div><LockKeyhole /></div><p>Manage your password, multi-factor authentication, recovery codes and signed-in sessions.</p><button className="member-secondary" onClick={() => setSecurityOpen(true)}>Manage security</button></article>}<MemberPaymentsCard data={data} actions={actions} /><article className="member-card member-preferences"><div className="member-card-title"><div><small>Notifications</small><h3>Reminders</h3></div><Bell /></div>{data.preference ? <form><label><span>Class reminders</span><input name="class_reminders_enabled" type="checkbox" disabled={actions.readOnly} defaultChecked={data.preference.class_reminders_enabled} onChange={updatePreference} /></label><label><span>Workout reminders</span><input name="workout_reminders_enabled" type="checkbox" disabled={actions.readOnly} defaultChecked={data.preference.workout_reminders_enabled} onChange={updatePreference} /></label><label><span>Payment reminders</span><input name="payment_reminders_enabled" type="checkbox" disabled={actions.readOnly} defaultChecked={data.preference.payment_reminders_enabled} onChange={updatePreference} /></label></form> : <p>Notification preferences are not available.</p>}</article><button className="member-signout" onClick={actions.onLogout}><LogOut size={17} /> Sign out securely</button></div></section>}
      </div>}
    </section>
    <footer className="member-mobile-nav">{nav}</footer>
    {profileOpen && <div className="member-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setProfileOpen(false); }}><section className="member-modal" role="dialog" aria-modal="true" aria-label="Edit profile"><header><div><small>Member profile</small><h2>Edit your details</h2></div><button onClick={() => setProfileOpen(false)} aria-label="Close profile editor"><X size={18} /></button></header><p>You can update contact and personal details only. Membership status and tenant links remain gym-managed.</p><form onSubmit={(event) => void submitProfile(event)}><div><label>First name<input name="first_name" defaultValue={data.profile?.first_name} required /></label><label>Last name<input name="last_name" defaultValue={data.profile?.last_name} required /></label></div><label>Email<input name="email" type="email" defaultValue={data.profile?.email ?? ""} required /></label><label>Phone<input name="phone" defaultValue={data.profile?.phone ?? ""} required /></label><label>Date of birth<input name="date_of_birth" type="date" defaultValue={data.profile?.date_of_birth ?? ""} /></label><footer><button type="button" className="member-secondary" onClick={() => setProfileOpen(false)}>Cancel</button><button className="member-primary" disabled={busy === "profile"}>Save changes</button></footer></form></section></div>}
    {securityOpen && <AccountSecurityDialog onClose={() => setSecurityOpen(false)} onChangePassword={actions.onChangePassword} mfa={actions.mfa} />}
  </main>;
}
