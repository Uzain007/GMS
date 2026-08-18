# IronCore delivery milestones

Each milestone ends with build verification, focused logic tests and responsive interaction testing. One cumulative portable project archive is updated at verified checkpoints rather than publishing fragment ZIPs.

## Milestone 1 — Product foundation
**Status: complete**
- Responsive super-admin application shell
- Gym, member, payment, billing, reporting, team and settings navigation
- Multi-currency display for GBP, USD, PKR, AED and SAR
- Working search, chart periods, notifications and gym onboarding interaction
- Multi-tenant architecture, role and payment rules
- Automated build and rendered-output tests

## Milestone 2 — Laravel API and identity
**Status: complete; tenancy hardening completed in Milestone 3**
- Laravel modular API and PostgreSQL tenant migrations
- Sanctum authentication and platform identity; password recovery is completed in Milestone 9 and optional MFA in Milestone 10
- Super-admin and gym role policies
- Tenant-isolation and audit-log tests

## Milestone 3 — Gym and member operations
**Status: feature-complete; GitHub-hosted Laravel/PostgreSQL/Redis runtime passing**
- Gym onboarding and subscription setup
- Member profiles, plans, contracts and lifecycle states
- Staff invitations, permissions and CSV import
- Fail-closed Eloquent tenancy, forced PostgreSQL RLS and composite tenant foreign keys
- Tenant-prefixed private uploads and 500-row queued member-import batches

## Milestone 4 — Payments and SaaS billing
**Status: feature-complete; core runtime passing; provider sandbox gate pending**
- Hosted Stripe Connect card collection and signed, idempotent provider webhooks
- Cash, external-terminal, bank and other payment records with audit history
- Tenant invoices, server-calculated totals, partial/full refunds and reconciliation summaries
- Platform-owned plans with immutable monthly/yearly prices across all five currencies
- Tenant-isolated gym subscriptions, hosted Checkout, customer portal and recurring invoice history
- Separate signed Stripe Billing webhook synchronization, trials, renewals and dunning
- Role-aware billing workspace for platform administrators, gym owners and read-only managers

## Milestone 5 — Attendance and engagement

**Status: feature-complete; core runtime passing; provider adapter gates pending**
- Revocable QR credentials, member-code check-in and live branch attendance
- Capacity-safe class scheduling, bookings, retained cancellations and FIFO waitlist promotion
- Role-aware member self-booking, rosters and assigned-trainer attendance
- Active-assignment trainer access and ordered member workout plans
- Append-only workout sessions, exact set loads and exact progress measurements
- Member notification preferences, quiet hours and encrypted destinations
- Tenant-bound Redis jobs with email, SMS and push adapters
- Role-aware responsive coaching, progress and notification-delivery workspace

## Milestone 6 — Reporting, hardening and deployment

**Milestone 6A status: feature-complete; core runtime and synthetic load gate passing; production deployment gate pending**
- Bounded financial, member, attendance and class-utilisation reporting for one selected tenant
- Currency-specific aggregates, equal-length comparison periods, tenant-keyed Redis caching and management-only access
- Report and readiness throttles, generic PostgreSQL/Redis readiness, secret scan and synthetic k6 load probe
- Responsive Reports workspace with real authenticated API mode and isolated representative preview data
- Populated Branches, Membership Plans and Memberships preview navigation plus responsive attendance-table regression coverage
- Deployment/rollback/recovery runbook and evidence-based security launch checklist
- Remaining production gates: deployment-topology capacity/saturation run, provider sandboxes, provider PITR/RPO/RTO evidence and monitored deployment

## Milestone 7 — Linked-member portal

**Status: feature-complete; GitHub-hosted core runtime passing**
- Dedicated mobile-first member shell for profile, membership, payments, attendance, classes, training, progress and preferences
- Server-resolved member identity from the authenticated user link
- One-time QR pass rotation with hash-only persistence and navigation clearing
- Standalone PWA manifest without an unreviewed offline data cache

## Milestone 8 — Secure member account activation

**Status: feature-complete; GitHub-hosted core runtime passing**
- Tenant staff can invite an existing, unlinked member record to activate portal access
- One-time opaque activation values are returned once and retained only as tenant-scoped SHA-256 digests
- Acceptance atomically creates or links the platform user, adds the member tenant role and consumes the invitation
- Activation fragments are removed immediately and never enter referrers, analytics or browser persistence
- Existing accounts keep their password; staff and platform-admin role collisions are rejected rather than downgraded
- 46 architecture/product contracts, production build, type-check, lint, artifact validation, secret scan and browser interaction QA pass

## Milestone 9 — Account security and recovery

**Status: feature-complete; core runtime passing; mail sandbox gate pending**
- Non-enumerating password recovery with one-time, expiry-bound reset tokens
- Strong authenticated password change for every platform and tenant role
- Monotonic session-generation checks across Redis/database sessions and explicit Sanctum token revocation
- Fragment-only reset handoff with immediate browser-address cleanup and no credential persistence
- Row-locked credential changes, 50 architecture/product contracts, production build, type-check, lint, artifact validation, secret scan and browser interaction QA pass

## Milestone 10 — Multi-factor authentication

**Status: feature-complete; GitHub-hosted core runtime passing**
- Optional RFC 6238 authenticator MFA for every platform, tenant-staff and member identity
- Encrypted 160-bit secrets, row-locked non-replayed TOTP counters and eight one-time recovery codes
- Application-keyed recovery-code digests with one-time plaintext enrollment/regeneration responses
- Five-minute, five-attempt Redis login challenges that bind the user's current authentication generation
- MFA enforcement across password login, password-reset completion and existing-member activation
- Account-security management for enrollment QR, recovery-code replacement and protected disablement
- 54 architecture/product contracts, production build, type-check, lint, artifact validation, secret scan and browser interaction QA

## Milestone 11 — Production CI runtime gate

**Status: complete on commit `79ed6ae`; both hosted jobs passing**
- Read-only GitHub Actions checks for pull requests, `main` pushes and manual dispatches
- Locked Node 22.13 web install, lint, type-check, secret scan, production dependency audit, portable contracts and deployable artifact validation
- PHP 8.3 Laravel execution against PostgreSQL 17 and Redis 8 service containers
- Disposable `ironcore_app` database role with explicit no-superuser, no-role/database-creation, no-inherit and no-RLS-bypass constraints
- Fail-on-skip runtime assertions for PostgreSQL, every `gym_id` table's forced RLS state and Redis-backed cache/session/queue configuration
- Composer dependency audit, production Laravel cache validation and weekly npm/Composer/GitHub Actions dependency review

## Milestone 12 — Member data export lifecycle

**Status: implementation complete; hosted S3-compatible runtime passing**
- Owner/manager/super-admin and linked-member request paths behind normal tenant authorization
- Redis-queued JSON generation with explicit tenant predicates and forced PostgreSQL RLS
- Private tenant-prefixed object storage, SHA-256 integrity evidence, authenticated no-store downloads and seven-day expiry
- Tenant-bound delayed byte deletion with request/audit metadata retained
- Erasure remains a launch-country policy decision because immutable financial and security evidence may require retention

## Milestone 13 — Hosted runtime-gate repair

**Status: complete on commit `79ed6ae`; GitHub-hosted runtime passing**
- Build and artifact validation now run before contracts that import rendered worker output
- Member-export forced RLS uses the shared `ironcore.current_gym_id` session setting with fail-closed empty handling
- Unsupported PHP setup input removed and regression contracts added for workflow order and tenant-setting consistency
- Cookie-authenticated Sanctum `TransientToken` instances no longer enter persisted bearer-token ID/deletion paths
- Tenant identity, membership and role middleware now precede implicit route-model binding, preventing PostgreSQL RLS from turning valid or role-denied tenant record routes into premature `404` responses
- Membership creation runtime coverage expects the API's intentional `201 Created` response
- The selected `{gym}` parameter is consumed into trusted `TenantContext` before positional controller dispatch, so nested member, staff, booking, training and export IDs reach the correct controller arguments
- PostgreSQL reporting groups by the selected local-date alias instead of duplicating timezone placeholders
- Stateful Sanctum credential rotation refreshes both session and request guards, MFA request rules use supported Laravel 13 validators, and users can read only their own whitelisted platform security audits under FORCE RLS
- A committed Composer lockfile and non-secret `.env.testing` marker make CI dependency resolution deterministic and eliminate dotenv warnings
- The complete local production-shaped gate passes 44 Laravel tests and 335 assertions with PostgreSQL 17, Redis 8, non-superuser `ironcore_app`, forced RLS and no warnings

## Milestone 14 — Application-security analysis

**Status: complete on commit `2ddc641`**
- Advanced CodeQL analysis for JavaScript/TypeScript application source and GitHub Actions workflows
- Pull-request, `main` push and weekly scheduled scans using the `security-extended` query suite
- Immutable reviewed action revisions, checkout credentials disabled and job-scoped SARIF upload permission
- PHP remains covered by parser validation, Laravel/PostgreSQL/Redis runtime tests, Composer audit and review because CodeQL does not support PHP
- Portable workflow contracts prevent permission, trigger, language and action-pin regressions
- The first hosted security workflow passed both `CodeQL (actions)` and `CodeQL (javascript-typescript)` analyses

## Milestone 15 — Deployed-web release verification

**Status: complete on commit `45cf343`; quality, security and deployed-web checks passing**
- Non-secret full Git SHA metadata proves which immutable frontend release is serving
- Main-push smoke waits for the Vercel alias to serve that exact SHA instead of accepting a stale deployment
- Six-hour scheduled and manual runs continuously verify HTTPS/HSTS, the reviewed IronCore shell, same-origin CSS/JavaScript and the install manifest
- The probe is pinned to an explicit public hostname, rejects credentials/query/fragment/private-host targets and sends no tenant or member data
- Portable contracts cover workflow triggers, least privilege, immutable actions, release metadata and target validation
- The first hosted smoke confirmed that Vercel served the exact `5f485f2` release with the reviewed HSTS, shell, asset and manifest contracts
- Rendered-output validation derives the expected marker from Vercel/GitHub build identity before the explicit local `development` fallback
- Authenticated API, provider, load, storage and restore-drill gates remain separate production requirements

## Milestone 16 — S3-compatible member-export runtime gate

**Status: complete on commit `7033e2b`; hosted runtime passing**
- Disposable S3-compatible service in the existing least-privilege backend CI lane
- Production `GenerateMemberDataExport` execution over the AWS SDK/Flysystem HTTP boundary
- Private tenant-prefixed object, exact SHA-256 digest and byte-count verification
- Production `PurgeMemberDataExport` execution with expired-byte deletion and retained audit metadata
- Non-secret emulator credentials only; production bucket encryption, access policy and lifecycle evidence remain deployment gates

## Milestone 17 — Synthetic PostgreSQL backup and restore drill

**Status: complete on commit `b5bb2d0`; hosted runtime passing**
- Two fixed synthetic gyms, assignments and members are written through non-superuser `ironcore_app` under normal tenant settings
- PostgreSQL 17 creates a custom-format archive and restores it into a fresh disposable database with ownership/ACL portability flags
- The restored connection must remain `NOSUPERUSER`, `NOINHERIT` and `NOBYPASSRLS`
- Every restored public `gym_id` table must retain RLS and FORCE RLS
- No-context reads fail closed, each selected gym sees exactly its own fixture, and an unrelated gym sees none
- Cleanup removes the restored database, archive and source fixtures even on failure
- Production encrypted backups, PITR, retention and measured RPO/RTO remain provider-environment gates

## Milestone 18 — Synthetic cached-report performance gate

**Status: complete on commit `066a4d6`; hosted quality gate passing**
- Pinned k6 1.7.1 runs inside the existing credential-free PostgreSQL/Redis backend lane
- One 500-member tenant and one unrelated tenant are created through non-superuser `ironcore_app`
- Sixteen ten-minute CI-only operators spread pressure without bypassing or weakening the production report throttle
- A warmed 60-second Redis report response must remain identical throughout the run, with p95 below 500 ms and p99 below 1,000 ms
- The same probe requires cross-tenant denial and zero committed access tokens or provider credentials
- Production-scale capacity and saturation testing remain a deployment-topology gate
- The hosted backend completed the PostgreSQL/Redis/S3 suite, restore drill and pinned k6 gate successfully

## Post-Milestone 18 — Deployed-release propagation hardening

**Status: implemented; hosted re-verification pending**
- The first `066a4d6` push smoke reached its former ten-minute deployment wait before Vercel published the matching release
- A subsequent credential-free smoke confirmed the exact commit, HTTP 200, HSTS, same-origin assets and valid install manifest
- The next push again proved that fifteen minutes was insufficient before Vercel later served the exact `5183f84` release
- The evidence-based bounded deployment wait is now thirty minutes inside a separate 35-minute job ceiling; authenticated API and production provider gates remain separate

## Milestone 19 — Production configuration preflight

**Status: implementation complete; web/security/deployed checks passing; backend rerun required**
- A fail-closed Laravel command validates resolved production configuration after cache generation and before migrations or traffic
- The API gate covers production/debug mode, application key strength, public HTTPS origins, exact cookie/CORS/Sanctum alignment, trusted proxies, PostgreSQL least privilege, encrypted database/Redis transport, Redis-backed cache/session/queues, private S3-compatible storage, Stripe signing/callback settings, delivering mail, production log routing and optional notification-adapter pairing
- A separate web-build preflight rejects representative demo mode, a missing or unsafe public API origin and a missing immutable full-SHA release identity
- Failure output names only configuration requirements and never prints a secret or configured value
- Automated tests cover a valid production shape, unsafe denial, cookie/origin mismatch, optional-adapter mismatch and secret-safe output
- Passing preflights does not claim provider connectivity, provider sandbox execution, backup/storage controls, monitoring, privacy approval, branch protection or production-capacity evidence
- The first hosted backend run stopped during Composer installation when GitHub codeload returned HTTP 429; the web, CodeQL and deployed-release checks passed and no IronCore backend assertion ran or failed

## Milestone 20 — Notification transport runtime gate

**Status: implementation complete; web/security/deployed checks passing; hosted backend stopped during dependency installation**
- Exercise queued password recovery through Redis and a disposable authenticated SMTP boundary
- Exercise tenant-bound email, SMS and push deliveries through Redis and loopback-only HTTPS endpoints with synthetic bearer credentials
- Prove provider request shapes, returned provider IDs, tenant isolation and preference enforcement without production data or provider access
- Sanitize transport/provider exceptions before retry and failed-job evidence can retain endpoint, response or destination details
- Keep selected-provider sandbox delivery, sender/domain approval, suppression/rate-limit behaviour, monitoring and live credentials as explicit deployment gates

## Milestone 21 — Stripe transport runtime gate

**Status: implementation complete; web/security/deployed checks passing; hosted backend stopped during dependency installation**
- Exercise Stripe Connect onboarding, account refresh, direct-charge Checkout and refunds through a disposable loopback-only HTTPS boundary
- Exercise platform product/price, customer, subscription Checkout and customer-portal requests without connected-account routing
- Verify distinct Connect and Billing webhook signatures, opaque account/customer tenant resolution, server-authored metadata and replay idempotency
- Prove cross-tenant metadata cannot mutate payment or subscription state and keep all evidence synthetic and credential-free
- Keep real Stripe test-mode onboarding, Checkout, refunds, portal, event delivery, monitoring and production credentials as explicit provider/deployment gates

## Milestone 22 — Hosted dependency-install resilience

**Status: complete; approved hosted run exhausted all four attempts before backend tests**
- Cache only Composer download archives under an immutable action and exact `composer.lock`-derived key; never cache generated `vendor` code
- Limit Composer's parallel HTTP requests and retry failed locked installs four times with bounded 15/30/60-second backoff
- Preserve `--prefer-dist`, non-interactive locked installation and fail closed after the final attempt without running `composer update` or clearing the reviewed cache
- Keep checkout credentials disabled and expose no GitHub token, production secret or provider credential to dependency scripts
- Add executable portable coverage for transient recovery, the final failure ceiling, exact arguments, cache scope and credential absence
- The approved `12276be` run passed web, security and deployed-web checks but the backend still stopped at dependency installation before any Laravel assertion

## Milestone 23 — Credential-isolated Composer prefetch

**Status: complete; hosted package prefetch passed and exposed a separate Laravel bootstrap defect**
- Supply GitHub Actions' ephemeral read-only token only to bounded Composer prefetch children with plugins and scripts disabled
- Strip `COMPOSER_AUTH` and every GitHub token variable before the separate normal Composer/Laravel activation child starts
- Keep the exact lockfile, `--prefer-dist`, reduced parallelism, lockfile-keyed archive cache and four-attempt backoff without caching `vendor`
- Fail activation immediately instead of retrying application or dependency-script defects as transport failures
- Add executable coverage for phase arguments, token isolation, safe environment retention, secret-free logs, recovery, exhaustion and activation failure
- The approved `48561a8` run downloaded and installed the complete locked graph, removed the workflow credential and reached normal Laravel activation; package discovery then failed because `bootstrap/app.php` resolved configuration before Laravel registered the config service

## Milestone 24 — Bootstrap-safe trusted proxy configuration

**Status: implementation complete locally; hosted re-verification pending approved push**
- Remove early configuration resolution from `bootstrap/app.php` so Composer package discovery and Laravel CLI startup can create the application safely
- Let Laravel's default trusted-proxy HTTP middleware resolve reviewed proxy IPs, CIDRs or the explicit provider wildcard after configuration is available
- Preserve the fail-closed production preflight for an empty or invalid `TRUSTED_PROXIES` boundary
- Add Laravel and portable regression coverage for wildcard normalization, invalid proxy rejection and a configuration-free bootstrap file
- Re-run the complete PostgreSQL/Redis/S3/provider/restore/load backend authority only after an approved commit and push
