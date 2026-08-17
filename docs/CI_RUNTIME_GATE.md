# IronCore production CI runtime gate

Milestone 11 moves the Laravel runtime evidence out of the build-only workspace and into GitHub-hosted Linux jobs. The workflow runs for every pull request, every push to `main`, and an authorised manual dispatch. It uses only read access to repository contents, pins actions to reviewed full commit hashes and does not receive production secrets.

## Required checks

### Web build and contracts

- Installs exactly the committed `package-lock.json` with Node 22.13.
- Runs ESLint, TypeScript, the committed secret scan and every portable architecture/product contract.
- Audits production npm dependencies at high severity or above.
- Builds and validates the deployable web artifact.

### Laravel, PostgreSQL RLS, Redis and S3-compatible storage

- Installs PHP 8.3 and the extensions used by the production API.
- Starts disposable PostgreSQL 17, Redis 8 and S3-compatible LocalStack services.
- Creates the test database under `ironcore_app`, an explicit non-superuser login that cannot create databases/roles, inherit privileges or bypass RLS.
- Generates a new ephemeral Laravel `APP_KEY`; no committed or production key is used.
- Installs and audits Composer dependencies, runs the complete Laravel suite with skipped/risky tests treated as failures, and validates production config/route/view caches.
- Proves that every public table containing `gym_id` has both RLS and FORCE RLS enabled and that Redis-backed cache, session and queue configuration is active.
- Executes the production member-export generation and expiry jobs over the real AWS SDK/Flysystem HTTP boundary, then verifies private tenant-prefixed bytes, integrity metadata and deletion.
- Writes two synthetic tenants through `ironcore_app`, creates a PostgreSQL 17 custom-format archive and restores it into a fresh disposable database.
- Reconnects to the restored database as `ironcore_app` and fails unless every `gym_id` table retains RLS plus FORCE RLS, no-context reads return no tenant rows, each selected tenant sees only its own fixture and an unrelated tenant sees none.
- Creates a 500-member synthetic gym plus an isolated gym, distributes load across 16 ten-minute CI-only operator tokens, and runs pinned k6 against a 16-worker disposable Laravel server.
- Warms the tenant-keyed Redis report cache, then requires 100% valid cached payloads, cross-tenant `403`, below 1% HTTP failures, p95 below 500 ms and p99 below 1,000 ms without weakening the real 30-per-minute user/gym throttle.

The database bootstrap refuses to run unless both `CI=true` and `IRONCORE_RUNTIME_GATE=true`; storage, restore and load stages independently require their explicit gate markers. All credentials and tokens are disposable workflow-only values, expire or are destroyed with the runner, and the job never creates or modifies production infrastructure. Cleanup removes the restore artifacts and stops the local load server on success or failure.

## GitHub handoff

After this milestone reaches GitHub:

1. Open the repository's Actions page and confirm both jobs finish successfully.
2. In repository branch rules for `main`, require a pull request and require both named checks above.
3. Keep direct pushes and force pushes disabled for `main` once the rule is verified.
4. Retain failed logs as defect evidence; do not weaken the role or remove fail-on-skip flags to make a run green.

Weekly Dependabot checks cover npm, Composer and GitHub Actions metadata. Dependency updates still pass through the same two runtime jobs before merge.

## Evidence boundary

A green hosted run closes the generic PHP/PostgreSQL/Redis execution gate, provides forced-RLS evidence, proves the S3 member-export lifecycle, proves synthetic custom-archive restoration, and establishes a repeatable cached-report performance regression baseline. It does not replace production-sized capacity testing against the selected topology, bucket encryption/IAM/lifecycle evidence, provider encrypted backup/PITR/retention and measured RPO/RTO evidence, provider sandbox tests, infrastructure monitoring, privacy approval or production user-acceptance testing.

Commit `79ed6ae` closed this gate on 12 August 2026: both `Web build and contracts` and `Laravel, PostgreSQL RLS and Redis` completed successfully. The backend lane passed all 44 Laravel tests and 335 assertions, dependency audit and production cache commands under the non-superuser forced-RLS configuration.

Commit `7033e2b` closed the S3-compatible runtime addition on 13 August 2026. Quality, CodeQL and deployed-web checks passed; the backend lane completed the real HTTP object-storage generation, integrity and expiry lifecycle while Vercel served the exact release and all three preview portals passed live interaction QA.

Commit `b5bb2d0` closed the synthetic PostgreSQL restore addition on 17 August 2026. Quality, CodeQL and deployed-web checks passed; the restored database retained least-privilege identity, FORCE RLS and selected-tenant isolation.

## First hosted-run repair

The first `main` run after Milestone 12 exposed two ordering/namespace defects that local build-first validation could not reveal: rendered-output contracts executed before `dist/server/index.js` existed, and the member-export policy read `app.current_gym_id` while the shared tenant context writes `ironcore.current_gym_id`. Milestone 13 makes the verified build precede the portable contracts, aligns the export policy with the established fail-closed setting, removes an unsupported setup action input, and adds regression assertions for both contracts.

The next hosted run passed the web job and then exposed a Laravel-only authentication boundary: Sanctum represents cookie-authenticated sessions with a non-persisted `TransientToken`. IronCore now treats only Eloquent-backed access tokens as bearer credentials when retaining or deleting a current token, while session credential rotation continues through `auth_version` and session regeneration.

The following backend rerun reached the tenant feature suite and exposed an RLS-only middleware-ordering defect: Laravel's default priority resolved implicit route models before IronCore had bound the authenticated database identity, selected tenant and role decision. The repair places `auth.version`, `database.identity`, `tenant` and `role` ahead of `SubstituteBindings`, so PostgreSQL can resolve tenant models only after the security boundary exists and unauthorized roles still receive `403` before record lookup. The membership creation test now also reflects the endpoint's intentional `201 Created` response.

The next rerun proved that priority alone was insufficient because Laravel's controller dispatcher still passed the already-consumed `{gym}` value positionally, shifting every nested parameter by one. `ResolveTenant` now removes that parameter only after route/header agreement, gym lookup and active-access authorization, while gym and SaaS controllers read the trusted `TenantContext`. The same repair closes PostgreSQL's duplicate-timezone-placeholder grouping error, stateful session-generation drift, unsupported MFA validators and platform security-audit visibility. A production-shaped local run passed all 44 Laravel tests and 335 assertions without warnings as non-superuser `ironcore_app` on PostgreSQL 17 forced RLS and Redis 8; commit `79ed6ae` then passed the same hosted authority.

The production-cache stage is also exercised locally. The API keeps an empty tracked `resources/views` directory so Laravel's `view:cache` command succeeds even though IronCore currently returns JSON rather than Blade pages.
