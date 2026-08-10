# IronCore architecture

> `MASTER_ARCHITECTURE_DOC.md` is the authoritative schema, endpoint, permission and active-branch record. This file is a concise topology overview.

IronCore is designed as a portable, multi-tenant SaaS. Every business record belongs to a gym tenant, while the platform owner operates across tenants through a separately authorised super-admin role.

## Production topology

- **Web/PWA:** React and Next.js with TypeScript. Mobile-first, installable, accessible and API-driven.
- **Application API:** Laravel, organised by business modules rather than page routes.
- **Primary database:** PostgreSQL with tenant-aware indexes, row ownership checks and read replicas when required.
- **Cache and jobs:** Redis for queues, rate limits, sessions, reporting caches and asynchronous payment/webhook work.
- **Files:** S3-compatible object storage for member documents, gym branding, invoices and exports.
- **Payments:** Provider adapters. Stripe Connect is the first card adapter where supported; regional gateways can be added without rewriting membership logic. Cash and manual records use the same auditable payment ledger.
- **Observability:** Structured logs, error tracking, uptime checks and per-tenant audit events.

## Tenant boundary

Every tenant-owned table carries a non-null `gym_id`. API queries are scoped through server-side tenant context; client-provided tenant identifiers are never trusted by themselves. Tenant-owned Eloquent models fail closed without context, composite foreign keys preserve tenant relationships, and PostgreSQL FORCE ROW LEVEL SECURITY enforces the same connection tenant. Super-admin access is explicit, separately authorised and audited.

High-volume tables such as payments, attendance events and audit logs use composite indexes beginning with `gym_id`. Time-based partitioning is introduced only after measured data volume requires it.

## Identity and roles

The roles are `super_admin`, `gym_owner`, `gym_manager`, `receptionist`, `trainer` and `member`. Authorisation is enforced in Laravel policies and tested at both request and domain-service level.

## Currency rules

Supported currencies are GBP, USD, PKR, AED and SAR. Each gym has one base currency. A user may change their display currency, but every financial record stores its original amount, ISO currency, provider amount, fees and settlement currency. Historical transactions are never silently recalculated when exchange rates change.

## Payment rules

Member payments and IronCore SaaS subscriptions are separate ledgers. Webhooks are signature-verified and idempotent. Manual cash payments and changes require an actor, timestamp, reason and before/after values. Payment secrets remain server-side and are never shipped in the downloadable source.

## Scale path

The initial target is 100 gyms and up to 1,000,000 member records. IronCore begins as a modular monolith so core transactions remain simple and reliable. Stateless web/API instances scale horizontally, while queues absorb imports, notifications, reports and provider callbacks.

The current backend includes branch/member/staff operations, role-safe invitations, immutable membership contracts, queued imports, separate member-payment and SaaS-billing ledgers, attendance/classes, assignment-bound coaching, append-only workout/progress history and tenant-bound notification jobs. Milestone 6 adds reporting, measured load/security hardening and production deployment operations.

## Reporting and operational readiness

Operational reports resolve one gym through the normal route/header tenant contract, use explicit tenant predicates plus Eloquent scope and forced RLS, accept at most 366 local-gym days and never combine currencies. Redis caches a filtered result for 60 seconds under a gym-prefixed key; the endpoint is rate-limited per user and selected gym.

Laravel exposes process liveness at `/up` and a generic PostgreSQL/Redis readiness check at `/api/v1/health/readiness`. The deployment runbook requires immutable releases, separate web/worker/scheduler processes, backup and restore evidence, signed-provider monitoring, centralised diagnostics and synthetic load tests that never use real member data.
