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
- Authentication, password recovery and optional MFA
- Super-admin and gym role policies
- Tenant-isolation and audit-log tests

## Milestone 3 — Gym and member operations
**Status: feature-complete; Laravel/PostgreSQL runtime gate pending**
- Gym onboarding and subscription setup
- Member profiles, plans, contracts and lifecycle states
- Staff invitations, permissions and CSV import
- Fail-closed Eloquent tenancy, forced PostgreSQL RLS and composite tenant foreign keys
- Tenant-prefixed private uploads and 500-row queued member-import batches

## Milestone 4 — Payments and SaaS billing
**Status: feature-complete; provider/runtime gate pending**
- Hosted Stripe Connect card collection and signed, idempotent provider webhooks
- Cash, external-terminal, bank and other payment records with audit history
- Tenant invoices, server-calculated totals, partial/full refunds and reconciliation summaries
- Platform-owned plans with immutable monthly/yearly prices across all five currencies
- Tenant-isolated gym subscriptions, hosted Checkout, customer portal and recurring invoice history
- Separate signed Stripe Billing webhook synchronization, trials, renewals and dunning
- Role-aware billing workspace for platform administrators, gym owners and read-only managers

## Milestone 5 — Attendance and engagement

**Status: feature-complete; Laravel/PostgreSQL/Redis and provider runtime gates pending**
- Revocable QR credentials, member-code check-in and live branch attendance
- Capacity-safe class scheduling, bookings, retained cancellations and FIFO waitlist promotion
- Role-aware member self-booking, rosters and assigned-trainer attendance
- Active-assignment trainer access and ordered member workout plans
- Append-only workout sessions, exact set loads and exact progress measurements
- Member notification preferences, quiet hours and encrypted destinations
- Tenant-bound Redis jobs with email, SMS and push adapters
- Role-aware responsive coaching, progress and notification-delivery workspace

## Milestone 6 — Reporting, hardening and deployment

**Milestone 6A status: feature-complete; Laravel/PostgreSQL/Redis, load and deployment gates pending**
- Bounded financial, member, attendance and class-utilisation reporting for one selected tenant
- Currency-specific aggregates, equal-length comparison periods, tenant-keyed Redis caching and management-only access
- Report and readiness throttles, generic PostgreSQL/Redis readiness, secret scan and synthetic k6 load probe
- Responsive Reports workspace with real authenticated API mode and isolated representative preview data
- Populated Branches, Membership Plans and Memberships preview navigation plus responsive attendance-table regression coverage
- Deployment/rollback/recovery runbook and evidence-based security launch checklist
- Remaining production gates: live Laravel/PostgreSQL RLS/Redis suite, measured k6 run, provider sandboxes, restore drill and monitored deployment
