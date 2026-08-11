"use client";

import { FormEvent, useState } from "react";
import { Check, LoaderCircle, LockKeyhole, ShieldCheck, X } from "lucide-react";

export function AccountSecurityDialog({
  onClose,
  onChangePassword,
}: {
  onClose: () => void;
  onChangePassword: (currentPassword: string, password: string) => Promise<void>;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [complete, setComplete] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const currentPassword = String(data.get("current_password"));
    const password = String(data.get("password"));
    const confirmation = String(data.get("password_confirmation"));

    if (password !== confirmation) {
      setError("The new password confirmation does not match.");
      return;
    }

    setBusy(true);
    setError(null);
    try {
      await onChangePassword(currentPassword, password);
      form.reset();
      setComplete(true);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "Your password could not be changed.");
    } finally {
      setBusy(false);
    }
  }

  return <div className="modal-layer account-security-layer" role="dialog" aria-modal="true" aria-labelledby="account-security-title">
    <button className="modal-scrim" onClick={onClose} aria-label="Close account security" />
    <form className="modal-card account-security-card" onSubmit={submit}>
      <div className="modal-heading"><span><LockKeyhole size={20} /></span><div><p className="eyebrow">Account security</p><h2 id="account-security-title">Change your password</h2></div><button type="button" className="icon-button" onClick={onClose} aria-label="Close"><X size={18} /></button></div>
      {complete ? <div className="security-complete"><span><Check size={20} /></span><div><strong>Password changed securely</strong><p>Other signed-in sessions and device tokens have been revoked. This session remains active.</p></div></div> : <>
        {error && <div className="form-error" role="alert">{error}</div>}
        <label>Current password<input name="current_password" type="password" autoComplete="current-password" required maxLength={1024} autoFocus /></label>
        <label>New password<input name="password" type="password" autoComplete="new-password" required minLength={12} maxLength={255} /></label>
        <label>Confirm new password<input name="password_confirmation" type="password" autoComplete="new-password" required minLength={12} maxLength={255} /></label>
        <div className="modal-note"><ShieldCheck size={17} />Use at least 12 characters with upper and lower case letters, a number and a symbol.</div>
      </>}
      <div className="modal-actions"><button type="button" className="secondary-button" onClick={onClose}>{complete ? "Done" : "Cancel"}</button>{!complete && <button className="primary-button" type="submit" disabled={busy}>{busy ? <><LoaderCircle className="spin" size={16} /> Changing…</> : "Change password"}</button>}</div>
    </form>
  </div>;
}
