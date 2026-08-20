# IronCore security launch checklist

The release owner must record evidence for every item before production traffic.

- [ ] Laravel runs as non-superuser `ironcore_app`; forced RLS integration tests pass against PostgreSQL 17.
- [ ] Cross-tenant tests cover every new tenant table, route and queued job.
- [ ] `APP_DEBUG=false`; no secrets, provider payloads, card data or notification destinations appear in logs.
- [ ] HTTPS is enforced; proxy trust, HSTS, exact CORS/Sanctum origins, secure/HttpOnly/SameSite cookies and CSRF are verified.
- [ ] Super-admin accounts use strong MFA/SSO controls; owner/manager/receptionist/trainer/member permissions are least privilege.
- [ ] Login, report, webhook and readiness rate limits are active and observed behind the real proxy/CDN.
- [ ] Stripe webhook signatures, idempotency, narrow opaque-account/customer lookup and separate Connect/Billing secrets are verified.
- [ ] PostgreSQL PITR, encrypted backups and an isolated restore drill meet the agreed RPO/RTO.
- [ ] Redis authentication/TLS, persistence, memory alerts and failed-job handling are enabled.
- [ ] Object storage is private, encrypted, tenant-prefixed and protected by retention/lifecycle policy.
- [ ] CI passes PHP/Laravel tests, RLS tests, frontend contracts, secret scan, dependency audit and an application security scan.
- [ ] The backend and web production preflights pass against the final resolved deployment settings before migrations or traffic; retained output contains no configured values.
- [ ] The deployed frontend exposes the reviewed full release SHA and the credential-free live smoke passes HTTPS/HSTS, shell, same-origin asset and install-manifest checks.
- [ ] Synthetic report load meets thresholds without tenant leakage; production tests never use real member data.
- [ ] Central logs, error tracking, uptime, queue, scheduler, webhook and provider alerts reach the on-call owner.
- [ ] Member export workflow passes PostgreSQL/Redis/S3 runtime validation; erasure/retention rules, privacy notice, processor agreements and incident-response contacts are approved for launch countries.

Milestone 11's web and Laravel/PostgreSQL/Redis jobs passed on commit `79ed6ae`. Both Milestone 14 CodeQL analyses passed on commit `2ddc641`. Milestone 15's quality, CodeQL and deployed-web checks passed on `45cf343`. Milestone 16 passed quality, CodeQL, S3 runtime and deployed-web verification on `7033e2b`; Vercel served that exact release and all three preview portals passed live QA. Milestone 17 passed quality, CodeQL, synthetic restore and deployed-web verification on `b5bb2d0`. Milestone 18's hosted quality and synthetic load gate passed on `066a4d6`. Keep the CI item unchecked until the checks are required by `main` branch protection. Keep provider/storage items unchecked until the selected services have their own evidence. The synthetic load baseline does not replace production-sized capacity testing.

Milestone 20's disposable SMTP/HTTPS boundary is intentionally credential-free. Even after it passes, keep mail/SMS/push provider items unchecked until the selected sandboxes prove sender/domain approval, delivery, suppression/rate-limit behaviour and alerting.

Milestone 21's disposable Stripe HTTPS boundary is also credential-free. Even after it passes, keep the Stripe item unchecked until test mode proves hosted onboarding, Checkout, refunds, portal actions, asynchronous webhook delivery and operational alerting with the selected Stripe account configuration.

Milestone 22 addresses the repeated pre-test Composer registry failure with a lockfile-keyed download cache and finite retries. Keep the CI item unchecked until an approved push reaches and passes both hosted quality jobs; cache/retry behavior is reliability evidence, not a substitute for the Laravel/PostgreSQL/Redis runtime authority.

Milestone 23 authenticates only the Composer package prefetch, with plugins and scripts disabled, using GitHub Actions' ephemeral read-only token. The normal Composer/Laravel activation child removes every Composer/GitHub credential variable first. Keep the CI item unchecked until an approved push passes the complete hosted backend authority.

The approved Milestone 23 run proved that credential boundary and reached Laravel package discovery. Milestone 24 moved trusted-proxy resolution out of early application bootstrap while retaining request-time forwarding-header protection and preflight validation; its approved push confirmed package discovery and reached the test suite.

The approved Milestone 24 run passed package discovery and reached the Laravel suite; 60 tests passed before a raw multipart-email assertion failed. Milestone 25 decodes individual MIME text bodies only inside the authenticated in-memory CI provider and uses failure messages that do not include reset values or message bodies. Keep the CI item unchecked until an approved Milestone 25 push passes the complete hosted backend authority.

Milestone 26 makes real account login the frontend entry and keeps its optional role previews read-only. The 18 August 2026 public deployment still lacked a configured reachable Laravel API, so do not mark login, readiness, provider or production-user acceptance complete until Super Admin, Gym Admin and Member accounts pass against the final HTTPS API/CORS/Sanctum/cookie topology.
