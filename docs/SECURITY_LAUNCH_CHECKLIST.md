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
- [ ] Synthetic report load meets thresholds without tenant leakage; production tests never use real member data.
- [ ] Central logs, error tracking, uptime, queue, scheduler, webhook and provider alerts reach the on-call owner.
- [ ] Member export workflow passes PostgreSQL/Redis/S3 runtime validation; erasure/retention rules, privacy notice, processor agreements and incident-response contacts are approved for launch countries.

Milestone 11 commits the read-only web and Laravel/PostgreSQL/Redis quality workflow. Keep the CI item unchecked until its first hosted run succeeds, both checks are required by `main` branch protection, and a separate application-security scan is selected and evidenced.
