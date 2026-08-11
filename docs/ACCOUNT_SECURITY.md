# IronCore account security

Milestones 9 and 10 complete password recovery, authenticated password changes, cross-driver session revocation and optional TOTP multi-factor authentication for every IronCore identity. These controls are platform-owned: they do not accept a gym identifier and never weaken tenant middleware, policy or PostgreSQL RLS.

## Recovery flow

1. The browser obtains a Sanctum CSRF cookie and posts a normalized email to `POST /api/v1/auth/forgot-password`.
2. Laravel returns HTTP 202 with the same message for every syntactically valid email, then queues the account lookup on the configured default Redis queue.
3. For a matching user, Laravel stores only the broker-hashed reset token and sends a link using `FRONTEND_URL/#reset_email=…&reset_token=…`.
4. The web app copies the fragment values into component memory and immediately removes the fragment with `history.replaceState`.
5. `POST /api/v1/auth/reset-password` locks and consumes the one-time token with the user credential change, applies the strong-password rule, rotates the remember token, advances `auth_version` and deletes every Sanctum token.
6. A user without MFA receives one regenerated web session. An MFA-enabled user receives only a five-minute opaque challenge and must complete the second factor before any session is created.

Reset values must never be moved into query parameters, logs, analytics, local storage, session storage or offline caches. The broker expiry is 60 minutes and request throttling is keyed by normalized email plus source IP.

## Password changes and session revocation

`PATCH /api/v1/auth/password` requires the current password and a new password of at least 12 characters containing upper and lower case letters, a number and a symbol. It then advances `users.auth_version`.

Every stateful login or member activation stores the current version inside the encrypted server-side session. The `auth.version` middleware compares that value with the user record before database identity or tenant middleware runs. A password reset or change therefore invalidates old Redis and database sessions without depending on session-key internals; each stale session receives HTTP 401 on its next request.

Password reset revokes every bearer token. Authenticated password change retains only the current context: browser requests keep the newly regenerated session, while native bearer requests keep their current token and delete all other device tokens.

## Authenticator MFA

IronCore uses RFC 6238 TOTP with HMAC-SHA-1, six digits, 30-second periods, a 160-bit Base32 secret and a bounded one-step clock window. Setup requires the current password. The pending secret is encrypted at rest and does not become active until a valid authenticator value is confirmed.

The account-security screen renders the returned `otpauth://` value as a QR code locally. The secret exists in component memory only and is not placed in a URL, browser storage, analytics or logs. Confirmation locks the user row, records the accepted TOTP counter, advances `auth_version`, revokes every other credential and returns eight recovery codes once.

Correct credentials for login, password-reset completion or existing-member activation produce a 256-bit opaque challenge instead of authentication when MFA is enabled. Redis retains only a SHA-256-keyed challenge record for five minutes, including the user's current authentication generation and at most five failed attempts. Verification is cache-locked and the challenge is deleted before a session or bearer token is issued.

The user row stores the highest accepted TOTP counter. A new code must advance it, preventing two concurrent requests or a replay within the same 30-second period from succeeding.

## Recovery codes and disablement

Eight recovery codes are generated from 80 random bits each. Plaintext is returned only after enrollment or explicit regeneration; PostgreSQL stores only an application-keyed HMAC-SHA-256 digest. A recovery code is consumed once under a database lock.

Regeneration requires the current password and a fresh authenticator code and invalidates every previous unused code. Disabling MFA requires the current password plus either a fresh authenticator code or one recovery code. Disablement deletes the encrypted secret and all recovery rows, advances `auth_version`, and revokes every credential except the current authenticated context.

## Runtime requirements

- Set `FRONTEND_URL` to the exact HTTPS web origin before caching Laravel configuration.
- Keep `QUEUE_CONNECTION=redis` and run the default queue worker from the same release as the web process.
- Keep `CACHE_STORE=redis`; five-minute MFA challenges and their locks must use the shared production cache across API instances.
- Configure a real mail transport; the local default writes reset messages to the Laravel log.
- Use HTTPS, secure encrypted session cookies, exact CORS/Sanctum origins and the existing shared parent-domain deployment contract.
- Apply migrations `2026_08_11_000020_add_auth_version_to_users_table.php` and `2026_08_11_000021_add_multi_factor_authentication.php`. Existing sessions without a generation intentionally fail closed, requiring one fresh sign-in after deployment.

## Verification

The portable contract suite validates migrations, middleware order, non-enumerating queuing, fragment-only reset handoff, strong-password rules, credential revocation, challenge storage, entry-path MFA enforcement and volatile web handling. `PhaseNineAccountSecurityTest`, `PhaseTenMultiFactorAuthenticationTest` and `TotpServiceTest` additionally exercise the Laravel broker, RFC vector, enrollment, login, one-time recovery, reset gating, disablement and stale-session rejection when a PHP/PostgreSQL/Redis-capable runtime is available.
