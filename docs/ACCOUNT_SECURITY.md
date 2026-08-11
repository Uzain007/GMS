# IronCore account security

Milestone 9 completes password recovery, authenticated password changes and cross-driver session revocation for every IronCore identity. These controls are platform-owned: they do not accept a gym identifier and never weaken tenant middleware, policy or PostgreSQL RLS.

## Recovery flow

1. The browser obtains a Sanctum CSRF cookie and posts a normalized email to `POST /api/v1/auth/forgot-password`.
2. Laravel returns HTTP 202 with the same message for every syntactically valid email, then queues the account lookup on the configured default Redis queue.
3. For a matching user, Laravel stores only the broker-hashed reset token and sends a link using `FRONTEND_URL/#reset_email=…&reset_token=…`.
4. The web app copies the fragment values into component memory and immediately removes the fragment with `history.replaceState`.
5. `POST /api/v1/auth/reset-password` locks and consumes the one-time token with the user credential change, applies the strong-password rule, rotates the remember token, advances `auth_version`, deletes every Sanctum token and establishes one regenerated web session.

Reset values must never be moved into query parameters, logs, analytics, local storage, session storage or offline caches. The broker expiry is 60 minutes and request throttling is keyed by normalized email plus source IP.

## Password changes and session revocation

`PATCH /api/v1/auth/password` requires the current password and a new password of at least 12 characters containing upper and lower case letters, a number and a symbol. It then advances `users.auth_version`.

Every stateful login or member activation stores the current version inside the encrypted server-side session. The `auth.version` middleware compares that value with the user record before database identity or tenant middleware runs. A password reset or change therefore invalidates old Redis and database sessions without depending on session-key internals; each stale session receives HTTP 401 on its next request.

Password reset revokes every bearer token. Authenticated password change retains only the current context: browser requests keep the newly regenerated session, while native bearer requests keep their current token and delete all other device tokens.

## Runtime requirements

- Set `FRONTEND_URL` to the exact HTTPS web origin before caching Laravel configuration.
- Keep `QUEUE_CONNECTION=redis` and run the default queue worker from the same release as the web process.
- Configure a real mail transport; the local default writes reset messages to the Laravel log.
- Use HTTPS, secure encrypted session cookies, exact CORS/Sanctum origins and the existing shared parent-domain deployment contract.
- Apply migration `2026_08_11_000020_add_auth_version_to_users_table.php`. Existing sessions do not have a generation and intentionally fail closed, requiring one fresh sign-in after deployment.

## Verification

The portable contract suite validates migration, middleware order, non-enumerating queuing, fragment-only reset handoff, strong-password rules, credential revocation and web-shell access. `PhaseNineAccountSecurityTest` additionally exercises the Laravel broker, notification URL, reset lifecycle, password change and stale-session rejection when a PHP/PostgreSQL/Redis-capable runtime is available.
