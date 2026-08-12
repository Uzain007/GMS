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

### Runtime/load/deployment gate still required

The Laravel feature suite includes selected-tenant count isolation, mismatched-header denial, role denial and 366-day validation. Live execution still needs PHP/Composer, PostgreSQL 17 under `ironcore_app` and Redis. Run the k6 probe against a synthetic test tenant, complete provider sandbox tests and a backup restore drill, then execute the monitored deployment runbook before production launch.

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

### Hosted runtime confirmation still required

The repaired suite has now been executed locally against PostgreSQL 17 forced RLS and Redis 8 as the non-superuser `ironcore_app`: all 44 Laravel tests and 335 assertions pass without warnings. The next GitHub-hosted backend run remains authoritative for the committed archive. Do not replace PostgreSQL with SQLite, grant `BYPASSRLS`, or remove fail-on-skip enforcement.

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
- A new GitHub-hosted run remains the authoritative PHP/PostgreSQL/Redis result.
- The second hosted run passed the complete web job and exposed a Sanctum runtime incompatibility in the backend: cookie-authenticated tests receive a non-persisted `TransientToken`, which has no Eloquent key. Credential rotation and logout now distinguish that session marker from stored bearer tokens, with a portable regression contract guarding every affected controller.
- The subsequent backend run advanced past authentication and exposed tenant route models being bound before PostgreSQL identity, tenant and role middleware. The runtime priority now establishes those security boundaries before implicit binding, retains authorization-before-lookup semantics, and adds hosted regression coverage; the next GitHub run is authoritative.
- The latest hosted failure was reproduced locally on PostgreSQL 17/Redis 8. The fourth repair consumes the validated `{gym}` parameter before positional controller dispatch, moves gym access to trusted `TenantContext`, groups report dates by alias, repairs session-generation guard refresh, replaces unsupported MFA validators, and adds a narrowly scoped own-security-audit SELECT policy.
- Composer dependencies are now locked and `.env.testing` is present without secrets, preventing dependency drift and dotenv warning noise.
- The tracked API views directory lets the GitHub production-cache stage complete `config:cache`, `route:cache`, `view:cache` and `optimize:clear` even though the backend currently serves JSON only.
- Final local runtime result: **44 tests passed, 335 assertions, zero failures, skips, risky tests or warnings** under non-superuser forced RLS and Redis-backed cache/session/queue configuration. The GitHub-hosted rerun is the remaining confirmation.
