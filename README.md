# IronCore

IronCore is a multi-tenant gym-management SaaS for platform owners, gym teams and members. The repository contains a responsive role-aware web application and a Laravel API foundation covering identity, tenancy, gym operations, members, staff, memberships, payments, attendance, class bookings, trainer coaching, workout history, progress, notifications and operational reporting.

## Included in this milestone

- Super-admin dashboard and responsive navigation
- Dedicated gym-client portal with a selected-gym dashboard for members, live operations, collections, classes, team and subscription health
- Role-separated platform/gym shells with a representative preview switch for product review
- Gym, member, payment, SaaS billing, reporting, staff and settings views
- GBP, USD, PKR, AED and SAR display switching
- Search, chart-period controls and notifications
- Working new-gym onboarding interaction
- Realistic preview data for cross-region gyms, members and payments
- Tenant invoices with server-calculated totals and exact minor-unit money storage
- Cash, external-terminal, bank, other and hosted Stripe online payments
- Stripe Connect onboarding, signed webhook processing and partial/full refunds
- Role-aware financial workspace with payment, invoice and refund interactions
- Platform-owned SaaS plans with append-only monthly/yearly prices in all five currencies
- Tenant-isolated gym subscriptions, Stripe-hosted Checkout and customer-portal access
- Separate signed Stripe Billing webhook processing, dunning state and recurring invoice history
- Role-aware SaaS billing workspace for platform administrators, gym owners and managers
- Revocable member QR credentials with hash-only server storage and one-time plaintext display
- Branch-aware QR/member-code check-in, live attendance and authorised check-out
- Class scheduling, capacity-safe bookings, retained cancellation history and FIFO waitlist promotion
- Role-aware rosters, member self-booking and assigned-trainer attendance controls
- Active-assignment trainer boundaries and member-specific workout plans
- Ordered exercise prescriptions with exact gram-based targets and append-only completed sets
- Exact thousandths-based member progress history and trend visualisation
- Member-controlled notification preferences, quiet hours and encrypted destinations
- Tenant-bound Redis delivery jobs with email, SMS and push provider adapters
- Bounded, currency-specific tenant reports for member growth, revenue, attendance and class utilisation
- Tenant-keyed 60-second report caching, management-only access and per-user/gym throttling
- Owner/manager/receptionist member-portal invitations with tenant-bound hash-only activation values
- Safe account creation or existing-account linking, role-collision guards and regenerated member sessions
- Generic PostgreSQL/Redis readiness, a synthetic k6 report probe and committed secret scan
- Production deployment runbook, security launch checklist and recovery/monitoring gates
- Product architecture, phased delivery plan and environment template
- Automated build, rendered-output and product-contract tests

The signed-out preview uses representative in-browser data; authenticated screens use the Laravel API. Tenant-owned models fail closed, PostgreSQL RLS is forced, tenant foreign keys are composite, and money is stored as integer minor units. Member payments use each gym's connected Stripe account; IronCore subscriptions use the separate platform Stripe account so the two money flows never mix. Live Laravel/PostgreSQL and Stripe-provider execution remains a deployment/CI gate because this build workspace does not provide those runtimes or credentials.

## Run locally

Requirements: Node.js 22.13 or newer and npm.

```bash
npm install
npm run dev
```

Run all current quality checks:

```bash
npm run lint
npm test
```

`npm test` performs the production build, TypeScript type-check and all frontend/backend architecture contract tests. Laravel feature and live PostgreSQL RLS tests run in a PHP/Docker-capable CI or deployment environment.

## Important files

- `app/ironcore-dashboard.tsx` — product interface and interactions
- `app/gym-client-overview.tsx` — gym owner/manager landing dashboard composed from selected-tenant responses
- `app/engagement-management.tsx` — attendance, QR access, class scheduling and bookings
- `app/coaching-management.tsx` — trainer assignments, workout plans, progress and notification evidence
- `app/report-management.tsx` — tenant-safe operational reporting and date/currency controls
- `app/globals.css` — responsive design system
- `config/ironcore.config.json` — agreed currencies, roles and audit rules
- `docs/ARCHITECTURE.md` — production architecture and scale approach
- `MASTER_ARCHITECTURE_DOC.md` — mandatory architectural SSOT, schema and endpoint map
- `AGENTS.md` — repository enforcement rules for every coding session
- `docs/MILESTONES.md` — full delivery sequence
- `docs/DEPLOYMENT_RUNBOOK.md` — production release, rollback, recovery and monitoring procedure
- `docs/SECURITY_LAUNCH_CHECKLIST.md` — evidence-based production security gates
- `docs/MEMBER_ACCOUNT_ACTIVATION.md` — invitation operations, threat boundaries and runtime gate
- `.env.example` — non-secret environment template
- `backend/` — Laravel API, PostgreSQL migrations, authentication and tenancy
- `docker-compose.yml` — local PostgreSQL, Redis and API services

## Ownership and portability

The project source is portable and can be uploaded to your own Git repository or hosting environment. Do not commit live database credentials, payment keys or other secrets. Original transaction amounts and currencies must remain immutable in the production ledger.
