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
2. Run the automated Node contracts, PHP tests, PostgreSQL RLS tests, secret scan and dependency/security scans in CI.
3. Back up PostgreSQL and confirm the latest restore drill before a schema-changing release.
4. Put all secrets in the host secret manager. Never copy `.env` into an image or Git.
5. Run `php artisan migrate --force` once using a deployment job with the schema-owner role. The web/worker runtime remains `ironcore_app`.
6. Deploy the API, queue workers and scheduler from the same release. Run `php artisan config:cache`, `route:cache` and `view:cache` during image/release preparation.
7. Restart queue workers with `php artisan queue:restart`, then shift traffic only after `/up` and `/api/v1/health/readiness` pass.
8. Deploy the frontend with the exact production API origin. Verify Sanctum stateful domains, CORS, secure cookies and the shared HTTPS parent domain.
9. Exercise login, password recovery through the default Redis queue and mail sandbox, MFA enrollment/challenge/recovery on the shared Redis cache, explicit tenant selection, one tenant read/write path, one queued notification, and signed Stripe test webhooks.
10. Watch error rate, queue age, failed jobs, database saturation and webhook failures through the rollback window.

## Required production environment

Set `APP_ENV=production`, `APP_DEBUG=false`, a generated `APP_KEY`, PostgreSQL/Redis/object-storage credentials, exact `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE=true`, provider secrets and notification-adapter credentials. Keep `CACHE_STORE`, `QUEUE_CONNECTION` and `SESSION_DRIVER` on Redis.

## Rollback and recovery

- Roll application containers back to the previous immutable image. Never use destructive Git or database resets.
- Database migrations must be backward-compatible for at least one application release. Use a follow-up migration for data/schema corrections.
- Pause workers before restoring PostgreSQL. Restore into an isolated environment, validate tenant counts and RLS, then switch through the provider's controlled recovery procedure.
- Rotate any credential suspected of exposure and invalidate affected sessions/provider endpoints.

## Monitoring gates

Alert on readiness failures, HTTP 5xx/error rate, p95 latency, PostgreSQL connections/locks/storage, Redis memory/evictions, queue depth/oldest age/failed jobs, scheduler heartbeat, Stripe webhook retries, notification-provider failures and backup/PITR status.

## Repository quality gate

Before a release commit is eligible for deployment, GitHub must report both `Web build and contracts` and `Laravel, PostgreSQL RLS and Redis` as successful. Configure `main` branch protection to require these checks and a pull request; never allow a deployment credential or production provider secret into the quality workflow. The backend check is intentionally fail-on-skip so SQLite or a privileged PostgreSQL connection cannot masquerade as RLS evidence.
