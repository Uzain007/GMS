"use client";

import QRCode from "qrcode";
import { FormEvent, useEffect, useRef, useState } from "react";
import { ArrowLeft, Check, Clipboard, KeyRound, LoaderCircle, LockKeyhole, RefreshCw, ShieldCheck, ShieldOff, Smartphone, X } from "lucide-react";
import type { MfaRecoveryCodes, MfaSetup, MfaStatus } from "./lib/ironcore-api";

export type MfaActions = {
  status: () => Promise<MfaStatus>;
  beginSetup: (currentPassword: string) => Promise<MfaSetup>;
  confirmSetup: (code: string) => Promise<MfaRecoveryCodes & { enabled: true }>;
  regenerateRecoveryCodes: (currentPassword: string, code: string) => Promise<MfaRecoveryCodes>;
  disable: (currentPassword: string, value: string, recovery: boolean) => Promise<void>;
};

type SecurityView = "home" | "password" | "setup" | "recovery" | "disable" | "codes";

export function AccountSecurityDialog({
  onClose,
  onChangePassword,
  mfa,
}: {
  onClose: () => void;
  onChangePassword: (currentPassword: string, password: string) => Promise<void>;
  mfa?: MfaActions;
}) {
  const [view, setView] = useState<SecurityView>("home");
  const [status, setStatus] = useState<MfaStatus | null>(null);
  const [setup, setSetup] = useState<MfaSetup | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [useRecovery, setUseRecovery] = useState(false);
  const qrCanvas = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    if (!mfa) return;
    let active = true;
    void mfa.status()
      .then((result) => { if (active) setStatus(result); })
      .catch((reason) => { if (active) setError(reason instanceof Error ? reason.message : "Security status could not be loaded."); });
    return () => { active = false; };
  }, [mfa]);

  useEffect(() => {
    if (!setup || !qrCanvas.current) return;
    void QRCode.toCanvas(qrCanvas.current, setup.otpauth_uri, { width: 180, margin: 1, color: { dark: "#171026", light: "#ffffff" } });
  }, [setup]);

  function go(next: SecurityView) {
    setView(next);
    setError(null);
    setNotice(null);
    setUseRecovery(false);
  }

  async function submitPassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const currentPassword = String(data.get("current_password"));
    const password = String(data.get("password"));
    if (password !== String(data.get("password_confirmation"))) {
      setError("The new password confirmation does not match.");
      return;
    }

    setBusy(true); setError(null);
    try {
      await onChangePassword(currentPassword, password);
      form.reset();
      setNotice("Password changed. Other signed-in sessions and device tokens were revoked.");
      setView("home");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Your password could not be changed.");
    } finally { setBusy(false); }
  }

  async function beginSetup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!mfa) return;
    setBusy(true); setError(null);
    try {
      const result = await mfa.beginSetup(String(new FormData(event.currentTarget).get("current_password")));
      setSetup(result);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Authenticator setup could not start.");
    } finally { setBusy(false); }
  }

  async function confirmSetup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!mfa) return;
    setBusy(true); setError(null);
    try {
      const result = await mfa.confirmSetup(String(new FormData(event.currentTarget).get("code")));
      setRecoveryCodes(result.recovery_codes);
      setStatus({ enabled: true, setup_pending: false, confirmed_at: new Date().toISOString(), recovery_codes_remaining: result.recovery_codes_remaining });
      setSetup(null);
      setView("codes");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "The authenticator code could not be confirmed.");
    } finally { setBusy(false); }
  }

  async function regenerateCodes(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!mfa) return;
    const data = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    try {
      const result = await mfa.regenerateRecoveryCodes(String(data.get("current_password")), String(data.get("code")));
      setRecoveryCodes(result.recovery_codes);
      setStatus((current) => current ? { ...current, recovery_codes_remaining: result.recovery_codes_remaining } : current);
      setView("codes");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Recovery codes could not be replaced.");
    } finally { setBusy(false); }
  }

  async function disableMfa(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!mfa) return;
    const data = new FormData(event.currentTarget);
    setBusy(true); setError(null);
    try {
      await mfa.disable(String(data.get("current_password")), String(data.get("verification")), useRecovery);
      setStatus({ enabled: false, setup_pending: false, confirmed_at: null, recovery_codes_remaining: 0 });
      setRecoveryCodes([]);
      setNotice("Multi-factor authentication disabled. Other signed-in sessions were revoked.");
      setView("home");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Multi-factor authentication could not be disabled.");
    } finally { setBusy(false); }
  }

  async function copyCodes() {
    try {
      await navigator.clipboard.writeText(recoveryCodes.join("\n"));
      setCopied(true);
    } catch {
      setError("Your browser blocked clipboard access. Copy the codes manually before closing this window.");
    }
  }

  const heading = view === "home" ? "Protect your account" : view === "password" ? "Change your password" : view === "setup" ? "Set up an authenticator" : view === "recovery" ? "Replace recovery codes" : view === "disable" ? "Disable multi-factor authentication" : "Save your recovery codes";

  return <div className="modal-layer account-security-layer" role="dialog" aria-modal="true" aria-labelledby="account-security-title">
    <button className="modal-scrim" onClick={onClose} aria-label="Close account security" />
    <section className="modal-card account-security-card">
      <div className="modal-heading">
        <span><LockKeyhole size={20} /></span>
        <div><p className="eyebrow">Account security</p><h2 id="account-security-title">{heading}</h2></div>
        <button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X size={18} /></button>
      </div>

      {error && <div className="form-error" role="alert">{error}</div>}
      {notice && <div className="security-complete" role="status"><span><Check size={20} /></span><div><strong>Security updated</strong><p>{notice}</p></div></div>}

      {view === "home" && <div className="security-choice-list">
        <button type="button" onClick={() => go("password")}><span><KeyRound size={19} /></span><div><strong>Password</strong><small>Replace your password and revoke other credentials.</small></div><span>Change</span></button>
        {mfa && <button type="button" disabled={status === null} onClick={() => go(status?.enabled ? "recovery" : "setup")}><span><Smartphone size={19} /></span><div><strong>Authenticator app</strong><small>{status?.enabled ? `Enabled · ${status.recovery_codes_remaining} recovery codes remain` : status === null ? "Checking status…" : "Add a code from your authenticator when signing in."}</small></div><span>{status?.enabled ? "Manage" : "Set up"}</span></button>}
        {mfa && status?.enabled && <button className="security-danger-choice" type="button" onClick={() => go("disable")}><span><ShieldOff size={19} /></span><div><strong>Disable MFA</strong><small>Password and second-factor verification are both required.</small></div><span>Disable</span></button>}
      </div>}

      {view === "password" && <form className="security-form" onSubmit={submitPassword}>
        <label>Current password<input name="current_password" type="password" autoComplete="current-password" required maxLength={1024} autoFocus /></label>
        <label>New password<input name="password" type="password" autoComplete="new-password" required minLength={12} maxLength={255} /></label>
        <label>Confirm new password<input name="password_confirmation" type="password" autoComplete="new-password" required minLength={12} maxLength={255} /></label>
        <div className="modal-note"><ShieldCheck size={17} />Use at least 12 characters with upper and lower case letters, a number and a symbol.</div>
        <SecurityActions busy={busy} onBack={() => go("home")} label="Change password" />
      </form>}

      {view === "setup" && !setup && <form className="security-form" onSubmit={beginSetup}>
        <p>Confirm your password before IronCore creates a new 160-bit authenticator secret.</p>
        <label>Current password<input name="current_password" type="password" autoComplete="current-password" required maxLength={1024} autoFocus /></label>
        <div className="modal-note"><ShieldCheck size={17} />The secret will be shown once and encrypted before it is stored.</div>
        <SecurityActions busy={busy} onBack={() => go("home")} label="Create setup code" />
      </form>}

      {view === "setup" && setup && <div className="mfa-enrollment">
        <div className="mfa-qr"><canvas ref={qrCanvas} aria-label="Authenticator enrollment QR code" /></div>
        <p>Scan with your authenticator app, or enter this secret manually:</p>
        <code>{setup.secret}</code>
        <form className="security-form" onSubmit={confirmSetup}>
          <label>6-digit code<input name="code" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" minLength={6} maxLength={6} required autoFocus /></label>
          <SecurityActions busy={busy} onBack={() => { setSetup(null); go("home"); }} label="Enable MFA" />
        </form>
      </div>}

      {view === "recovery" && <form className="security-form" onSubmit={regenerateCodes}>
        <p>Replacing codes invalidates every unused recovery code immediately.</p>
        <label>Current password<input name="current_password" type="password" autoComplete="current-password" required maxLength={1024} autoFocus /></label>
        <label>Fresh 6-digit code<input name="code" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" minLength={6} maxLength={6} required /></label>
        <SecurityActions busy={busy} onBack={() => go("home")} label="Replace codes" />
      </form>}

      {view === "disable" && <form className="security-form" onSubmit={disableMfa}>
        <p>Disabling MFA signs out every other browser and device token.</p>
        <label>Current password<input name="current_password" type="password" autoComplete="current-password" required maxLength={1024} autoFocus /></label>
        <label>{useRecovery ? "Recovery code" : "6-digit code"}<input name="verification" inputMode={useRecovery ? "text" : "numeric"} autoComplete="one-time-code" pattern={useRecovery ? undefined : "[0-9]{6}"} minLength={useRecovery ? 16 : 6} maxLength={useRecovery ? 64 : 6} required /></label>
        <button className="security-text-button" type="button" onClick={() => setUseRecovery((current) => !current)}>Use {useRecovery ? "authenticator code" : "a recovery code"}</button>
        <SecurityActions busy={busy} onBack={() => go("home")} label="Disable MFA" danger />
      </form>}

      {view === "codes" && <div className="mfa-recovery-result">
        <div className="security-complete"><span><Check size={20} /></span><div><strong>Recovery codes created</strong><p>Each code works once. Save them now; IronCore cannot show them again.</p></div></div>
        <div className="mfa-code-grid">{recoveryCodes.map((code) => <code key={code}>{code}</code>)}</div>
        <div className="modal-note"><ShieldCheck size={17} />Do not save these codes in IronCore or browser storage.</div>
        <div className="modal-actions"><button type="button" className="secondary-button" onClick={() => void copyCodes()}>{copied ? <><Check size={16} /> Copied</> : <><Clipboard size={16} /> Copy codes</>}</button><button type="button" className="primary-button" onClick={() => go("home")}>I saved them</button></div>
      </div>}
    </section>
  </div>;
}

function SecurityActions({ busy, onBack, label, danger = false }: { busy: boolean; onBack: () => void; label: string; danger?: boolean }) {
  return <div className="modal-actions"><button type="button" className="secondary-button" onClick={onBack}><ArrowLeft size={16} /> Back</button><button className={danger ? "danger-button" : "primary-button"} type="submit" disabled={busy}>{busy ? <><LoaderCircle className="spin" size={16} /> Verifying…</> : danger ? <><ShieldOff size={16} /> {label}</> : <><RefreshCw size={16} /> {label}</>}</button></div>;
}
