# IronCore deployment runbook

This runbook targets a production topology with a separately deployed web app, Laravel API, PostgreSQL 17, Redis and private S3-compatible storage. Free static hosting can serve the frontend preview, but a production IronCore SaaS also needs paid, backed-up stateful services and always-on queue processing.

## Required services

- Next.js web/PWA behind HTTPS
- Laravel API built once and reused by web, queue-worker and scheduler processes
- PostgreSQL 17 with point-in-time recovery and the non-superuser `ironcore_app` runtime role
- Redis with authentication/TLS where supported, persistence appropriate to queues, and eviction monitoring
- Private S3-compatible bucket with tenant-prefixed object keys
- Stripe test/live accounts, transactional email, optional SMS/push adapters, central logs, error monitoring and uptime checks

## Release sequence

1. Build an immutable frontend artifact and Laravel image from a reviewed commit.
2. Run the automated Node contracts, PHP tests, PostgreSQL RLS tests, credential-free notification and Stripe transport gates, synthetic cached-report load gate, secret scan and dependency/security scans in CI.
3. Back up PostgreSQL and confirm the latest restore drill before a schema-changing release.
4. Put all secrets in the host secret manager. Never copy `.env` into an image or Git.
5. Build Laravel's configuration cache, then run `php artisan ironcore:production-preflight`. Stop before migrations if it fails; its output is safe to retain as release evidence.
6. Run `php artisan migrate --force` once using a deployment job with the schema-owner role. The web/worker runtime remains `ironcore_app`.
7. Deploy the API, queue workers and scheduler from the same release. Run `php artisan route:cache` and `view:cache` during image/release preparation.
8. Restart queue workers with `php artisan queue:restart`, then shift traffic only after `/up` and `/api/v1/health/readiness` pass.
9. In the production web-build environment, run `npm run preflight:production-web`, then build and deploy the frontend with the exact production API origin.
10. Require `Deployed web release` to pass for the deployed commit. The probe waits for the public alias to expose the triggering full SHA, then checks HTTPS/HSTS, the reviewed shell, same-origin CSS/JavaScript and the install manifest.
11. Exercise login, password recovery through the default Redis queue and the selected mail-provider sandbox, MFA enrollment/challenge/recovery on the shared Redis cache, explicit tenant selection, one tenant read/write path, one queued notification through each enabled selected-provider sandbox, and Stripe test-mode onboarding, Checkout, refund, portal and signed asynchronous webhooks. CI transport emulators are protocol baselines, not this provider evidence.
12. Watch error rate, queue age, failed jobs, database saturation and webhook failures through the rollback window.

## Required production environment

Set `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, reviewed `TRUSTED_PROXIES`, encrypted PostgreSQL/Redis connections, object-storage configuration, exact `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE=true`, provider secrets and notification-adapter credentials. Keep `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` on Redis. Run both commands in [PRODUCTION_PREFLIGHT.md](PRODUCTION_PREFLIGHT.md) against the final deployment environment.

## Rollback and recovery

- Roll application containers back to the previous immutable image. Never use destructive Git or database resets.
- Database migrations must be backward-compatible for at least one application release. Use a follow-up migration for data/schema corrections.
- Pause workers before restoring PostgreSQL. Restore into an isolated environment, validate tenant counts and RLS, then switch through the provider's controlled recovery procedure.
- Rotate any credential suspected of exposure and invalidate affected sessions/provider endpoints.

The CI restore drill is the minimum schema/data portability baseline: it uses only a disposable PostgreSQL 17 service and synthetic tenants, and verifies restored FORCE RLS with `ironcore_app`. Before production launch, record separate provider evidence for encrypted backups, PITR/retention, an isolated restore of approved non-production data, measured RPO/RTO, monitoring and controlled cutover.

The CI k6 run is a regression baseline for cached reporting, not a capacity claim. Before launch, repeat an approved synthetic-data test against the selected API/PostgreSQL/Redis topology, record saturation and recovery behavior, and choose production alert thresholds without using real member data.

## Monitoring gates

Alert on readiness failures, HTTP 5xx/error rate, p95 latency, PostgreSQL connections/locks/storage, Redis memory/evictions, queue depth/oldest age/failed jobs, scheduler heartbeat, Stripe webhook retries, notification-provider failures and backup/PITR status.

## Repository quality gate

Before a release commit is eligible for deployment, GitHub must report `Web build and contracts`, `Laravel, PostgreSQL RLS and Redis`, `CodeQL (actions)` and `CodeQL (javascript-typescript)` as successful. After Vercel updates the production alias, `Deployed web release` must confirm that the same full commit SHA is serving. Configure `main` branch protection to require the pre-deployment checks and a pull request; never allow a deployment credential or production provider secret into a quality or smoke workflow. The backend dependency step may restore lockfile-keyed Composer download archives and use its bounded retry runner, but it must never update the lockfile, cache generated `vendor` code or receive a GitHub token. The backend check is intentionally fail-on-skip so SQLite or a privileged PostgreSQL connection cannot masquerade as RLS evidence.

The committed smoke target is `https://gms-beige-ten.vercel.app/`. A reviewed source change must update both its URL and exact hostname allowlist before a production-domain migration. The check is intentionally public and credential-free; authenticated Laravel readiness and business workflows remain the monitored deployment steps above.
