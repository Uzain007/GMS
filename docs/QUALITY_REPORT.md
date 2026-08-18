# IronCore quality report

Date: 7 August 2026

## Passed

- Production application build
- Deployable artifact structure validation
- ESLint source-quality check with zero warnings
- Server-rendered homepage response and preview metadata
- Product name, dashboard heading and core metric rendering
- Agreed 100-gym / 1,000,000-member capacity contract
- Currency contract: GBP, USD, PKR, AED and SAR
- Card, cash and manual-payment audit requirements
- Platform and tenant role uniqueness checks
- Responsive rules for desktop, tablet and mobile breakpoints
- Keyboard focus styling and reduced-motion support

## Test count

The initial UI checkpoint contained four automated tests. The current suite contains 40 automated build, rendering, architecture and backend-contract tests.

## Milestone boundary

This milestone validates the product interface and agreed domain configuration. Backend tenant isolation, authentication, persistent transactions, provider webhooks and load testing begin with the Laravel/PostgreSQL milestone and receive their own quality gate before handoff.

## Milestone 3 backend checkpoint

### Passed in this workspace

- PHP syntax parsing across every Laravel source, migration and feature-test file
- Stack, currency, role and permission-array contracts
- Fail-closed tenant concern and active-membership middleware contracts
- Tenant-leading indexes and composite tenant foreign-key contracts
- Forced PostgreSQL RLS policy and non-superuser connection-role contracts
- Branch, member, staff, invitation, plan and membership route/middleware contracts
- Immutable membership money/terms snapshot contract
- Tenant-prefixed CSV storage, Redis queue and 500-row import batching contract
- Existing frontend lint/build/render regression gate
- Desktop visual smoke test for navigation and currency switching
- Deterministic compact-currency SSR formatting; the discovered `£86K`/`£86k` hydration mismatch is fixed and regression-tested

### Current automated result

33 tests passed with no failures, skips or cancellations. ESLint completed with zero errors, the production build completed, and the deployable artifact validator passed.

## Milestone 4 tenant-finance checkpoint

### Passed in this workspace

- Every invoice, item, gateway, payment, refund and webhook row is tenant-owned with a non-null `gym_id`
- Composite tenant foreign keys, tenant-leading indexes and forced PostgreSQL RLS contracts
- Server-calculated invoice totals and integer minor-unit amounts across all five supported currencies
- Append-only payment ledger, tenant idempotency keys and row-locked invoice settlement
- Cash, external-terminal, bank, other and hosted Stripe payment flows
- Signed Stripe webhooks, narrow connected-account lookup and provider-event deduplication
- Owner-only refund controls with mandatory audit reasons and immutable original transactions
- Responsive browser QA for invoices, payments, prefilled settlement, refunds and Stripe guidance
- No raw card-number, CVC or payment-token fields in the IronCore interface

### Runtime gate still required

The current build environment does not provide PHP, Composer or Docker. Laravel feature tests and live PostgreSQL RLS integration tests are included in the repository but must run in CI or a PHP/Docker-capable environment. Stripe onboarding, checkout, webhook and refund calls also require test-mode provider credentials before the provider gate is marked complete.

## Milestone 4B platform-subscription checkpoint

### Passed in this workspace

- Platform-owned plan catalogue with append-only monthly/yearly prices across GBP, USD, PKR, AED and SAR
- Tenant-owned customers, subscriptions, Checkout sessions, invoices and webhook evidence with non-null `gym_id`
- Composite tenant foreign keys, tenant-leading indexes, forced RLS and narrow verified-customer webhook lookup
- One non-terminal subscription and one open hosted Checkout per gym, backed by PostgreSQL partial unique indexes
- Separate Stripe platform-account flow with no connected-account routing or IronCore card-entry fields
- Signed Stripe Billing webhook verification, event deduplication, payload hashing and immutable entitlement snapshots
- Owner/super-admin Checkout and portal actions, manager read-only access and platform-admin catalogue controls
- Stale-response protection and immediate SaaS-state clearing on logout or tenant switch
- Responsive desktop browser QA for plan interval switching, plan creation, hosted billing actions and invoice history

### Current automated result

33 tests passed with no failures, skips or cancellations. ESLint completed with zero errors, the production build completed, PHP sources passed parser-based syntax validation, and the deployable artifact validator passed.

### Provider/runtime gate still required

Live Laravel feature tests, PostgreSQL RLS integration and Stripe test-mode Checkout/webhook/portal exercises require PHP/Docker plus test credentials in the deployment or CI environment. No live payment claim is made by this workspace-only checkpoint.

## Milestone 5A attendance and class-booking checkpoint

### Passed in this workspace

- Every credential, attendance, class-session and booking row is tenant-owned with a non-null `gym_id`
- Composite tenant foreign keys, tenant-leading indexes and forced PostgreSQL RLS contracts
- One-time opaque QR issuance with SHA-256-only persistence and immediate previous-credential revocation
- Active, in-date membership and branch-eligibility checks before admission
- One open attendance row per member, backed by a PostgreSQL partial unique index
- Capacity and waitlist counters updated under a class-session row lock
- One active booking per member/session, retained cancellation history and deterministic FIFO promotion
- Server-resolved member self-service and assigned-trainer roster/attendance boundaries
- Role-aware responsive attendance, QR issuance, class schedule, roster and booking interface
- Browser-verified 210×210 QR rendering, full-class waitlist feedback, check-in form reset and overflow-free desktop layout
- Browser QA found and fixed an asynchronous React form-target reset bug; the final app emitted no console warnings or errors
- TypeScript type-check, ESLint, production build and all 33 architecture/rendering tests

### Current automated result

33 tests passed with no failures, skips or cancellations. ESLint and TypeScript completed with zero errors, the production build completed, PHP sources passed parser-based syntax validation, and the deployable artifact validator passed.

### Runtime gate still required

The repository includes Laravel feature coverage for cross-tenant check-in denial and capacity/FIFO promotion. Executing those tests against live Laravel, PostgreSQL RLS and Redis still requires the PHP/Docker-capable CI or deployment environment. QR scanners should also receive device-level acceptance testing with the selected front-desk hardware before production rollout.

## Milestone 5B training, progress and notification checkpoint

### Passed in this workspace

- Every assignment, plan, exercise, session, set, progress, preference and delivery row is tenant-owned with a non-null `gym_id`
- Composite tenant foreign keys, tenant-leading scale indexes and forced PostgreSQL RLS contracts across all eight tables
- Active trainer/member assignment as the explicit server-authoritative access boundary
- Suspended trainer profiles and expired assignments fail closed across list and record access
- One active assignment pair and one active member plan, reinforced by PostgreSQL partial unique indexes
- Reasoned, audited active-to-inactive assignment transition with immediate access revocation and retained history
- Ordered exercise prescriptions, exact gram-based loads and append-only completed session/set history
- Exact integer-thousandths progress measurements with tenant/member/metric/time cursor paths
- Member self-resolution and assigned-trainer enforcement without browser-trusted identity fields
- Encrypted notification destinations, bounded variables, tenant idempotency and preference/quiet-hour scheduling
- Tenant-bound Redis delivery jobs and server-only email, SMS and push adapters
- HTTPS-only provider endpoints and generic failure evidence that cannot persist delivery destinations
- Role-aware responsive coaching, progress trend and masked notification-delivery interface
- Browser-verified assignment, plan, workout and progress form workflows with no horizontal overflow
- Production browser emitted no app-origin warnings or errors; extension-origin diagnostics were excluded
- TypeScript type-check, ESLint, production build, deployable artifact validation and all 36 automated tests

### Current automated result

36 tests passed with no failures, skips or cancellations. ESLint and TypeScript completed with zero errors, the production build completed, PHP sources passed parser-based syntax validation, and the deployable artifact validator passed.

### Runtime/provider gate still required

The repository includes Laravel feature coverage for cross-tenant plan denial, assigned-trainer access and exact workout/progress persistence. Executing it against live Laravel, PostgreSQL RLS and Redis requires the PHP/Docker-capable CI or deployment environment. Email, SMS and push adapters also require selected provider credentials and sandbox delivery tests before production enablement.

## Milestone 6A reporting and operational-hardening checkpoint

### Passed in this workspace

- Management-only, single-tenant reporting behind route/header agreement, fail-closed Eloquent scoping and forced-RLS-compatible queries
- Inclusive local-gym date filters converted to half-open UTC windows and capped at 366 days
- Strict GBP, USD, PKR, AED and SAR separation with integer minor-unit revenue and no implicit conversion
- Equal-length comparison metrics, bounded daily series, member status, payment-method and class-capacity aggregates
- Tenant-leading report indexes and 60-second Redis keys that always contain `gym_id` plus hashed filters
- Named 30/minute user/gym report throttle and 60/minute source-IP readiness throttle
- Generic PostgreSQL/Redis readiness without connection or tenant detail disclosure
- Synthetic k6 load probe with no committed token, live-secret signature scan, deployment runbook and security checklist
- Responsive report navigation, date/currency controls, charts, status mix, collection mix and class funnel
- Browser-verified AED report switching, desktop overflow check and zero app-origin console warnings/errors
- TypeScript type-check, ESLint, production build, PHP parser validation and deployable artifact validation

### Current automated result

39 tests passed with no failures, skips or cancellations. The committed secret scan found no known live-secret signatures.

### Production provider/deployment gates still required

The GitHub-hosted Laravel suite covers selected-tenant count isolation, mismatched-header denial, role denial and 366-day validation under PostgreSQL 17 and Redis 8. Milestones 17 and 18 add synthetic restore and cached-report k6 regression gates. Production still requires provider sandbox evidence, provider PITR/RPO/RTO recovery evidence, deployment-topology capacity/saturation testing and the monitored deployment runbook.

## Milestone 6A preview-navigation QA repair

### Passed in this workspace

- Branches, Membership Plans and Memberships now receive representative records in isolated preview mode instead of rendering blank content
- Preview operational records are explicitly labelled and never replace authenticated selected-gym API collections
- Attendance now uses the shared table styling and a fixed readable minimum width with contained horizontal scrolling
- Browser QA confirms populated Branches, Membership Plans, Memberships, Attendance and Classes views with correct action and billing labels
- The repaired navigation emitted no application-origin browser warnings or errors
- Regression coverage verifies all three operational navigation targets, the preview/live distinction and the attendance table contract

### Current automated result

40 tests pass with no failures, skips or cancellations. TypeScript and ESLint complete with zero errors; the production build, deployable artifact validation and secret scan pass.

## Milestone 11 production CI runtime-gate checkpoint

### Passed in this workspace

- Read-only GitHub Actions workflow syntax and least-privilege checkout contract
- Separate web and backend jobs with explicit time limits and stale-run cancellation
- Locked Node 22.13 install, lint, type-check, secret scan, production dependency audit, portable contracts and deployable artifact build
- PHP 8.3 Laravel job backed by PostgreSQL 17 and Redis 8 service containers
- Disposable non-superuser `ironcore_app` bootstrap with no role/database creation, inheritance or RLS-bypass privilege
- Fail-on-skip runtime test that discovers every public `gym_id` table and requires both RLS and FORCE RLS
- Redis-backed cache, session and queue configuration assertion
- Composer audit, Laravel production-cache validation and weekly Dependabot review across npm, Composer and GitHub Actions
- YAML parsing, PHP parser validation, TypeScript, ESLint, secret scan and all 56 portable contracts

### Hosted runtime confirmed

Commit `79ed6ae` passed both hosted jobs. The backend lane executed all 44 Laravel tests and 335 assertions against PostgreSQL 17 forced RLS and Redis 8 as non-superuser `ironcore_app`, then completed dependency audit and the production cache commands. Do not replace PostgreSQL with SQLite, grant `BYPASSRLS`, or remove fail-on-skip enforcement.

## Milestone 12 member-data export checkpoint

- Queued export generation and delayed expiry are bound to immutable gym/export identifiers and re-establish tenant context in long-lived Redis workers.
- `member_data_exports` has a non-null `gym_id`, composite member foreign key, tenant-leading indexes, fail-closed model scope and forced PostgreSQL RLS.
- Large histories stream through database cursors into a spill-to-disk JSON stream instead of accumulating an unbounded payload in PHP memory.
- Private tenant-prefixed storage, SHA-256 evidence, authenticated `private, no-store` downloads and seven-day byte deletion are covered by portable contracts.
- Production Vinext build/artifact validation, TypeScript, ESLint, secret scan, production npm audit and all 59 portable contracts pass locally.
- Laravel/PostgreSQL/Redis/S3 execution, queue retry/expiry observation and object-storage lifecycle verification remain target-environment gates.

## Milestone 13 hosted runtime-gate repair checkpoint

- Public GitHub check evidence confirmed both first-run jobs failed at their actual test steps; the PHP setup warning was independently visible.
- The web failure is prevented by creating and validating the deployable artifact before rendered-output contracts import it.
- The backend failure is prevented by aligning member-export forced RLS with the shared `ironcore.current_gym_id` connection setting and fail-closed empty-string conversion.
- Portable contracts now assert build-before-render ordering, reject the unsupported setup input and reject the incorrect export RLS namespace.
- Each repaired archive required a new GitHub-hosted run as the authoritative PHP/PostgreSQL/Redis result.
- The second hosted run passed the complete web job and exposed a Sanctum runtime incompatibility in the backend: cookie-authenticated tests receive a non-persisted `TransientToken`, which has no Eloquent key. Credential rotation and logout now distinguish that session marker from stored bearer tokens, with a portable regression contract guarding every affected controller.
- The subsequent backend run advanced past authentication and exposed tenant route models being bound before PostgreSQL identity, tenant and role middleware. The runtime priority now establishes those security boundaries before implicit binding, retains authorization-before-lookup semantics, and adds hosted regression coverage.
- The latest hosted failure was reproduced locally on PostgreSQL 17/Redis 8. The fourth repair consumes the validated `{gym}` parameter before positional controller dispatch, moves gym access to trusted `TenantContext`, groups report dates by alias, repairs session-generation guard refresh, replaces unsupported MFA validators, and adds a narrowly scoped own-security-audit SELECT policy.
- Composer dependencies are now locked and `.env.testing` is present without secrets, preventing dependency drift and dotenv warning noise.
- The tracked API views directory lets the GitHub production-cache stage complete `config:cache`, `route:cache`, `view:cache` and `optimize:clear` even though the backend currently serves JSON only.
- Final result: **44 tests passed, 335 assertions, zero failures, skips, risky tests or warnings** locally and on GitHub under non-superuser forced RLS and Redis-backed cache/session/queue configuration.

## Milestone 14 application-security checkpoint

- CodeQL advanced setup scans JavaScript/TypeScript and GitHub Actions with the `security-extended` query suite.
- Scans run on pull requests, `main` pushes, manual dispatch and a weekly schedule so newly published queries re-evaluate unchanged source.
- Every action is pinned to a reviewed immutable revision, checkout credentials are not persisted and only the analysis job receives `security-events: write` for result upload.
- Commit `2ddc641` passed both hosted `CodeQL (actions)` and `CodeQL (javascript-typescript)` analyses. PHP is explicitly outside CodeQL language support and remains covered by syntax parsing, Composer audit, Laravel runtime tests and human review.
- Local validation passes all 64 portable contracts, ESLint, TypeScript, YAML parsing, secret scan, production build/artifact validation and the zero-vulnerability production npm audit.

## Milestone 15 deployed-web verification checkpoint

- Every frontend build publishes a non-secret full Git SHA release marker; Vercel supplies the deployment-triggering SHA.
- The credential-free workflow runs after every `main` push, every six hours and by manual dispatch with read-only repository permission and immutable action revisions.
- A ten-minute bounded retry window distinguishes normal Vercel alias propagation from a stale or failed deployment.
- The target must be reviewed, HTTPS and explicitly allowlisted; credentials, query strings, fragments, private host literals and cross-origin redirects fail closed.
- The release probe validates the exact commit, HTTP `200` HTML, HSTS, language/title/platform markers, one same-origin stylesheet and script, and the install manifest contract.
- The first hosted smoke passed against Vercel commit `5f485f2`, confirming the exact release plus HSTS, shell, same-origin assets and install manifest. Its accompanying web quality job exposed a contract that assumed the local `development` release marker even when GitHub supplied a real build SHA; the repaired assertion now follows the same Vercel/GitHub/local precedence as production metadata.
- Commit `45cf343` passed the repaired quality gate, both CodeQL analyses and deployed-web smoke. Live cloud-browser QA confirmed the matching release marker and working platform, gym and member preview navigation without application-origin errors.

## Milestone 16 S3-compatible export runtime checkpoint

- The backend workflow provisions disposable S3-compatible storage alongside PostgreSQL 17 and Redis 8, using only non-secret test credentials.
- A production-job integration test creates the bucket through the AWS SDK, runs `GenerateMemberDataExport`, reads the private tenant-prefixed object through Flysystem, and verifies JSON identity, exact SHA-256 and byte count.
- The same test confirms the delayed tenant-bound purge is dispatched, executes `PurgeMemberDataExport` after expiry, and proves the bytes are deleted while the metadata becomes expired.
- All 71 portable contracts pass and prevent removal of the explicit S3 gate, emulator health check, private-object assertion, integrity check or deletion assertion.
- Commit `7033e2b` passed quality, both CodeQL analyses and deployed-web smoke. The hosted backend completed its PostgreSQL/Redis/S3 lane in 1m 37s, and live Vercel QA confirmed the matching release plus working platform, gym and member portal navigation without application-origin errors.
- Production encryption, IAM/bucket policy and lifecycle configuration remain separate deployment evidence.

## Milestone 17 synthetic PostgreSQL restore checkpoint

- The hosted backend now writes two fixed synthetic tenants through the same non-superuser `ironcore_app` identity used by Laravel.
- PostgreSQL 17 creates a compressed custom archive and restores it into a newly created disposable database with ownership and ACL portability options.
- Post-restore assertions rediscover every public `gym_id` table and require both RLS and FORCE RLS while confirming the role still cannot create databases/roles, inherit privileges or bypass RLS.
- Tenant checks prove no-context reads fail closed, each selected gym sees exactly its own restored member/assignment and a third unrelated gym sees no rows.
- A failure-safe cleanup removes the restored database, dump file and source fixtures. No production database, credential or member record is used.
- Local shell syntax and portable contract validation pass; the GitHub-hosted PostgreSQL execution remains the authoritative completion gate.
- This synthetic drill does not replace provider evidence for encrypted backups, PITR/retention, isolated operational recovery or measured RPO/RTO.

## Milestone 18 synthetic report-load checkpoint

- The hosted backend creates a 500-member tenant, an unrelated tenant and 16 expiring synthetic gym-owner tokens under the normal non-superuser/RLS runtime.
- Pinned k6 warms the tenant-keyed 60-second Redis report cache and drives eight requests per second for 30 seconds across disposable operators, keeping every operator below the real report throttle.
- The gate requires the warmed payload identity to remain stable, all report assertions to pass, cross-tenant access to return `403`, HTTP failure rate below 1%, p95 below 500 ms and p99 below 1,000 ms.
- The Laravel target uses 16 local PHP workers and is stopped by an unconditional cleanup step. No production URL, credential, tenant or member data is used.
- Portable contracts validate the immutable action pin, fixed k6 version, guarded fixture, expiring tokens, thresholds, tenant denial and cleanup behavior.
- Commit `066a4d6` passed both hosted quality jobs. The backend lane completed the PostgreSQL/Redis/S3 suite, restore drill and pinned k6 report gate successfully.
- Production-sized capacity and saturation evidence must still be collected against the selected deployment topology.

## Post-Milestone 18 deployed-release propagation checkpoint

- The `066a4d6` push smoke reached the former ten-minute propagation ceiling before Vercel published the matching release; this was a deployment-timing failure, not a web or backend quality-test failure.
- A later credential-free smoke passed on its first attempt against the exact `066a4d6` release, including HTTP 200, HSTS, same-origin CSS/JavaScript and the install manifest.
- The `5183f84` push then reached the fifteen-minute ceiling before Vercel later exposed that exact full release SHA, proving the first extension was still too short rather than exposing a product regression.
- The workflow now allows a bounded thirty-minute deployment window inside a 35-minute job, with portable contracts preventing accidental removal of either limit.

## Milestone 19 production configuration preflight checkpoint

- `php artisan ironcore:production-preflight` validates Laravel's resolved/cached release configuration and returns a non-zero result before migrations or traffic when a launch-critical setting is unsafe or incomplete.
- The backend gate covers HTTPS/browser-session alignment, explicit trusted proxies, non-superuser PostgreSQL, encrypted PostgreSQL/Redis transport, Redis cache/session/queues, private S3-compatible storage, Stripe signing/callback settings, authenticated SMTP, central log routing and complete optional notification-adapter pairs.
- `npm run preflight:production-web` rejects representative demo mode, unsafe or missing public API origins and missing full-SHA release identity before a production frontend build.
- Both preflights emit stable setting names and requirements only. A Laravel regression test injects marker values and asserts that none appear in output.
- Local validation passes the production web preflight, secret scan, TypeScript, ESLint, deployable Vinext build/artifact validation, PHP syntax parsing and all **80 portable contracts** with zero failures, skips or cancellations.
- The production dependency audit passes the required high-severity threshold with zero moderate/high/critical findings. One low-severity optional `@babel/core` advisory remains in Next.js's `styled-jsx` path and is outside this milestone's dependency changes.
- The first approved hosted backend attempt stopped during Composer installation when GitHub codeload returned HTTP 429 for Symfony; the web, CodeQL and deployed-release checks passed, and no IronCore Laravel assertion ran or failed. A later backend run must provide the runtime result.

## Milestone 20 notification transport runtime checkpoint

- The backend quality lane now starts a loopback-only authenticated SMTP and certificate-verified HTTPS provider boundary using a certificate/private key generated inside the disposable runner.
- Password recovery crosses the public non-enumerating API, Redis queue, password broker and SMTP boundary; the received synthetic message must retain the fragment-only reset link.
- Tenant email, SMS and push deliveries cross Redis and the real transport adapters with exact payload/provider-ID assertions. A mismatched immutable gym/delivery payload must fail closed before any provider request, and a disabled channel is suppressed before an attempt.
- Adapter failures now discard the original transport exception chain before retry/failed-job evidence can retain provider response bodies, endpoint details, credentials or member destinations. Stored delivery failure evidence remains generic and bounded.
- The custom notification CA bundle is optional, never disables verification and must reference a readable PEM file when production preflight evaluates it.
- Local validation passes the clean production build, artifact/render contracts, production web preflight, TypeScript, ESLint, PHP syntax parsing, secret scan and all **84 portable contracts** with zero failures, skips or cancellations.
- The production npm lockfile audit reports zero vulnerabilities. The Laravel/PostgreSQL/Redis provider-boundary tests require the existing hosted lane after the user approves a commit/push; all workflow credentials and provider values are synthetic and runner-only.
- This credential-free protocol gate does not replace selected-provider sandbox delivery, sender/domain approval, suppression/rate-limit handling, alerting or production credentials.

## Milestone 21 Stripe transport runtime checkpoint

- The backend quality lane now starts a second loopback-only, certificate-verified HTTPS boundary with synthetic Stripe bearer and endpoint-signing values generated or supplied only inside the disposable runner.
- Connect coverage exercises account onboarding/refresh, direct-charge member Checkout and refunds. Platform Billing coverage exercises product/price creation, customers, subscription Checkout, portal sessions and subscription retrieval without a connected-account header.
- Request evidence verifies exact integer minor units, tenant metadata and server-authored idempotency headers while proving gym member funds and IronCore SaaS subscription funds remain separate.
- Connect and Billing webhooks use different signing secrets, resolve tenants only through signed opaque account/customer identifiers, reject cross-tenant metadata and treat replayed event IDs as duplicates without repeating ledger changes.
- Stripe HTTP exceptions are replaced with one generic exception that retains no provider body, endpoint, credential, payment reference or previous exception chain; ledger failure evidence stores only that bounded message.
- The optional Stripe CA bundle never disables verification and production preflight rejects an unreadable configured file without printing its path.
- Local validation passes the clean production build and artifact/render checks, TypeScript, ESLint, PHP syntax parsing, secret scan and all **89 portable contracts** with zero failures, skips or cancellations. The production dependency audit reports zero vulnerabilities.
- The Laravel/PostgreSQL runtime tests require the existing hosted lane after the user approves a commit/push. This credential-free boundary does not replace real Stripe test-mode onboarding, Checkout, refunds, portal actions, asynchronous webhooks, monitoring or production credentials.

## Milestone 22 hosted dependency-install resilience checkpoint

- Public workflow evidence shows the Milestone 19, 20 and 21 web jobs passed while each backend job stopped at `Install backend dependencies` before any Laravel assertion; the first retained diagnosis was a transient GitHub codeload HTTP 429.
- Official `actions/cache` v5.0.3 is pinned to full commit `cdf6c1fa76f9f475f3d7449005a359c84ca0f306` and restores Composer download archives only under an operating-system/PHP/exact-lockfile key. Generated `vendor` code is excluded.
- Composer parallel HTTP pressure is limited to four. The Node 22.13 retry runner preserves the exact `--prefer-dist` locked install, waits 15/30/60 seconds and fails after attempt four without an update, source substitution, cache clearing or credential injection.
- Executable regression tests simulate recovery after two transient failures and hard failure after the fourth attempt, while also checking exact arguments, unchanged lockfile content, cache scope, immutable action pinning and absence of workflow credentials.
- Local validation passes a clean production build, post-build artifact/render contracts, TypeScript, ESLint, PHP syntax parsing, secret scan and all **92 portable contracts** with zero failures, skips or cancellations. The production npm dependency audit reports zero vulnerabilities.
- No PHP dependency, Laravel source, tenant schema, route, permission or product behavior changed. The approved `12276be` run exhausted all four attempts at the same dependency boundary, so no hosted backend assertion ran.

## Milestone 23 credential-isolated Composer prefetch checkpoint

- The approved Milestone 22 run passed the web, CodeQL and deployed-release checks but exhausted all four Composer attempts before Laravel started, which established the transport blocker addressed here.
- GitHub Actions' ephemeral read-only token is now converted to in-memory Composer authentication only for locked prefetch children that disable every plugin and script.
- A separate normal Composer activation child explicitly removes `COMPOSER_AUTH`, the workflow handoff variable, `GITHUB_TOKEN` and `GH_TOKEN` before Laravel package discovery or any third-party code can run.
- Lockfile-keyed archive caching, reduced parallelism, exact `--prefer-dist` packages and finite 15/30/60-second backoff remain; `composer update`, source substitution and generated-`vendor` caching remain prohibited.
- Executable tests cover exact phase arguments, credential isolation, safe environment retention, secret-free runner output, transient recovery, retry exhaustion, activation failure and unchanged lockfile content.
- No dependency, Laravel source, tenant schema, route, permission or product behavior changed. The complete local quality gate passed **93 portable contracts**.
- The approved `48561a8` run installed all 116 locked packages during authenticated prefetch and reached credential-free activation. `artisan package:discover` then exposed an early-bootstrap config-service defect before any Laravel assertion ran.

## Milestone 24 bootstrap-safe trusted proxy checkpoint

- Trusted proxy parsing now lives in `config/trustedproxy.php`, where Laravel's default HTTP middleware resolves it after configuration is available.
- `bootstrap/app.php` no longer calls `config()` or manually configures trusted proxies while the application container is still being created, removing the exact package-discovery failure.
- Production preflight remains fail closed for empty, hostname or malformed proxy values and accepts reviewed IP/CIDR boundaries plus the explicit provider wildcard.
- A Laravel feature test covers wildcard normalization, and the portable regression contract prevents early config resolution from returning to bootstrap.
- Local validation passes a clean production build, artifact/render contracts, TypeScript, ESLint, PHP syntax parsing, production web preflight, secret scan and all **94 portable contracts** with zero failures, skips or cancellations. The production dependency audit reports zero vulnerabilities.
- The local workstation has no PHP/Composer/PostgreSQL runtime, so the complete existing Laravel/PostgreSQL/Redis/S3/provider/restore/load authority remains pending an approved commit/push. No commit or push is part of this milestone handoff.
