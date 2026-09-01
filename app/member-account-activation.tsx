"use client";

import { ArrowRight, Building2, CheckCircle2, Eye, EyeOff, LoaderCircle, LockKeyhole, ShieldCheck } from "lucide-react";
import { FormEvent, useEffect, useState } from "react";
import type { MemberAccountActivationPreview } from "./lib/ironcore-api";

export type MemberActivationSecret = { gymId: string; token: string };

type ActivationProps = {
  invitation: MemberActivationSecret;
  onPreview: (gymId: string, token: string) => Promise<MemberAccountActivationPreview>;
  onAccept: (gymId: string, token: string, password?: string) => Promise<void>;
  onCancel: () => void;
};

function ActivationBrand() {
  return <div className="auth-brand"><span><i /><strong>IC</strong></span><b>IRONCORE</b></div>;
}

export function MemberAccountActivation({ invitation, onPreview, onAccept, onCancel }: ActivationProps) {
  const [preview, setPreview] = useState<MemberAccountActivationPreview | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showPassword, setShowPassword] = useState(false);
  const [showConfirmation, setShowConfirmation] = useState(false);

  useEffect(() => {
    let active = true;
    void onPreview(invitation.gymId, invitation.token)
      .then((result) => { if (active) setPreview(result); })
      .catch((reason) => { if (active) setError(reason instanceof Error ? reason.message : "This invitation is invalid or expired."); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [invitation, onPreview]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!preview) return;
    const data = new FormData(event.currentTarget);
    const password = preview.existing_account ? undefined : String(data.get("password"));
    const confirmation = preview.existing_account ? undefined : String(data.get("password_confirmation"));
    if (password !== confirmation) {
      setError("The password confirmation does not match.");
      return;
    }

    setBusy(true); setError(null);
    try { await onAccept(invitation.gymId, invitation.token, password); }
    catch (reason) { setError(reason instanceof Error ? reason.message : "The account could not be activated."); }
    finally { setBusy(false); }
  }

  return <main className="auth-page activation-page">
    <section className="auth-story">
      <ActivationBrand />
      <div><p className="eyebrow">Member portal</p><h1>Your gym life, all in one place.</h1><p>View membership details, book classes, follow training plans and manage your profile securely.</p></div>
      <ul><li><ShieldCheck size={17} /> Invitation bound to one gym</li><li><LockKeyhole size={17} /> One-time activation secret</li><li><Building2 size={17} /> Private member workspace</li></ul>
    </section>
    <section className="auth-form-side">
      <form className="auth-card activation-card" onSubmit={submit}>
        <div className="auth-mobile-brand"><ActivationBrand /></div>
        <p className="eyebrow">Secure activation</p>
        {loading && <div className="activation-loading"><LoaderCircle className="spin" size={22} /><strong>Checking your invitation…</strong></div>}
        {!loading && error && !preview && <><h2>Invitation unavailable</h2><div className="form-error" role="alert">{error}</div><button type="button" className="secondary-button activation-cancel" onClick={onCancel}>Return to sign in</button></>}
        {!loading && preview && <>
          <div className="activation-gym"><span><Building2 size={19} /></span><div><small>Invited by</small><strong>{preview.gym_name}</strong></div><CheckCircle2 size={18} /></div>
          <h2>Welcome, {preview.member_first_name}</h2>
          <p>{preview.existing_account ? `Link your existing ${preview.masked_email} account to this member profile.` : `Create your sign-in for ${preview.masked_email}.`}</p>
          {error && <div className="form-error" role="alert">{error}</div>}
          {!preview.existing_account && <div className="activation-fields">
            <label className="activation-field" htmlFor="activation-password">
              <span>Create password</span>
              <span className="activation-password-control">
                <input id="activation-password" name="password" type={showPassword ? "text" : "password"} autoComplete="new-password" required minLength={12} maxLength={255} aria-describedby="activation-password-help" autoFocus />
                <button type="button" className="activation-password-toggle" aria-label={`${showPassword ? "Hide" : "Show"} password`} aria-pressed={showPassword} onClick={() => setShowPassword((visible) => !visible)}>
                  {showPassword ? <EyeOff size={15} aria-hidden="true" /> : <Eye size={15} aria-hidden="true" />}
                  <span>{showPassword ? "Hide" : "Show"}</span>
                </button>
              </span>
            </label>
            <label className="activation-field" htmlFor="activation-password-confirmation">
              <span>Confirm password</span>
              <span className="activation-password-control">
                <input id="activation-password-confirmation" name="password_confirmation" type={showConfirmation ? "text" : "password"} autoComplete="new-password" required minLength={12} maxLength={255} aria-describedby="activation-password-help" />
                <button type="button" className="activation-password-toggle" aria-label={`${showConfirmation ? "Hide" : "Show"} password confirmation`} aria-pressed={showConfirmation} onClick={() => setShowConfirmation((visible) => !visible)}>
                  {showConfirmation ? <EyeOff size={15} aria-hidden="true" /> : <Eye size={15} aria-hidden="true" />}
                  <span>{showConfirmation ? "Hide" : "Show"}</span>
                </button>
              </span>
            </label>
            <small id="activation-password-help">Use at least 12 characters. IronCore never stores this password in the browser.</small>
          </div>}
          <button className="primary-button auth-submit" disabled={busy} type="submit">{busy ? <><LoaderCircle className="spin" size={17} /> Activating</> : <>Activate and sign in <ArrowRight size={17} /></>}</button>
          <button type="button" className="activation-text-button" onClick={onCancel}>Use a different account</button>
          <small>The activation secret was removed from your address bar and is used only for this request.</small>
        </>}
      </form>
    </section>
  </main>;
}
