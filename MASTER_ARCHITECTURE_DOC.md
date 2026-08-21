# IronCore Master Architecture Document (MAD)

> Single Source of Truth (SSOT) for the IronCore multi-tenant B2B2C gym-management SaaS.

## Document control

| Field | Value |
| --- | --- |
| MAD version | 0.35.0 — Member Code and secure camera QR check-in |
| Last verified | 21 August 2026 |
| Product | IronCore |
| Architecture | Laravel modular-monolith API + React/Next.js TypeScript web/PWA |
| Active branch | `main` |
| Active milestone | Post-Milestone 26 Member Code and camera QR check-in implemented locally; approval and production API acceptance remain pending |
| Scale target | At least 1,000,000 member records and thousands of gym branches |
| Supported currencies | GBP, USD, PKR, AED and SAR |

## Mandatory session gate

Before any source code is written, modified or suggested in a new session:

1. Read this file completely and confirm its version and active branch status.
2. Compare the proposed change against the tenancy, security, schema, API and permission rules below.
3. Never invent missing architecture. Record unresolved decisions in this document before implementation.
4. Add technical comments to generated code explaining tenant privacy or scale-sensitive decisions.
5. Update this document whenever a migration, relationship, endpoint, role permission or active feature status changes.

## Non-negotiable architecture

- **Backend:** Laravel 13 modular monolith, PHP 8.3+, REST API under `/api/v1`.
- **Frontend:** React 19, Next.js 16 and TypeScript; web/PWA first, native apps later.
- **Database:** PostgreSQL 17 is the production source of truth.
- **Cache and queues:** Redis for cache, rate limiting, sessions, queues and asynchronous work.
- **Files:** S3-compatible object storage; files are referenced by tenant-owned metadata, not stored in PostgreSQL rows.
- **Authentication:** Laravel Sanctum with server-side role and tenant authorization.
- **Tenant model:** Logical isolation. Every tenant-owned row has a non-null `gym_id` foreign key.
- **Deployment:** Frontend and API remain portable and independently deployable. Secrets never enter source control.

## Tenant isolation contract

### Table classification

| Classification | Rule | Current examples |
| --- | --- | --- |
| Platform-owned | No `gym_id`; accessible only through explicit platform authorization | `users`, `gyms`, `saas_plans`, `saas_plan_prices`, authentication tables |
| Tenant-owned | Non-null `gym_id`, foreign key to `gyms.id`, tenant-leading indexes and global query enforcement | `gym_user`; all operational, member-finance and gym-subscription tables |
| Hybrid audit | `gym_id` is required for tenant events and nullable only for genuine platform-level events | `audit_logs` |

### Enforcement rules

1. The authenticated user never gains access merely by supplying `gym_id` or `X-Gym-ID`.
2. `ResolveTenant` must resolve the gym, verify active membership or `super_admin`, bind `TenantContext`, and clear it after the request.
3. Every tenant-owned Eloquent model must use the shared `BelongsToGym` concern. Reads without tenant context must fail closed; creates must reject missing or mismatched tenants.
4. Every tenant route must run behind `auth:sanctum` and `tenant` middleware. Write routes also require a role/policy decision.
5. PostgreSQL Row-Level Security (RLS) is mandatory defence-in-depth for tenant-owned tables. Policies use the request-bound tenant setting and include both `USING` and `WITH CHECK` clauses. RLS is not considered complete until integration tests run against PostgreSQL.
6. Super administrators select an explicit gym context for tenant-owned records. Cross-tenant reporting uses dedicated, audited platform services and never disables scoping inside ordinary repositories/controllers.
7. Tenant cache keys, queue payloads, exports, object-storage paths and logs must include `gym_id`.
8. All cross-tenant denial paths return 403 or 404 without disclosing whether another gym's record exists.

### Current enforcement status

| Layer | Status | Evidence / required follow-up |
| --- | --- | --- |
| Authenticated tenant middleware | Active | `ResolveTenant` verifies gym membership |
| Role middleware and gym policy | Active | `RequireRole`, `GymPolicy` |
| Eloquent global tenant concern | Active and fail-closed | Missing context returns no rows; mismatched/moved tenant writes throw |
| Tenant-leading indexes | Active | Every Phase 3 operational index begins with `gym_id` unless explicitly global |
| PostgreSQL RLS | Implemented | Forced policies cover `gym_user`, audit, operational and import tables |
| PostgreSQL isolation integration test | Passing locally and on GitHub | Commit `79ed6ae` passed 44 Laravel tests and 335 assertions as non-superuser `ironcore_app` against PostgreSQL 17 forced RLS and Redis 8 |

## Active database schema

All identifiers are UUIDs unless explicitly stated. Timestamps are timezone-aware (`timestampTz`). Laravel pins every PostgreSQL connection to UTC so host-level database timezone settings cannot shift Eloquent date casts; gym and branch IANA timezones are applied only at business/UI boundaries.

### `users` — platform identity

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key |
| `name` | varchar(160) | no | — |
| `email` | varchar(254) | no | unique |
| `email_verified_at` | timestamp | yes | — |
| `password` | varchar | no | hashed cast |
| `auth_version` | unsigned integer | no | default 1; monotonic server-side session revocation generation |
| `mfa_secret` | encrypted text | yes | 160-bit Base32 TOTP secret; present during pending setup and never returned after confirmation |
| `mfa_confirmed_at` | timestamp | yes | non-null only after a valid authenticator code confirms enrollment |
| `mfa_last_used_step` | unsigned bigint | yes | highest accepted 30-second TOTP counter; prevents same-code replay under a row lock |
| `platform_role` | varchar(40) | yes | index; only `super_admin` is currently valid |
| `remember_token` | varchar(100) | yes | — |
| `created_at`, `updated_at` | timestamp | yes | Laravel timestamps |

### `password_reset_tokens` — platform authentication

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `email` | varchar(254) | no | primary key |
| `token` | varchar | no | one-way hashed by Laravel's reset broker; plaintext exists only in the email fragment |
| `created_at` | timestamp | yes | — |

### `sessions` — platform authentication session

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | varchar | no | primary key |
| `user_id` | uuid | yes | index |
| `ip_address` | varchar(45) | yes | — |
| `user_agent` | text | yes | — |
| `payload` | long text | no | — |
| `last_activity` | integer | no | index |

### `gyms` — tenant registry

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key |
| `name` | varchar(160) | no | — |
| `slug` | varchar(100) | no | unique |
| `legal_name` | varchar(200) | yes | — |
| `base_currency` | char/varchar(3) | no | default GBP; enum-cast |
| `country_code` | char(2) | no | ISO 3166-1 alpha-2 |
| `timezone` | varchar(80) | no | default `Europe/London` |
| `status` | varchar(30) | no | index; `trial`, `active`, `past_due`, `suspended`, `cancelled` |
| `trial_ends_at` | timestamp | yes | index |
| `settings` | jsonb | yes | non-relational tenant preferences only |
| `created_at`, `updated_at` | timestamp | yes | composite index `(status, created_at)` |

### `gym_user` — tenant role membership

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete; primary key position 1 |
| `user_id` | uuid | no | FK `users.id` cascade delete; primary key position 2 |
| `role` | varchar(40) | no | tenant role |
| `status` | varchar(30) | no | default `active` |
| `joined_at` | timestamp | yes | — |
| `created_at`, `updated_at` | timestamp | yes | — |

Indexes: primary `(gym_id, user_id)`, `(gym_id, role, status)`, `(user_id, status)`.

### `personal_access_tokens` — Sanctum token store

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | big integer | no | primary key |
| `tokenable_type`, `tokenable_id` | morph pair | no | UUID morph index |
| `name` | varchar | no | — |
| `token` | varchar(64) | no | unique hashed token |
| `abilities` | text | yes | — |
| `last_used_at` | timestamp | yes | — |
| `expires_at` | timestamp | yes | index |
| `created_at`, `updated_at` | timestamp | yes | — |

### `user_mfa_recovery_codes` — platform authentication recovery

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key |
| `user_id` | uuid | no | FK `users.id` cascade delete; index `(user_id, used_at)` |
| `code_hash` | char(64) | no | application-keyed HMAC-SHA-256; unique `(user_id, code_hash)` |
| `used_at` | timestamp | yes | set once under a row lock when the recovery code authenticates |
| `created_at`, `updated_at` | timestamp | yes | — |

Recovery-code plaintext is returned only in the enrollment/regeneration response, is never logged or persisted by the browser, and cannot be recovered from the database. The table is platform-owned and therefore has no `gym_id`.

### `audit_logs` — immutable security and change evidence

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key |
| `gym_id` | uuid | yes | FK `gyms.id`, null on tenant deletion; required for tenant events |
| `actor_id` | uuid | yes | FK `users.id`, null on user deletion |
| `event` | varchar(120) | no | — |
| `auditable_type` | varchar | yes | polymorphic subject type |
| `auditable_id` | uuid | yes | polymorphic subject id |
| `before_values` | encrypted text | yes | encrypted array cast |
| `after_values` | encrypted text | yes | encrypted array cast |
| `reason` | text | yes | mandatory for sensitive manual changes |
| `ip_address` | varchar(45) | yes | — |
| `user_agent` | varchar(500) | yes | — |
| `created_at` | timestamp | no | defaults to current timestamp |

Indexes: `(gym_id, created_at)`, `(gym_id, event, created_at)`, `(auditable_type, auditable_id)`, `(actor_id, created_at)`.

Tenant events remain visible only through the selected gym. A separate FORCE-RLS policy permits an authenticated actor to read only their own platform-level MFA enable, recovery-code-regeneration and disable events; it cannot expose another user's platform audit or any tenant audit row.

### `gym_branches` — physical/operational locations

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `name` | varchar(160) | no | index `(gym_id, status, name)` |
| `code` | varchar(50) | no | unique `(gym_id, code)` |
| `email` | varchar(254) | yes | — |
| `phone` | varchar(40) | yes | — |
| `timezone` | varchar(80) | yes | falls back to gym timezone |
| `address` | jsonb | yes | structured postal address |
| `status` | varchar(30) | no | `active`, `inactive` |
| `is_primary` | boolean | no | partial unique index allows one primary branch per gym |
| `created_at`, `updated_at` | timestamp | yes | index `(gym_id, is_primary)` |

### `members` — tenant member profiles

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `home_branch_id` | uuid | yes | composite FK `(gym_id, home_branch_id)` to branch |
| `user_id` | uuid | yes | FK `users.id`, null on delete; unique `(gym_id, user_id)` |
| `member_number` | varchar(50) | no | unique `(gym_id, member_number)` |
| `member_code` | char(6) | no | visible numeric reception lookup; unique `(gym_id, member_code)`; never a security identifier |
| `first_name`, `last_name` | varchar(100) | no | index `(gym_id, last_name, first_name)` |
| `email` | varchar(254) | yes | index `(gym_id, email)` |
| `phone` | varchar(40) | yes | — |
| `date_of_birth` | date | yes | — |
| `status` | varchar(30) | no | `lead`, `active`, `paused`, `cancelled`, `archived` |
| `joined_at`, `archived_at` | timestamp | yes | — |
| `metadata` | jsonb | yes | non-relational custom attributes |
| `created_at`, `updated_at` | timestamp | yes | indexes `(gym_id, status, created_at)`, `(gym_id, home_branch_id, status)` |

### `staff_profiles` — tenant employment profile

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `user_id` | uuid | no | FK `users.id` restrict delete; unique `(gym_id, user_id)` |
| `home_branch_id` | uuid | yes | composite tenant FK to `gym_branches` |
| `employee_number` | varchar(50) | no | unique `(gym_id, employee_number)` |
| `job_title` | varchar(120) | yes | — |
| `status` | varchar(30) | no | `active`, `suspended`, `inactive` |
| `hired_at`, `terminated_at` | date | yes | — |
| `permissions` | jsonb | yes | narrowly scoped overrides; role remains authoritative |
| `created_at`, `updated_at` | timestamp | yes | indexes `(gym_id, status, created_at)`, `(gym_id, home_branch_id, status)` |

### `staff_profile_branch` — staff branch assignment

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `gym_id` | uuid | no | FK `gyms.id`; primary key position 1 |
| `staff_profile_id` | uuid | no | composite tenant FK; primary key position 2 |
| `branch_id` | uuid | no | composite tenant FK; primary key position 3 |
| `is_primary` | boolean | no | default false |
| `created_at`, `updated_at` | timestamp | yes | index `(gym_id, branch_id)` |

### `staff_invitations` — secure staff onboarding

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `home_branch_id` | uuid | yes | composite tenant FK to branch |
| `invited_by` | uuid | no | FK `users.id` restrict delete |
| `email` | varchar(254) | no | partial unique `(gym_id, email)` while pending |
| `role` | varchar(40) | no | tenant roles only; never `super_admin` |
| `employee_number` | varchar(50) | no | partial unique per gym while pending |
| `job_title` | varchar(120) | yes | — |
| `token_hash` | char(64) | no | globally unique SHA-256; plaintext is never stored |
| `status` | varchar(30) | no | `pending`, `accepted`, `revoked`, `expired` |
| `expires_at`, `accepted_at` | timestamp | no/yes | index `(gym_id, status, expires_at)` |
| `metadata` | jsonb | yes | invitation-specific metadata |
| `created_at`, `updated_at` | timestamp | yes | index `(gym_id, email, status)` |

### `member_account_invitations` — secure member login activation

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `member_id` | uuid | no | composite tenant FK to `members` cascade delete |
| `invited_by` | uuid | no | authorised staff user FK; restrict delete |
| `accepted_user_id` | uuid | yes | platform user linked after acceptance; null on delete |
| `email` | varchar(254) | no | normalized invitation snapshot; must still match the member at acceptance |
| `token_hash` | char(64) | no | SHA-256 only; unique `(gym_id, token_hash)` |
| `status` | varchar(30) | no | `pending`, `accepted`, `revoked`, `expired` |
| `expires_at`, `accepted_at`, `revoked_at` | timestamp | mixed | bounded activation lifecycle |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading member/status and status/expiry indexes |

Only one pending invitation may exist per `(gym_id, member_id)`. Reissuing revokes the prior row under a lock and returns a new opaque token once. Acceptance uses the route gym plus token digest to bind the normal tenant context before any invitation/member lookup; no global unscoped token lookup is permitted. The token plaintext never enters the database, audit values, logs, query string or browser persistence.

### `membership_plans` — sellable tenant plan definition

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `branch_id` | uuid | yes | composite tenant FK; null means gym-wide |
| `name` | varchar(160) | no | index `(gym_id, status, name)` |
| `code` | varchar(50) | no | unique `(gym_id, code)` |
| `description` | text | yes | — |
| `billing_interval` | varchar(30) | no | `one_time`, `weekly`, `monthly`, `quarterly`, `yearly` |
| `interval_count` | small integer | no | default 1 |
| `price_amount_minor` | unsigned big integer | no | exact minor units |
| `currency` | char(3) | no | supported ISO currency |
| `joining_fee_minor` | unsigned big integer | no | default 0 |
| `duration_days` | unsigned integer | yes | fixed-term length |
| `trial_days` | unsigned small integer | no | default 0 |
| `status` | varchar(30) | no | `active`, `inactive`, `archived` |
| `terms` | jsonb | yes | current plan terms |
| `created_at`, `updated_at` | timestamp | yes | index `(gym_id, branch_id, status)` |

### `memberships` — immutable member contract snapshot

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `member_id` | uuid | no | composite tenant FK to `members` |
| `plan_id` | uuid | no | composite tenant FK to `membership_plans` |
| `branch_id` | uuid | yes | composite tenant FK to branch |
| `created_by` | uuid | no | FK `users.id` restrict delete |
| `status` | varchar(30) | no | `pending`, `active`, `paused`, `cancelled`, `expired` |
| `starts_at`, `ends_at`, `next_billing_at` | date | no/yes/yes | lifecycle dates |
| `price_amount_minor`, `joining_fee_minor` | unsigned big integer | no | accepted plan price snapshot |
| `currency` | char(3) | no | accepted ISO currency snapshot |
| `billing_interval`, `interval_count` | varchar/small integer | no | accepted schedule snapshot |
| `auto_renew` | boolean | no | default true |
| `cancelled_at` | timestamp | yes | — |
| `cancellation_reason` | text | yes | required for cancellation |
| `terms_snapshot` | jsonb | yes | immutable accepted terms |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading member/status, billing, plan and branch indexes |

Partial unique index `(gym_id, member_id)` permits only one `pending` or `active` membership at a time.

### `member_imports` — asynchronous CSV import tracking

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `requested_by` | uuid | no | FK `users.id` restrict delete |
| `original_name` | varchar(255) | no | display name only |
| `storage_disk`, `storage_path` | varchar | no | private tenant-prefixed object location |
| `status` | varchar(30) | no | `queued`, `processing`, `completed`, `failed` |
| `total_rows`, `processed_rows`, `success_rows`, `failure_rows` | unsigned big integer | no | progress counters |
| `errors` | jsonb | yes | first 100 bounded validation errors |
| `started_at`, `completed_at` | timestamp | yes | — |
| `created_at`, `updated_at` | timestamp | yes | indexes `(gym_id, status, created_at)`, `(gym_id, requested_by, created_at)` |

### `member_data_exports` — time-limited privacy export evidence

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `member_id` | uuid | no | composite tenant FK to `members`; index `(gym_id, member_id, created_at)` |
| `requested_by` | uuid | no | authenticated actor FK to `users` restrict delete |
| `status` | varchar(30) | no | `queued`, `processing`, `completed`, `failed`, `expired` |
| `storage_disk`, `storage_path` | varchar | yes | hidden private tenant-prefixed object location |
| `content_sha256`, `size_bytes` | char(64)/unsigned bigint | yes | integrity and bounded operational evidence |
| `failure_reason` | text | yes | bounded internal failure detail; never exposed by the API |
| `started_at`, `completed_at`, `expires_at` | timestamp | yes | queue, completion and seven-day retrieval lifecycle |
| `created_at`, `updated_at` | timestamp | yes | index `(gym_id, status, expires_at)` |

Export bytes are JSON on private S3-compatible storage under `gyms/{gym_id}/exports/members/`. Redis jobs carry immutable gym/export IDs, establish their own tenant context and retain explicit `gym_id` predicates while forced RLS remains active. Downloads require a currently authorised tenant request and use `private, no-store`. A delayed tenant job deletes bytes after seven days and retains request/audit metadata.

### `payment_gateway_accounts` — tenant payment-provider connection

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `provider` | varchar(30) | no | currently `stripe`; unique `(gym_id, provider)` |
| `provider_account_id` | varchar(120) | yes | opaque account reference; unique `(provider, provider_account_id)` for verified webhook resolution |
| `status` | varchar(30) | no | `pending`, `restricted`, `active`, `disabled` |
| `charges_enabled`, `payouts_enabled`, `details_submitted` | boolean | no | provider capability state |
| `country_code`, `default_currency` | char(2)/char(3) | no | tenant country and supported settlement preference |
| `requirements` | jsonb | yes | bounded onboarding requirement codes; no identity documents |
| `connected_at` | timestamp | yes | first fully enabled time |
| `created_at`, `updated_at` | timestamp | yes | index `(gym_id, status, updated_at)` |

Provider credentials are platform environment secrets and never columns. The only non-tenant-leading gateway index supports an HMAC-verified account lookup; a SELECT-only RLS policy exposes exactly the signed provider account, after which normal tenant context is mandatory.

### `invoices` — tenant member receivable

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `member_id` | uuid | no | composite tenant FK to `members` |
| `membership_id`, `branch_id` | uuid | yes | composite tenant FKs to membership and branch |
| `created_by` | uuid | no | FK `users.id` restrict delete |
| `number` | varchar(50) | no | unique `(gym_id, number)` |
| `status` | varchar(30) | no | `draft`, `open`, `paid`, `void`, `uncollectible` |
| `currency` | char(3) | no | supported ISO currency |
| `subtotal_amount_minor`, `tax_amount_minor`, `total_amount_minor` | unsigned bigint | no | server-calculated immutable invoice totals |
| `paid_amount_minor`, `due_amount_minor` | unsigned bigint | no | transactionally maintained balance |
| `issued_at`, `due_at`, `paid_at`, `voided_at` | timestamp | mixed | lifecycle timestamps |
| `notes`, `metadata` | text/jsonb | yes | bounded tenant detail |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading status/due, member, membership and branch indexes |

### `invoice_items` — immutable server-calculated lines

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `invoice_id` | uuid | no | composite tenant FK to `invoices` cascade delete |
| `description` | varchar(240) | no | line description |
| `quantity` | unsigned integer | no | validated 1–1000 |
| `unit_amount_minor`, `subtotal_amount_minor`, `tax_amount_minor`, `total_amount_minor` | unsigned bigint | no | server-recomputed integer amounts |
| `metadata` | jsonb | yes | bounded line detail |
| `created_at`, `updated_at` | timestamp | yes | index `(gym_id, invoice_id, created_at)` |

### `payments` — append-oriented tenant payment ledger

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `member_id` | uuid | no | composite tenant FK to member |
| `membership_id`, `invoice_id`, `branch_id` | uuid | yes | composite tenant FKs |
| `recorded_by` | uuid | no | FK `users.id` restrict delete |
| `receipt_number` | varchar(50) | no | unique `(gym_id, receipt_number)` |
| `provider` | varchar(30) | no | `manual` or `stripe` |
| `method` | varchar(30) | no | `cash`, `card`, `bank_transfer`, `online_card`, `other` |
| `status` | varchar(30) | no | `pending`, `succeeded`, `failed`, `partially_refunded`, `refunded`, `voided` |
| `amount_minor`, `refunded_amount_minor` | unsigned bigint | no | exact minor-unit ledger amounts |
| `currency` | char(3) | no | ISO currency; must match linked invoice |
| `idempotency_key` | varchar(120) | no | unique per gym; prevents duplicate operator submissions |
| `provider_checkout_id`, `provider_payment_id` | varchar(180) | yes | opaque references, unique inside `(gym_id, provider)` |
| lifecycle/failure/notes/metadata fields | mixed | yes | no PAN, CVC or bank credentials |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading status, member, invoice, method and branch indexes |

Manual card means an externally processed terminal payment; IronCore never accepts or stores raw card data. Online card checkout uses Stripe-hosted Checkout on the gym's connected account.

### `payment_refunds` — immutable refund evidence

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key; unique `(gym_id, id)` |
| `gym_id` | uuid | no | FK `gyms.id` cascade delete |
| `payment_id` | uuid | no | composite tenant FK to payment |
| `recorded_by` | uuid | no | FK `users.id` restrict delete |
| `status` | varchar(30) | no | `pending`, `succeeded`, `failed` |
| `amount_minor`, `currency` | unsigned bigint/char(3) | no | cannot exceed unrefunded settled amount |
| `provider_refund_id` | varchar(180) | yes | unique per gym |
| `reason`, lifecycle/failure fields | mixed | mixed | reason mandatory; audit event mandatory |
| `created_at`, `updated_at` | timestamp | yes | indexes `(gym_id, payment_id, created_at)`, `(gym_id, status, created_at)` |

### `payment_webhook_events` — idempotent provider event evidence

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant-owned identity and gym FK |
| `provider`, `provider_account_id`, `provider_event_id`, `event_type` | varchar | no | unique `(gym_id, provider, provider_event_id)` |
| `payload_hash` | char(64) | no | SHA-256 evidence; raw payer payload is not retained |
| `status`, `processed_at`, `error` | mixed | mixed | retry-safe processing state |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading status and event-type indexes |

### `saas_plans` — platform-owned IronCore product tiers

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id` | uuid | no | primary key |
| `code`, `name` | varchar | no | code globally unique; name is customer-facing |
| `description` | text | yes | bounded product copy |
| `status` | varchar(30) | no | `draft`, `active`, `archived`; indexed with sort order |
| `feature_limits` | jsonb | no | platform-authored limits such as members, branches and staff |
| `sort_order` | unsigned small integer | no | deterministic catalogue order |
| `provider`, `provider_product_id` | varchar | mixed | Stripe product reference; globally unique when present |
| `created_at`, `updated_at` | timestamp | yes | — |

Plan feature limits are controlled only by `super_admin`. Archived plans remain available to historical subscription snapshots and are never hard-deleted through the API.

### `saas_plan_prices` — immutable platform prices

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `saas_plan_id` | uuid | no | primary key and FK to `saas_plans` restrict delete |
| `currency` | char(3) | no | GBP, USD, PKR, AED or SAR |
| `billing_interval` | varchar(20) | no | `monthly` or `yearly` |
| `amount_minor` | unsigned bigint | no | exact recurring amount in minor units |
| `trial_days` | unsigned small integer | no | 0–90 |
| `active` | boolean | no | inactive prices remain historical references |
| `provider`, `provider_price_id` | varchar | mixed | Stripe recurring Price; globally unique when present |
| `created_at`, `updated_at` | timestamp | yes | unique active catalogue key `(saas_plan_id, currency, billing_interval)` |

Prices are append-oriented. Changing an amount creates a new provider Price and deactivates the old price instead of rewriting historical contracts.

### `platform_billing_customers` — tenant Stripe Billing identity

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | primary key, tenant FK and unique `(gym_id, id)` |
| `provider`, `provider_customer_id` | varchar | no | unique gym/provider and global opaque provider identity |
| `billing_email`, `billing_name` | varchar | no/yes | current billing contact; no payment credentials |
| `country_code`, `default_currency` | char(2)/char(3) | no | billing locale and supported currency |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading updated index |

The global customer identifier supports only a signature-verified SELECT policy. The billing webhook immediately binds the resolved gym and returns to normal tenant RLS before reading or writing subscription data.

### `gym_subscriptions` — tenant IronCore subscription ledger

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity and gym FK |
| `billing_customer_id` | uuid | no | composite tenant FK to `platform_billing_customers` |
| `saas_plan_id`, `saas_plan_price_id` | uuid | no | platform catalogue FKs; restrict delete |
| `provider`, `provider_subscription_id` | varchar | no | globally unique opaque provider subscription |
| `status` | varchar(30) | no | `incomplete`, `trialing`, `active`, `past_due`, `unpaid`, `paused`, `cancelled`, `incomplete_expired` |
| plan/price/feature snapshot fields | mixed | no | immutable accepted tier, limits, amount, currency and interval |
| `current_period_start`, `current_period_end`, `trial_ends_at` | timestamp | mixed | access and renewal boundaries |
| `cancel_at_period_end`, `cancelled_at`, `ended_at` | mixed | mixed | cancellation lifecycle |
| `latest_invoice_id`, failure fields | varchar/text | yes | bounded dunning state; no card data |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading status/period indexes and one non-terminal subscription per gym |

### `subscription_checkout_sessions` — tenant checkout idempotency

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity and gym FK |
| `created_by` | uuid | no | actor FK to `users` restrict delete |
| `saas_plan_price_id` | uuid | no | platform price FK |
| `idempotency_key` | varchar(120) | no | unique `(gym_id, idempotency_key)` |
| `provider_session_id` | varchar(180) | no | globally unique hosted session reference |
| `status` | varchar(30) | no | `open`, `completed`, `expired` |
| `expires_at`, `completed_at` | timestamp | mixed | bounded checkout lifecycle |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading status/created index; one open checkout per gym |

### `saas_billing_invoices` — tenant recurring invoice snapshot

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity and gym FK |
| `billing_customer_id`, `gym_subscription_id` | uuid | no/yes | composite tenant FKs |
| `provider_invoice_id`, `number`, `status` | varchar | no/mixed/no | provider invoice unique globally; status `draft`, `open`, `paid`, `void`, `uncollectible` |
| `currency`, amount totals | char(3)/unsigned bigint | no | due, paid and remaining in exact minor units |
| `hosted_invoice_url`, `invoice_pdf_url` | text | yes | provider-hosted documents exposed only to billing-authorised roles |
| period/due/paid timestamps | timestamp | mixed | renewal and collection evidence |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading status/due and subscription indexes |

### `saas_billing_webhook_events` — tenant Stripe Billing event evidence

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity and gym FK |
| `provider_customer_id`, `provider_event_id`, `event_type` | varchar | no | unique `(gym_id, provider_event_id)` after customer resolution |
| `payload_hash` | char(64) | no | raw billing payload is not retained |
| `status`, `processed_at`, `error` | mixed | mixed | retry-safe processing evidence |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading status and event-type indexes |

### `member_access_credentials` — revocable QR access identities

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `member_id`, `issued_by` | uuid | no | composite tenant member FK and actor FK |
| `credential_hash` | char(64) | no | SHA-256 only; unique `(gym_id, credential_hash)` |
| `credential_hint` | varchar(12) | no | bounded non-secret suffix for support/audit display |
| `status` | varchar(30) | no | `active`, `revoked`, `expired` |
| `expires_at`, `last_used_at`, `revoked_at` | timestamp | yes | credential lifecycle only |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading member/status and expiry indexes; one active credential per member |

The opaque plaintext QR credential is returned once at issuance or rotation and is never stored. Scanning occurs only inside an already authenticated and resolved gym context; the server hashes the submitted value and queries `(gym_id, credential_hash)`, so a credential from another gym cannot reveal or resolve a member.

### `attendance_records` — member presence lifecycle

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `member_id`, `membership_id`, `branch_id` | uuid | no | composite tenant FKs proving eligible member, contract and location |
| `access_credential_id` | uuid | yes | composite tenant FK when admitted from QR |
| `checked_in_by`, `checked_out_by` | uuid | no/yes | authenticated operator FKs |
| `method` | varchar(30) | no | `qr`, `member_code`, `manual` |
| `status` | varchar(30) | no | `checked_in`, `checked_out` |
| `checked_in_at`, `checked_out_at` | timestamp | no/yes | immutable admission time and controlled close time |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading branch/time, member/time and status/time indexes |

A PostgreSQL partial unique index permits one open `checked_in` row per `(gym_id, member_id)`. Check-in locks and validates the member's active, in-date membership and branch eligibility before admission. High-volume attendance lists use cursor pagination and time-bounded filters.

### `class_sessions` — scheduled branch classes

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `branch_id` | uuid | no | composite tenant FK to the hosting branch |
| `trainer_staff_profile_id` | uuid | yes | composite tenant FK to the assigned trainer |
| `created_by` | uuid | no | authenticated actor FK |
| `title`, `description` | varchar/text | no/yes | bounded customer-facing class details |
| `starts_at`, `ends_at` | timestamp | no | indexed session window; end must be after start |
| `capacity` | unsigned small integer | no | 1–1000 |
| booking/waitlist/attendance counters | unsigned integer | no | transactionally maintained under a session row lock |
| `next_waitlist_sequence` | unsigned bigint | no | monotonic FIFO sequence assigned under the same lock |
| `waitlist_enabled` | boolean | no | controls capacity overflow behavior |
| `booking_opens_at`, `booking_closes_at` | timestamp | yes | optional booking window |
| `status`, `cancellation_reason` | varchar/text | no/yes | `scheduled`, `cancelled`, `completed`; cancellation requires a reason |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading branch/start, trainer/start and status/start indexes |

### `class_bookings` — capacity-safe booking and waitlist history

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `class_session_id`, `member_id`, `membership_id` | uuid | no | composite tenant FKs to session, member and eligible contract |
| `booked_by` | uuid | no | staff actor or linked member user FK |
| `status` | varchar(30) | no | `booked`, `waitlisted`, `cancelled`, `attended`, `no_show` |
| `waitlist_sequence` | unsigned bigint | yes | immutable FIFO order for waitlisted rows |
| booking/promotion/cancellation/attendance timestamps | timestamp | mixed | append-preserving lifecycle evidence |
| `cancellation_reason` | text | yes | mandatory for staff cancellation; bounded for member cancellation |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading session/status/FIFO, member/status/time indexes |

A PostgreSQL partial unique index allows only one active booking or waitlist entry for a member/session while retaining cancelled history. Booking, cancellation, counter updates and promotion of the earliest waitlisted record occur in one transaction while the session row is locked.

### `trainer_member_assignments` — explicit coaching access boundary

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `trainer_staff_profile_id`, `member_id` | uuid | no | composite tenant FKs to trainer and member |
| `assigned_by` | uuid | no | authorised owner/manager/super-admin user FK |
| `status` | varchar(30) | no | `active`, `inactive` |
| `starts_on`, `ends_on` | date | no/yes | bounded coaching period |
| `notes` | text | yes | bounded operational context; no medical diagnosis |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading trainer/status and member/status indexes |

A PostgreSQL partial unique index permits only one active assignment per `(gym_id, trainer_staff_profile_id, member_id)`. Trainers may access training/progress records only for members with a current active assignment; a client-supplied member ID never expands access. Owners/managers may end an assignment through a reasoned, audited transition from `active` to `inactive`; records are never deleted or reactivated through the API and trainer access closes immediately.

### `workout_plans` — member-specific trainer prescription

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `member_id`, `trainer_staff_profile_id` | uuid | no | composite tenant FKs to assigned member/trainer |
| `created_by` | uuid | no | authenticated actor user FK |
| `title`, `goal`, `notes` | varchar/text | mixed | bounded coaching content |
| `starts_on`, `ends_on` | date | no/yes | plan lifecycle window |
| `status` | varchar(30) | no | `draft`, `active`, `completed`, `cancelled` |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading member/status/start and trainer/status/start indexes |

At most one active plan is permitted per `(gym_id, member_id)` by partial unique index. Exercise prescriptions are created transactionally with the plan and remain historical evidence once the plan is active.

### `workout_plan_exercises` — ordered exercise prescription

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `workout_plan_id` | uuid | no | composite tenant FK to plan cascade delete while draft |
| `name`, `instructions` | varchar/text | no/yes | bounded trainer-authored content |
| `day_number`, `sort_order` | unsigned small integer | no | unique ordered position inside plan day |
| target sets/reps fields | unsigned small integer | mixed | minimum/maximum repetition prescription |
| `target_load_grams` | unsigned big integer | yes | exact integer mass; no floating-point weights |
| duration/rest fields | unsigned integer | mixed | exact seconds for timed work and recovery |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading plan/day/order index |

### `workout_sessions` and `workout_set_logs` — append-only completion evidence

`workout_sessions` stores tenant-owned plan/member/logging-user IDs, `performed_at`, exact `duration_seconds`, bounded notes and timestamps. `workout_set_logs` stores the tenant-owned session/exercise IDs, set order, reps, exact `load_grams`, duration, distance metres and 1–10 RPE. Composite tenant FKs and unique `(gym_id, workout_session_id, workout_plan_exercise_id, set_number)` prevent cross-plan or duplicate-set evidence. Completed sessions and sets are append-only through the API and use cursor pagination by `(gym_id, member_id, performed_at)`.

### `member_progress_measurements` — append-only metric history

| Column | Type | Null | Constraints / index |
| --- | --- | --- | --- |
| `id`, `gym_id` | uuid | no | tenant identity, gym FK and unique `(gym_id, id)` |
| `member_id`, `recorded_by` | uuid | no | composite member FK and authenticated actor FK |
| `metric` | varchar(40) | no | controlled metric key such as `body_weight`, `body_fat`, `waist`, `chest`, `custom` |
| `value_milli`, `unit` | signed big integer/varchar(20) | no | exact thousandths plus controlled unit; no floating-point storage |
| `measured_at`, `note` | timestamp/text | no/yes | chronological evidence and bounded context |
| `created_at`, `updated_at` | timestamp | yes | tenant-leading member/metric/time index |

Measurements are appended rather than edited in place. Member self-service and assigned trainers can record/read only the linked member; owners/managers may operate inside the selected tenant.

### `notification_preferences` and `notification_deliveries` — queued communication

`notification_preferences` is tenant-owned and unique per member. It stores email/SMS/push enablement, class/workout/payment reminder choices, explicit marketing opt-in, quiet-hours and timezone. `notification_deliveries` is tenant-owned append-only evidence containing member, optional triggering user, channel (`email`, `sms`, `push`), template key, encrypted destination, safe bounded JSON variables, tenant idempotency key, status (`queued`, `sending`, `sent`, `failed`, `suppressed`), attempts, provider reference, failure detail and lifecycle timestamps. Destination plaintext is never returned by API resources or written to logs.

Notification delivery runs only on Redis queues. Each job carries immutable `gym_id` and delivery ID, establishes tenant context, honours current preferences/quiet hours, selects a server-configured adapter and clears context in `finally`. Email uses Laravel Mail; SMS and push use configured HTTPS adapters. Provider credentials remain environment secrets and delivery payload variables never grant tenant authority.

### Operational reporting read model — bounded tenant aggregates

Milestone 6A adds no durable reporting table. `ReportService` builds a read model from existing tenant-owned members, memberships, invoices, payments, refunds, attendance, class sessions and bookings while normal Eloquent scoping and forced PostgreSQL RLS remain active.

- Reports are always for one explicitly selected gym. Super administrators use the same tenant selection route/header contract as gym staff; ordinary reporting never disables scoping or loops across gyms.
- A report accepts inclusive local-gym `from`/`to` dates, is limited to 366 days, and converts those dates to a half-open UTC range using the gym timezone before querying timestamp columns.
- Money is aggregated only for one requested supported ISO currency. IronCore never performs implicit conversion or combines currencies.
- The overview includes current active members and outstanding balance; period and immediately preceding comparison metrics for new member profiles, net collected revenue, attendance visits and class utilisation; daily member/attendance/revenue series; current member-status distribution; period payment-method mix; and period class totals.
- Each aggregate query retains an explicit `gym_id` predicate even though the fail-closed Eloquent scope and PostgreSQL RLS also enforce the tenant. Date/status/currency predicates remain compatible with tenant-leading indexes.
- Reporting adds tenant-leading indexes `(gym_id, created_at)` on members, `(gym_id, cancelled_at)` on memberships, `(gym_id, currency, status, paid_at)` on payments, `(gym_id, currency, status, refunded_at)` on refunds, `(gym_id, currency, status)` on invoices and `(gym_id, checked_in_at)` on attendance. No reporting index or query starts from an unscoped date/currency column.
- Responses are cached for at most 60 seconds under `ironcore:gym:{gym_id}:reports:overview:{sha256(filters)}`. The gym identifier is never omitted from a report cache key.
- The endpoint is limited to 30 requests per authenticated user and selected gym per minute. Cache population occurs only after tenant middleware and role authorization have succeeded.

### Queue evidence tables

- `job_batches` uses Laravel's standard batch counters/options schema.
- `failed_jobs` uses Laravel's UUID, connection, queue, payload, exception and failure timestamp schema.
- Active jobs remain in Redis. Queue payloads contain immutable `gym_id` and record IDs; jobs establish and clear tenant context themselves.

### MFA login challenge cache

- A correct password for an MFA-enabled account creates a 256-bit opaque challenge returned only in the JSON response and held only in browser component memory.
- Redis stores the challenge for at most five minutes under `ironcore:auth:mfa:challenge:{sha256(challenge)}` with user ID, current `auth_version`, requested authentication mode, bounded device name, expiry and failed-attempt count.
- The plaintext challenge is never a cache key or database value. Verification is serialized with a cache lock, limited to five failed codes and deleted before a session or bearer token is issued.
- Password reset, MFA enablement/disablement and any other authentication-generation change invalidate older challenges because the cached generation must equal the locked user row.

## Active relationships

- `Gym belongsToMany User` through `gym_user` with `role`, `status`, `joined_at` and timestamps.
- `User belongsToMany Gym` through `gym_user`.
- `User hasMany UserMfaRecoveryCode`; recovery rows are platform-owned one-time authentication evidence and never tenant data.
- `Gym hasMany GymBranch`, `Member`, `StaffProfile`, `MembershipPlan`, `MemberImport` and `MemberDataExport`.
- `GymBranch belongsTo Gym` and has many home members and branch-specific membership plans.
- `Member belongsTo Gym`, optionally belongs to a home branch and platform user, and has many memberships and time-limited data exports.
- `StaffProfile belongsTo User` and optionally a home branch; it belongs to many branches through tenant-owned `staff_profile_branch`.
- `StaffInvitation belongsTo invitedBy User` and optionally a home branch. Acceptance creates/updates `gym_user` and `staff_profiles` atomically.
- `MemberAccountInvitation belongsTo Member`, its inviting User and optional accepted User. Acceptance creates/updates the member-role `gym_user` row and links `members.user_id` atomically inside the invitation's tenant context.
- `MembershipPlan optionally belongsTo GymBranch` and has many memberships.
- `Membership belongsTo Member`, `MembershipPlan`, optional branch and creating user; every operational relationship is protected by a composite tenant FK.
- `MemberImport belongsTo requestedBy User`; its queued job carries gym/import IDs and resets tenant context after processing.
- `MemberDataExport belongsTo Member and requestedBy User`; generation and expiry jobs carry gym/export IDs and reset tenant context after processing.
- `PaymentGatewayAccount belongsTo Gym`; only a verified provider signature may use its opaque lookup policy before the service binds normal tenant context.
- `Invoice belongsTo Member`, optional Membership and Branch, and has many tenant-scoped InvoiceItems and Payments.
- `Payment belongsTo Member`, optional Membership, Invoice and Branch, and has many immutable PaymentRefund records.
- `PaymentWebhookEvent` is tenant-owned idempotency evidence and retains only a payload hash.
- `SaasPlan hasMany SaasPlanPrice`; both are platform-owned catalogue records and have no tenant data.
- `PlatformBillingCustomer belongsTo Gym` and has many tenant-scoped subscriptions and SaaS invoices.
- `GymSubscription belongsTo PlatformBillingCustomer`, `SaasPlan` and `SaasPlanPrice`; accepted plan, feature and price values are immutable snapshots.
- `SubscriptionCheckoutSession belongsTo its creating User` and platform price while remaining tenant-owned through non-null `gym_id`.
- `SaasBillingInvoice belongsTo PlatformBillingCustomer` and optionally a tenant-scoped GymSubscription.
- `SaasBillingWebhookEvent` is tenant-owned idempotency evidence and retains only a payload hash.
- `MemberAccessCredential belongsTo Member`; it stores only an opaque credential hash and supports one active credential per member.
- `AttendanceRecord belongsTo Member`, active Membership, GymBranch and optional MemberAccessCredential; every relationship uses the same tenant key.
- `ClassSession belongsTo GymBranch`, optional trainer StaffProfile and creating User, and has many tenant-scoped ClassBookings.
- `ClassBooking belongsTo ClassSession`, Member and active Membership; cancellation preserves history and promotes the earliest waitlisted booking transactionally.
- `TrainerMemberAssignment belongsTo StaffProfile trainer and Member`; it is the server-authoritative boundary for trainer access.
- `WorkoutPlan belongsTo Member and assigned StaffProfile trainer`, has many ordered WorkoutPlanExercises and many append-only WorkoutSessions.
- `WorkoutSession belongsTo WorkoutPlan and Member` and has many WorkoutSetLogs; every session/exercise relation repeats the same tenant key.
- `MemberProgressMeasurement belongsTo Member` and its recording User; measurements are append-only chronological evidence.
- `NotificationPreference belongsTo Member`; `NotificationDelivery belongsTo Member` and optionally its triggering User while retaining encrypted destination evidence.
- `AuditLog` records an optional actor (`users.id`), optional gym (`gyms.id`) and polymorphic subject.
- Tenant deletion cascades role memberships but preserves audit evidence by nulling `audit_logs.gym_id`.

## Active API endpoint map

All successful JSON payloads are versioned under `/api/v1`.

| Method | Endpoint | Middleware / permission | Purpose |
| --- | --- | --- | --- |
| POST | `/auth/login` | login throttle + stateful Sanctum middleware for browser origins | Start an encrypted server-side web session by default; issue a scoped bearer token only when a native client explicitly sends `use_bearer_token: true` |
| POST | `/auth/mfa/challenge` | public MFA-challenge throttle; opaque five-minute challenge | Complete a pending login with one non-replayed TOTP value or consume one recovery code before any session/token is issued |
| POST | `/auth/forgot-password` | recovery throttle; public | Always acknowledge a syntactically valid normalized email identically, while Laravel conditionally sends a one-time reset fragment |
| POST | `/auth/reset-password` | recovery throttle + stateful browser origin | Consume a valid one-time reset token, replace the password, advance `auth_version`, revoke all bearer tokens and start one regenerated web session |
| GET | `/auth/me` | `auth:sanctum`, authentication-version gate, database identity | Return authenticated identity and active gym roles |
| PATCH | `/auth/password` | `auth:sanctum`, authentication-version gate, database identity | Verify the current password, replace it, advance `auth_version`, revoke other credentials and retain only the current authenticated context |
| GET | `/auth/mfa` | `auth:sanctum`, authentication-version gate, database identity | Return only MFA enabled/pending state, confirmation time and remaining recovery-code count |
| POST | `/auth/mfa/setup` | authenticated + MFA-management throttle + current password | Replace a pending unconfirmed secret and return its Base32/otpauth values once; an enabled factor must be disabled before replacement |
| POST | `/auth/mfa/confirm` | authenticated + MFA-management throttle | Confirm the pending secret with a non-replayed TOTP value, issue recovery codes once and revoke every other credential |
| POST | `/auth/mfa/recovery-codes` | authenticated + MFA-management throttle + current password and TOTP | Replace all unused recovery codes and return the new plaintext set once |
| DELETE | `/auth/mfa` | authenticated + MFA-management throttle + current password and TOTP/recovery code | Remove the factor and recovery codes, advance `auth_version`, and revoke every other credential |
| POST | `/auth/logout` | `auth:sanctum`, authentication-version gate, database identity | Invalidate the current web session or revoke the current bearer token |
| GET | `/health/readiness` | public, generic response, `health` throttle | Verify Laravel can reach PostgreSQL and Redis without exposing configuration or tenant data |
| GET | `/gyms` | authentication, database identity, `GymPolicy::viewAny` | List all gyms for super admin or active assigned gyms for a tenant user |
| POST | `/gyms` | `auth:sanctum`, `super_admin`, `GymPolicy::create` | Create a trial gym and owner membership |
| GET | `/gyms/{gym}` | `auth:sanctum`, `tenant`, `GymPolicy::view` | Read one authorised gym |
| PATCH | `/gyms/{gym}` | `auth:sanctum`, `tenant`, manager role, `GymPolicy::update` | Update gym identity/settings with audit reason |
| GET/POST | `/gyms/{gym}/branches` | tenant; reads all tenant roles, writes owner/manager | List or create branches |
| GET/PATCH | `/gyms/{gym}/branches/{branch}` | tenant; reads all tenant roles, writes owner/manager | Read or update a tenant-resolved branch |
| GET/POST | `/gyms/{gym}/members` | tenant; owner/manager/receptionist | List/search or create member profiles |
| GET/PATCH | `/gyms/{gym}/members/{member}` | tenant; owner/manager/receptionist | Read or update a tenant-resolved member |
| GET/POST | `/gyms/{gym}/members/{member}/account-invitations` | tenant; owner/manager/receptionist | Read bounded activation history or revoke/reissue a one-time member account invitation |
| GET/POST | `/gyms/{gym}/members/{member}/data-exports` | tenant; owner/manager/super admin | List or queue a private seven-day member-data export |
| GET | `/gyms/{gym}/members/{member}/data-exports/{export}[/download]` | tenant; owner/manager/super admin | Read safe export state or stream the authorised private JSON document |
| POST | `/gyms/{gym}/member-account-invitations/preview` | public activation throttle; route gym + opaque fragment token in request body | Return only gym name, member first name, masked email and whether an existing account will be linked |
| POST | `/gyms/{gym}/member-account-invitations/accept` | public activation throttle; route gym + opaque fragment token | Atomically create or link the invited email's platform user, add member tenant access, consume the token and start a regenerated web session |
| GET/POST | `/gyms/{gym}/member-imports` | tenant; owner/manager/receptionist | List imports or queue a private CSV upload |
| GET | `/gyms/{gym}/member-imports/{import}` | tenant; owner/manager/receptionist | Poll import counters and bounded errors |
| GET | `/gyms/{gym}/staff` | tenant; owner/manager | List staff with role using a tenant-keyed join |
| GET/PATCH | `/gyms/{gym}/staff/{staff}` | tenant; owner/manager | Read/update staff; reason required; manager cannot promote owner/manager |
| GET/POST | `/gyms/{gym}/staff-invitations` | tenant; owner/manager | List pending invitations or create a one-time hashed invitation |
| POST | `/gyms/{gym}/staff-invitations/accept` | authenticated user + matching email/token | Accept before membership; service explicitly binds token's gym context |
| GET/POST | `/gyms/{gym}/membership-plans` | tenant; reads all tenant roles, writes owner/manager | List or create plan definitions |
| GET/PATCH | `/gyms/{gym}/membership-plans/{plan}` | tenant; reads all tenant roles, writes owner/manager | Read/update plan; existing contracts remain unchanged |
| GET/POST | `/gyms/{gym}/memberships` | tenant; owner/manager/receptionist | List or create snapshotted member contracts |
| GET/PATCH | `/gyms/{gym}/memberships/{membership}` | tenant; owner/manager/receptionist | Read/update lifecycle; cancellation reason required |
| GET/POST | `/gyms/{gym}/invoices` | tenant; owner/manager/receptionist | List or issue server-calculated member invoices |
| GET | `/gyms/{gym}/invoices/{invoice}` | tenant; owner/manager/receptionist | Read invoice and tenant-scoped items |
| GET/POST | `/gyms/{gym}/payments` | tenant; owner/manager/receptionist | List or record cash, terminal/card, bank or hosted online payment |
| GET | `/gyms/{gym}/payments/summary` | tenant; owner/manager/receptionist | Currency-specific gross, refunds, net, pending and outstanding totals |
| GET | `/gyms/{gym}/payments/{payment}` | tenant; owner/manager/receptionist | Read a payment and its refunds |
| POST | `/gyms/{gym}/payments/{payment}/refunds` | tenant; owner/super admin | Create a reasoned, audited partial or full refund |
| GET | `/gyms/{gym}/payment-gateways/stripe` | tenant; owner/manager/super admin | Read safe Stripe connection/capability state |
| POST | `/gyms/{gym}/payment-gateways/stripe/onboard` | tenant; owner/super admin | Create/resume hosted connected-account onboarding |
| POST | `/gyms/{gym}/payment-gateways/stripe/refresh` | tenant; owner/super admin | Refresh verified provider capability state |
| POST | `/webhooks/stripe` | Stripe HMAC signature + narrow provider-account RLS resolution | Idempotently settle checkout and account events |
| POST | `/webhooks/stripe/billing` | separate Stripe Billing HMAC secret + narrow customer RLS resolution | Idempotently synchronize IronCore subscriptions, renewals, failures and invoices |
| GET/POST | `/platform/saas-plans` | authenticated `super_admin`; `platform.billing.manage` | List all tiers or create a Stripe-backed platform plan with its first immutable price |
| PATCH | `/platform/saas-plans/{plan}` | authenticated `super_admin`; `platform.billing.manage` | Update catalogue copy, feature limits, order or lifecycle status; prices remain immutable |
| POST | `/platform/saas-plans/{plan}/prices` | authenticated `super_admin`; `platform.billing.manage` | Add a new Stripe recurring price and deactivate the replaced catalogue price |
| GET | `/gyms/{gym}/saas-plans` | tenant; owner/manager/super admin | List active platform tiers and prices available to the selected gym |
| GET | `/gyms/{gym}/saas-subscription` | tenant; owner/manager/super admin | Read the selected gym's current snapshotted subscription and customer state |
| GET | `/gyms/{gym}/saas-billing-invoices` | tenant; owner/manager/super admin | List bounded recurring invoice history with provider-hosted document links |
| POST | `/gyms/{gym}/saas-subscription/checkout` | tenant; owner/super admin | Create or reuse an idempotent Stripe-hosted subscription Checkout session |
| POST | `/gyms/{gym}/saas-subscription/portal` | tenant; owner/super admin | Create a short-lived Stripe customer-portal session for payment methods, invoices, plan changes and cancellation |
| POST | `/gyms/{gym}/members/{member}/access-credential` | tenant; owner/manager/receptionist/super admin | Revoke the previous QR credential, issue a new opaque credential once and retain only its SHA-256 hash |
| GET/PATCH | `/gyms/{gym}/member/me` | tenant; linked member self | Read or update only the authenticated user's linked member profile; updates are limited to name/contact/date-of-birth fields |
| GET | `/gyms/{gym}/member/membership` | tenant; linked member self | Read the linked member's current membership with safe plan and branch summaries |
| GET | `/gyms/{gym}/member/invoices` | tenant; linked member self | List only the linked member's bounded invoice history |
| GET | `/gyms/{gym}/member/payments` | tenant; linked member self | List only the linked member's bounded payment history and safe refund evidence |
| GET | `/gyms/{gym}/member/attendance` | tenant; linked member self | Cursor-list at most 90 days of the linked member's own attendance history |
| GET/POST | `/gyms/{gym}/member/access-credential` | tenant; linked member self | Read safe pass metadata or rotate a one-time opaque QR value while retaining only its tenant-scoped SHA-256 digest |
| GET/POST | `/gyms/{gym}/member/data-exports` | tenant; linked member self | List or queue an export for the server-resolved linked member; no member UUID is accepted |
| GET | `/gyms/{gym}/member/data-exports/{export}[/download]` | tenant; linked member self | Read or download only the linked member's unexpired export |
| GET | `/gyms/{gym}/attendance` | tenant; owner/manager/receptionist/trainer/super admin | Cursor-list a bounded, time-filtered branch attendance history |
| POST | `/gyms/{gym}/attendance/check-ins` | tenant; owner/manager/receptionist/super admin | Admit an eligible member by secure QR credential, 4–6 digit member code or selected member ID; validate membership, branch and duplicate presence server-side |
| POST | `/gyms/{gym}/attendance/{attendance}/check-out` | tenant; owner/manager/receptionist/super admin | Close the selected tenant attendance row once |
| GET | `/gyms/{gym}/class-sessions` | tenant; all tenant roles | List a bounded date-window class schedule without cross-tenant attendee data |
| POST/PATCH | `/gyms/{gym}/class-sessions[/{session}]` | tenant; owner/manager/super admin | Create, update or reason-cancel a branch session |
| GET | `/gyms/{gym}/class-bookings` | tenant; staff roles; member restricted to linked self | List bounded bookings for authorised session operations or member self-service |
| GET | `/gyms/{gym}/class-sessions/{session}/bookings` | tenant; owner/manager/receptionist/assigned trainer/super admin | List a bounded roster and FIFO waitlist for one tenant-resolved session |
| POST | `/gyms/{gym}/class-sessions/{session}/bookings` | tenant; owner/manager/receptionist or linked member self | Book an eligible active member or place them on the FIFO waitlist under a row lock |
| POST | `/gyms/{gym}/class-bookings/{booking}/cancel` | tenant; owner/manager/receptionist or linked member self | Cancel one booking and atomically promote the earliest waitlisted member |
| POST | `/gyms/{gym}/class-bookings/{booking}/attend` | tenant; owner/manager/receptionist/assigned trainer/super admin | Mark a booked member attended and create/reuse their branch presence record |
| GET/POST | `/gyms/{gym}/trainer-assignments` | tenant; owners/managers/super admin manage; trainer/member reads server-filtered | List or create the explicit trainer/member coaching boundary |
| PATCH | `/gyms/{gym}/trainer-assignments/{assignment}/end` | tenant; owner/manager/super admin; mandatory reason | End access immediately while retaining immutable assignment history and audit evidence |
| GET/POST | `/gyms/{gym}/workout-plans` | tenant; owner/manager or assigned trainer writes; member linked-self read | List bounded plans or create a member plan with ordered exercises transactionally |
| GET/PATCH | `/gyms/{gym}/workout-plans/{plan}` | tenant; owner/manager/assigned trainer; member linked-self read only | Read a tenant-resolved plan or transition its controlled lifecycle |
| GET/POST | `/gyms/{gym}/workout-sessions` | tenant; owner/manager, assigned trainer or linked member self | Cursor-list member sessions or append one completed session with set evidence |
| GET/POST | `/gyms/{gym}/progress-measurements` | tenant; owner/manager, assigned trainer or linked member self | Cursor-list or append exact progress measurements |
| GET/PATCH | `/gyms/{gym}/notification-preferences` | tenant; linked member self; owner/manager read for support | Read defaults/current choices or update the member's own communication preferences |
| GET | `/gyms/{gym}/notification-deliveries` | tenant; owner/manager or linked member self | Cursor-list masked delivery history; never return encrypted destinations |
| GET | `/gyms/{gym}/reports/overview` | tenant; owner/manager/super admin; `reports` throttle | Return one bounded, currency-specific operational report for the explicitly selected gym |

## Role permission arrays

These arrays define the maximum product permissions. Controllers and policies may grant less, never more.

```text
super_admin = [platform.gyms.manage, platform.billing.manage, platform.audit.read,
               tenant.select, tenant.read, tenant.manage, member_exports.manage]
gym_owner   = [gym.read, gym.update, branches.manage, members.manage, members.import,
               member_accounts.manage, memberships.manage, plans.manage, staff.manage, payments.manage,
               saas_billing.read, saas_billing.manage, attendance.manage,
               classes.manage, bookings.manage, training.manage,
               progress.manage, notifications.read, reports.read, member_exports.manage, audit.read]
gym_manager = [gym.read, gym.update, branches.manage, members.manage, members.import,
               member_accounts.manage, memberships.manage, plans.manage, staff.manage, payments.record,
               saas_billing.read, attendance.manage, classes.manage,
               bookings.manage, training.manage, progress.manage,
               notifications.read, reports.read, member_exports.manage, audit.read]
receptionist = [gym.read, branches.read, members.read, members.create,
                members.update, members.import, member_accounts.manage,
                memberships.read, memberships.create,
                attendance.manage, classes.read, bookings.manage, payments.record]
trainer     = [gym.read, branches.read, members.assigned.read,
               training.manage, attendance.read, classes.assigned.read,
               classes.assigned.attendance, progress.assigned.manage]
member      = [self.read, self.update_limited, membership.self.read,
               payment.self.read, attendance.self.read, classes.read,
               booking.self.manage, training.self.read, training.self.log,
               progress.self.manage, notifications.self.manage, member_exports.self.manage]
```

### Permission invariants

- A tenant role is valid only within its own `(gym_id, user_id)` membership.
- `super_admin` is a platform role and must not be stored as a tenant pivot role.
- Role changes, manual payment changes, membership price changes and refunds require audit evidence.
- Receptionists and managers may record payments but cannot issue refunds or manage provider onboarding; only a gym owner or super admin may do so.
- Only `super_admin` manages the platform plan catalogue. A gym owner may start or manage its selected gym subscription; managers have read-only billing visibility.
- A valid Stripe signature and opaque billing-customer lookup are required before any asynchronous event can resolve a gym. Client or provider metadata never grants tenant access.
- SaaS subscription price and feature snapshots are immutable. Plan and price changes append new catalogue/contract history instead of rewriting earlier billing periods.
- Payment amounts, providers and methods are not edited in place. Corrections use reasoned refunds or future explicit void/replacement workflows so ledger history remains intact.
- Staff invitations cannot grant `super_admin`.
- Gym managers may grant only receptionist/trainer roles; owner/manager grants require a gym owner or super admin.
- Gym managers cannot update, suspend, demote or otherwise mutate an owner or another manager, including updates that omit the `role` field.
- Members may access only the member profile explicitly linked to their authenticated `user_id`.
- Member account invitations can be created only for an unlinked tenant member with a normalized email. Reissuing revokes the previous pending token; acceptance requires a matching unexpired digest and unchanged member email, then consumes the row under a database lock.
- A new activation creates a platform user only when no user owns the invited email. An existing account is linked without changing its password. Both paths create/update only the `member` role for the invitation gym and regenerate the authenticated web session.
- Password recovery returns the same accepted response for existing and unknown normalized emails. Reset tokens are one-time, broker-hashed at rest, expiry-bound and placed only in a frontend URL fragment; they never enter query strings, logs, analytics or browser persistence.
- Stateful login records the user's current `auth_version`. Every authenticated route compares that session value with the database value before tenant identity is bound; password reset or change advances the version so every stale Redis/database session fails closed on its next request.
- Password reset revokes all Sanctum tokens before creating the replacement session. Authenticated password change retains only the current context: it updates the current session generation or, for a bearer request, revokes every other personal access token.
- MFA is optional per platform identity and applies uniformly across super-admin, tenant staff and member roles. It never belongs to a gym and a tenant role cannot enable, disable or inspect another user's factor.
- TOTP uses RFC 6238 SHA-1 with a 160-bit secret, six digits, 30-second steps and a bounded ±1-step clock window. The user row is locked and `mfa_last_used_step` must advance, so concurrent requests cannot accept the same authenticator value twice.
- MFA setup requires the current password and does not become active until a valid code confirms the pending encrypted secret. Confirmation and disablement advance `auth_version`, revoke other browser sessions and bearer tokens, and retain only the current authenticated context.
- Recovery codes contain 80 random bits, are returned only once, stored as application-keyed HMAC digests and consumed under a row lock. Regeneration requires both the current password and a fresh TOTP code; disablement requires the password plus either a fresh TOTP or one recovery code.
- Correct primary credentials, password reset and existing-member activation never bypass an enabled factor. They return an opaque five-minute MFA challenge and establish no authenticated session until the second factor succeeds.
- Trainers may view rosters and mark attendance only for class sessions assigned to their tenant staff profile; they cannot create classes or book other members.
- Member booking reads and writes resolve the `members.user_id` link server-side. A client-supplied `member_id` never expands member self-service access.
- Check-in requires an active, in-date membership and branch compatibility. Submitted QR secrets are hashed before tenant-scoped lookup and never enter logs or audit values. The visible six-digit `member_code` is only a tenant-local manual lookup and never substitutes for secure QR validation.
- Session capacity, counters, waitlist sequence and FIFO promotion are updated only inside a database transaction holding a row lock on the tenant-resolved class session.
- Trainer training/progress access requires an active tenant assignment whose `trainer_staff_profile_id` belongs to the authenticated user. Owners/managers operate only inside the selected gym, and member access always resolves `members.user_id` server-side.
- Workout session and progress records are append-only. Exact loads use integer grams and measurements use integer thousandths plus controlled units, avoiding floating-point drift.
- Notification jobs re-establish the immutable gym context from their queue payload, never expose encrypted destinations, and apply tenant preferences before selecting a configured adapter.
- Operational reports are read-only and available only to gym owners, gym managers and super administrators after explicit tenant selection. Receptionists, trainers and members cannot read gym-wide aggregates.
- Report comparison windows have the same number of inclusive local-gym days as the requested period; division-by-zero changes return `null`, never fabricated growth.

## Web authentication and tenant-selection contract

- The signed-out web entry is always the real account login screen. Representative Super Admin, Gym Admin and Member previews are explicit secondary choices and never replace, prefill or grant an authenticated session.
- The Next.js web/PWA uses Sanctum's stateful cookie flow: it first requests `/sanctum/csrf-cookie`, sends `X-XSRF-TOKEN` on mutations and includes credentials on API requests.
- Production frontend and API hosts must share an HTTPS parent domain (or use a same-origin API proxy); production sets `SESSION_SECURE_COOKIE=true`, an appropriate shared `SESSION_DOMAIN` and an exact `SANCTUM_STATEFUL_DOMAINS`/CORS allowlist.
- The Laravel session identifier is regenerated after login and invalidated on logout. The session cookie remains encrypted and HttpOnly; bearer tokens are never written to `localStorage` or `sessionStorage`.
- Login, member activation and successful password reset copy the current server-side `auth_version` into the encrypted session. No client-provided version is trusted, and a stale or missing version is logged out before any tenant route runs.
- For an MFA-enabled identity, primary credential or recovery verification returns an opaque five-minute challenge instead of a user/session payload. The browser keeps it only in component memory, sends one authenticator/recovery value in the request body and clears the challenge on success, cancellation, navigation or reload.
- Authenticator enrollment returns a Base32 secret and `otpauth://` URI once for local QR rendering. The browser does not persist the secret or recovery codes; closing the setup result requires starting setup again with the current password.
- Recovery accepts only a normalized email and always renders generic acknowledgement. A reset fragment is copied into volatile component state and immediately removed from the address; passwords and tokens are never stored in local storage, session storage or URL query parameters.
- Bearer tokens remain available only as an explicit `use_bearer_token: true` path for future native clients and are scoped/revocable per device name.
- Authenticated users load `/auth/me` and the authorised `/gyms` collection. Super administrators always make an explicit gym selection before accessing tenant records; a non-platform user may be auto-selected only when exactly one active gym is available.
- An authenticated `super_admin` first enters the API-backed platform portal. Its tenant registry, explicit gym opening, gym onboarding and SaaS-plan publication use the existing `/gyms` and `/platform/saas-plans` APIs; it never uses representative platform totals as live records.
- Gym owners and managers enter the selected tenant portal according to their server-returned membership role. Linked members enter the dedicated self-service portal; the browser does not offer a role selector that could override `/auth/me`.
- Every operational web request carries the selected gym in both `/gyms/{gym}` and `X-Gym-ID`. Laravel independently verifies the authenticated role/membership and binds PostgreSQL RLS; matching client identifiers do not grant authority.
- Member search is server-side, prefix/index compatible and capped at 25 records per browser page in the current UI. The client ignores superseded responses to prevent an earlier tenant/search result replacing newer state.
- Branches, membership plans and memberships load as bounded tenant collections in parallel with independent stale-response guards.
- Authenticated navigation exposes only live members, branches, plans and memberships. Setup writes require super admin, owner or manager; receptionists may create memberships.
- Plan prices are parsed into integer minor units before transmission. Laravel remains authoritative for tenant ownership and immutable membership snapshots.
- Staff and pending invitations load only for super admins, owners and managers. Role options are filtered in the UI but the server remains authoritative; every profile update requires an audit reason.
- Invitation secrets are returned exactly once and encoded only in the URL fragment of the acceptance link. The fragment is not sent to servers/referrers, is never stored in browser storage, and is removed immediately after acceptance. Laravel binds the route gym into RLS before validating the token hash and invited email.
- Navigation is derived from the selected tenant role: owners/managers receive staff administration, receptionists receive member operations, and trainer/member access is limited to currently integrated read-safe modules.
- Finance navigation is available to owners, managers and receptionists. All tenant collections use independent stale-response guards and are cleared immediately when the active gym changes.
- Online checkout opens only a provider-hosted URL returned for the current payment. Cash and terminal-card recording never request card details; refunds require an explicit amount and reason.
- Gym subscription Checkout and customer-portal sessions open only Stripe-hosted URLs returned for the selected gym. Billing methods, tax IDs and card details never pass through or persist in IronCore.
- Subscription collections, invoices and customer state use independent stale-response guards and are cleared immediately when the active gym changes.
- Attendance, class sessions and booking collections use independent stale-response guards and are cleared immediately when the active gym changes. Class form wall-clock values are converted with the selected gym's IANA timezone, and staff/member schedules render in that gym timezone rather than the device timezone. QR/member-code inputs are held only long enough to submit one authenticated check-in request and are never written to browser storage. Reception camera scanning prefers a rear mobile camera, permits webcam/USB-camera selection, stops every media track on close and retains the numeric Member Code fallback when permission or QR detection is unavailable.
- Training plans, workout sessions, progress measurements and notification preferences use independent stale-response guards and clear immediately on logout or tenant switch. Staff-entered workout wall-clock values are converted with the selected gym's IANA timezone before persistence. The browser never decides trainer/member scope and never stores notification destinations or health/progress history in local storage.
- Reports use one independently guarded tenant request and clear immediately on logout or tenant switch. Date and currency filters are sent to Laravel, while all aggregation and scope decisions remain server-authoritative.
- `NEXT_PUBLIC_IRONCORE_DEMO_MODE=true` (or an absent public API origin) keeps the real login screen visible but disables account submission with a clear deployment notice. It also offers separately labelled representative previews; configured API mode exposes only authenticated live modules.
- Preview mode supplies isolated representative records to the same operational views while labelling them as read-only samples. Write controls, security controls and invitation issuance are hidden or disabled there. Authenticated mode constructs collections exclusively from bounded API responses and exposes a control only when it has a permitted backend action.
- Platform and gym-client portals use distinct landing views and role-aware navigation. The gym dashboard composes only collections already returned for the explicitly selected gym; its cards and navigation are presentational and never grant access or expand the server-authoritative permission scope.
- Linked members receive a dedicated mobile-first shell for their profile, current membership, billing history, own attendance, classes, training, progress, preferences and access pass. The server resolves every member identifier from the authenticated `user_id`; the client cannot choose or override that link.
- A newly rotated QR credential exists in component memory only, is returned once, and is cleared on navigation away from the pass, reload, logout or tenant change. No offline cache contains the credential plaintext.
- Member activation tokens arrive in the URL fragment, are copied into component memory and removed from the address immediately. Preview and acceptance send the opaque value only in stateful request bodies; no referrer, analytics event or browser persistence receives it.
- The install manifest provides standalone PWA presentation metadata only. IronCore deliberately defines no offline data cache until a separately reviewed encrypted/offline threat model exists.
- Representative preview mode may switch between the platform, gym-client and member shells for product review. The switch is labelled as a preview, does not persist tenant data, and is unavailable as an authorization mechanism in configured API mode.
- The production frontend is not ready for authenticated acceptance until its build receives a reviewed public HTTPS `NEXT_PUBLIC_IRONCORE_API_URL` and the Laravel API, PostgreSQL, Redis and queue services are reachable. The 18 August 2026 live audit found the deployed Vercel release still serving preview-only mode and the previously expected `api.ironcore.co.uk` hostname unresolved; this is a deployment blocker, not a browser fallback.

## Currency and money contract

- Supported ISO currencies: GBP, USD, PKR, AED and SAR.
- Each gym has one base currency; users may choose a display currency.
- Monetary amounts are stored as integer minor units plus ISO currency, never floating point.
- A membership stores its agreed price/currency snapshot; later plan price changes do not rewrite history.
- Online, cash, bank-transfer and manual-adjustment records share an auditable payment ledger.
- Stripe Connect uses direct charges on each gym's connected account; IronCore SaaS subscription billing remains commercially separate from member funds.
- IronCore SaaS subscriptions use Customers, recurring Prices, Checkout and the customer portal on the platform Stripe account; connected-account headers are never used for this money flow.
- Each accepted SaaS contract snapshots tier, features, amount, currency and interval. Provider webhooks, not checkout redirects, authorize active/trial access and dunning transitions.
- Signed Stripe events resolve an opaque connected account through a SELECT-only RLS policy, bind its gym, verify server-authored metadata and use `(gym_id, provider, event_id)` idempotency before updating a ledger record.

## Performance and scale contract

- UUID primary keys support distributed creation; high-volume list queries must use cursor pagination when offsets become expensive.
- Every tenant-owned high-volume index begins with `gym_id` unless it supports an explicitly documented global lookup.
- Member uniqueness is tenant-local, including `(gym_id, member_number)` and the reception lookup `(gym_id, member_code)`.
- Foreign-key lookup paths must also have tenant-compatible composite indexes.
- Search endpoints must cap page size and avoid unbounded wildcard scans.
- Imports, notifications, scheduled/large reports and exports run through Redis queues. Member export generation and expiry jobs carry immutable gym/export IDs, and export objects use tenant-prefixed private paths. The bounded interactive report overview may execute synchronously behind role checks, a 366-day cap, rate limiting and a tenant-keyed Redis cache.
- Cache keys follow `ironcore:gym:{gym_id}:{domain}:{key}` and never mix tenant data.
- Partitioning is introduced only from measured volume, initially by time for append-only payments, attendance and audit data.
- Attendance defaults to a bounded date window and cursor pagination. Branch/time and member/time indexes support reception dashboards without scanning a gym's complete history.
- Training/progress history defaults to bounded member/date filters and cursor pagination. Tenant/member/time and tenant/trainer/status indexes avoid unbounded coaching-dashboard scans.
- Report periods are capped at 366 days and use half-open timestamp ranges. The 60-second Redis cache key includes `gym_id`, date range, currency and report version; the named report limiter is keyed by authenticated user and selected gym.

## Deployment and operational hardening contract

- Production uses separate frontend, Laravel API, PostgreSQL 17, Redis and S3-compatible storage services; free frontend hosting is not a safe substitute for the stateful API/database/queue stack.
- HTTPS, exact CORS/Sanctum origins, secure cookies, trusted proxy configuration and environment-only secrets are mandatory before launch.
- Laravel web, queue worker and scheduler processes deploy from the same immutable backend release. Database migrations run once before traffic shifts; queue workers restart after a successful release.
- `/up` is the process-only liveness check. `/api/v1/health/readiness` verifies PostgreSQL and Redis connectivity, returns only `ready` or `unavailable`, logs no credentials/tenant data and is rate-limited to 60 requests per source IP per minute.
- Backups, point-in-time recovery, restore drills, provider webhook monitoring, failed-job alerts, centralised logs and error tracking are production launch gates.
- Load validation targets report cache behaviour, bounded query latency, authentication throttles and tenant isolation. Load scripts use synthetic tenant IDs/tokens supplied only through environment variables and never contain committed credentials.
- Pull requests and `main` pushes run two independent, read-only GitHub Actions jobs. The web job uses the committed npm lockfile and runs lint, type-checking, the secret scan, the production build/artifact validation before rendered-output contracts, all portable contracts and the production-dependency audit.
- The backend job uses PHP 8.3 with PostgreSQL 17, Redis 8 and a disposable LocalStack S3 service. It creates an ephemeral `ironcore_app` login with `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, `NOINHERIT` and `NOBYPASSRLS`, owns only the disposable test database, and fails rather than skipping when PostgreSQL RLS, Redis or S3 runtime requirements are absent.
- After the feature suite, the backend job inserts two fixed synthetic tenants through `ironcore_app`, creates a PostgreSQL 17 custom-format archive, restores it into a new disposable database and reconnects as `ironcore_app`. The drill fails unless every restored `gym_id` table retains RLS plus FORCE RLS, no-context reads fail closed, each selected tenant sees exactly its own fixture and an unrelated tenant sees none. A trap removes the archive, restored database and source fixtures on success or failure.
- After the restore drill, the backend job creates one 500-member report tenant, one isolated tenant and 16 ten-minute operator tokens under `ironcore_app`. Pinned k6 1.7.1 warms the 60-second Redis report cache and drives eight requests per second for 30 seconds against a 16-worker disposable Laravel server. The gate requires an unchanged cached payload, cross-tenant `403`, below 1% HTTP failures, p95 below 500 ms and p99 below 1,000 ms while retaining the production 30-per-minute user/gym limiter. The target and tokens exist only on the disposable runner.
- Auth generation, database identity, tenant membership and role authorization execute before implicit route-model binding. This ordering lets PostgreSQL RLS resolve tenant-owned records only after the connection security context exists and returns role denial before record lookup.
- After `ResolveTenant` validates route/header agreement and promotes the gym into `TenantContext`, it consumes the `{gym}` route parameter before controller dispatch. Controllers needing the selected gym read the trusted context, preventing Laravel's positional dispatcher from shifting nested member, staff, booking or export parameters.
- The committed Composer lockfile makes the Laravel 13 dependency graph deterministic. Hosted CI caches only Composer download archives under a lockfile-derived key, limits parallel provider requests and uses four finite prefetch attempts. The read-only workflow token exists only in a Composer child with plugins and scripts disabled; the activation child removes `COMPOSER_AUTH` and all GitHub token variables before Laravel or dependency code can execute. CI never updates the lockfile, caches `vendor` or receives a production credential. The non-secret test environment marker prevents missing-dotenv warnings without storing configuration or credentials.
- CI receives no production provider credentials. Its database passwords and generated `APP_KEY` are ephemeral test-only values; workflow permissions remain `contents: read`, third-party actions are pinned to reviewed full commit hashes, checkout credentials are not persisted, and fork pull requests receive no secrets.
- CodeQL advanced analysis scans both JavaScript/TypeScript application source and GitHub Actions workflows on pull requests, `main` pushes and a weekly schedule. It runs the `security-extended` query suite, checks out without persisted credentials and grants only the job-scoped `security-events: write` permission required to upload code-scanning results. PHP remains covered by parser validation, Laravel runtime tests, Composer audit and review because CodeQL does not support PHP.
- Each web build publishes the non-secret immutable Git commit as the `ironcore-release` metadata value. Vercel builds use `VERCEL_GIT_COMMIT_SHA`, GitHub builds use `GITHUB_SHA`, and another deployment platform must inject `IRONCORE_RELEASE_SHA`; local preview alone may use the explicit `development` fallback. A commit identifier is provenance, never an authorization or tenant input.
- The deployed-web smoke workflow runs on each `main` push, every six hours and by manual dispatch. It waits at most thirty minutes for the allowlisted HTTPS deployment to serve the triggering full Git SHA, then verifies a `200` HTML shell, HSTS, the reviewed title/platform markers, same-origin CSS and JavaScript, and the install manifest. Its target cannot contain credentials, query parameters or fragments and cannot be a local/private host literal. The job retains a separate 35-minute ceiling so the bounded propagation window cannot consume its final verification budget. The longer bound is evidence-based: both the former ten- and fifteen-minute push windows expired before Vercel later served the exact requested release.
- The deployed-web smoke is a public frontend availability/provenance gate only. It sends no production credential or tenant/member value and does not replace authenticated Laravel readiness, PostgreSQL/Redis monitoring, provider sandbox execution, load evidence or a backup restore drill.
- The object-storage runtime gate uses disposable non-secret credentials and an ephemeral S3-compatible emulator. It executes the production member-export and expiry jobs over HTTP, checks the private tenant-prefixed object, stored digest/size and byte deletion, and never contacts production storage. Provider encryption, bucket policy, lifecycle configuration and restore evidence remain deployment-environment gates.
- The synthetic database restore drill proves repository schema/data restoration mechanics and forced-RLS continuity without production data or credentials. It does not prove a provider's encrypted backup schedule, point-in-time recovery, retention, cross-region recovery, RPO/RTO or operational cutover; those remain deployment-environment gates.
- Before a production release runs migrations or receives traffic, `php artisan ironcore:production-preflight` must pass against Laravel's resolved configuration. The command fails closed on debug/non-production mode, an invalid application key, non-HTTPS public origins, unsafe cross-origin session/CORS/Sanctum settings, missing trusted proxies, a privileged/non-PostgreSQL runtime identity, non-Redis cache/session/queues, insecure database or Redis transport, non-private object storage configuration, missing Stripe signing secrets/callbacks, non-delivering mail, local-only logging or partially configured notification adapters.
- Production web builds must separately run `npm run preflight:production-web`. It rejects representative demo mode, missing/non-public HTTPS API origins and missing immutable full-SHA release identity. This build-time check receives public deployment metadata only and never accepts a backend or provider secret.
- Both preflights report only stable configuration names and requirements; they never print configured values. Passing them proves configuration shape only. Provider sandbox execution, service connectivity, provider backup/storage controls, monitored topology, privacy approval, branch protection and production capacity evidence remain separate launch gates.
- The hosted backend gate runs password recovery and tenant notification jobs through Redis against a disposable loopback-only SMTP/HTTPS transport. SMTP authentication, HTTPS bearer authorization, email/SMS/push payloads and provider IDs use synthetic CI-only values; no production provider or credential is contacted.
- The disposable SMTP boundary decodes each multipart text body according to its declared MIME transfer encoding and keeps both raw and decoded evidence only in authenticated runner memory. Test failures use stable assertions rather than printing reset values or message bodies into hosted logs.
- Notification adapters convert transport/provider exceptions into a stable generic exception without retaining the original exception chain. This prevents provider response bodies, endpoint details or destinations from entering failed-job evidence while preserving a retryable failure signal.
- The credential-free transport gate proves IronCore's queue and protocol boundaries only. Selected transactional email, SMS and push providers still require their own sandbox delivery, sender/domain approval, suppression handling, rate-limit, observability and production credential evidence before enablement.
- The hosted backend gate runs Stripe Connect and platform Billing operations against a disposable loopback-only HTTPS boundary using synthetic CI-only bearer and webhook-signing credentials. It proves the production request shapes, connected-account routing, idempotency headers, distinct money flows and signed webhook entry points without contacting Stripe or handling real payment data.
- Connect webhooks must resolve a gym only from the signed opaque connected-account identifier; Billing webhooks must use the separate endpoint secret and signed opaque platform-customer identifier. Server-authored metadata must match the resolved tenant before payment or subscription state can change, and replayed provider event IDs must remain idempotent.
- The credential-free Stripe gate proves IronCore's HTTP, signing and tenant-isolation boundaries only. Stripe test-mode onboarding, Checkout, refunds, customer portal, asynchronous event delivery, production webhook monitoring and live credentials remain explicit provider/deployment gates.

## Active feature status

| Milestone / feature | Status | Notes |
| --- | --- | --- |
| Milestone 1 — responsive super-admin interface | Complete | Representative browser data and automated UI contracts |
| Milestone 2 — Laravel API, authentication and base tenancy | Complete with hardening carried into M3 | Sanctum, gym CRUD, roles, audit foundation |
| MAD and repository enforcement | Active | Root `AGENTS.md` requires this document before source changes |
| PostgreSQL RLS and fail-closed tenancy | Passing locally and hosted | Commit `79ed6ae` passed 44 Laravel tests / 335 assertions under PostgreSQL 17 forced RLS and non-superuser `ironcore_app` |
| Branches, members, staff, invitations, plans and memberships API | Implemented; core runtime passing | Tenant composite FKs, RLS, validation, audit and capped pagination are active |
| Streaming member CSV imports | Implemented; PostgreSQL/Redis runtime passing | Private tenant paths, Redis job, 500-row inserts, bounded errors and progress counters |
| Secure web authentication and tenant selection | Implemented; core runtime passing | Stateful Sanctum/CSRF flow, session rotation, explicit super-admin tenant selection and no browser bearer storage |
| Real role entry and actionable frontend portals | Local business acceptance complete; production acceptance pending | Login, logout and recovery work for Super Admin, Gym Admin and Member accounts; permission-visible tenant/member writes use existing API methods; representative previews are explicit and read-only |
| Members frontend/API integration | Implemented; local acceptance passing | Tenant route/header agreement, capped server search, loading/error/empty states, creation, portal invitation and audited profile/lifecycle editing; demo preview remains isolated |
| Branch, plan and membership frontend/API integration | Implemented; local acceptance passing | Parallel bounded reads, role-aware creation and audited edits/status transitions, exact minor-unit prices and immutable accepted snapshots; isolated preview navigation renders representative rows |
| Staff and invitation frontend/API integration | Implemented; core runtime passing | Tenant directory, pending invitations, one-time acceptance links, hierarchy-safe edits and mandatory audit reasons |
| Milestone 3 — gym, member and staff operations | Feature-complete; core runtime passing | Browser/API contracts and GitHub-hosted Laravel/PostgreSQL/Redis tests pass |
| Tenant invoices and immutable payment/refund ledger | Implemented; core runtime passing | Server totals, cash/terminal/bank records, hosted online checkout, partial/full refunds and currency-specific summaries |
| Stripe Connect onboarding and signed webhooks | Implemented; provider sandbox gate pending | Direct charges, no card storage, HMAC verification, narrow account lookup and idempotent settlement |
| Payments frontend/API integration | Implemented; provider sandbox gate pending | Responsive finance workspace, role-aware actions, exact minor-unit entry and hosted checkout redirect |
| Platform SaaS plan catalogue and immutable prices | Implemented; core runtime passing; provider gate pending | Platform-owned tiers, five supported currencies, monthly/yearly prices and Stripe product/price references |
| Tenant-isolated gym subscriptions and SaaS invoices | Implemented; core runtime passing; provider gate pending | Separate platform Stripe customer, one open Checkout, customer portal, snapshots, dunning and invoice history |
| Stripe Billing signed webhook synchronization | Implemented; core runtime passing; provider gate pending | Separate endpoint secret, verified customer lookup, tenant RLS binding, event deduplication and payload hashing |
| Platform SaaS subscription frontend/API integration | Implemented; core runtime passing; provider gate pending | Super-admin catalogue management plus owner checkout/portal and manager read-only status |
| Milestone 4 — payments and platform SaaS billing | Feature-complete; provider sandbox gate pending | Core runtime, static contracts, production build and responsive browser QA pass; live Stripe execution remains gated |
| Member QR credentials, Member Codes and branch attendance | Implemented locally; core runtime passing | Separate tenant-unique six-digit lookup, one-time opaque/hash-only QR security, camera scanning, active-membership/branch validation and one open presence row |
| Class sessions, capacity-safe bookings and FIFO waitlists | Implemented; core runtime passing | Row-locked counters, retained cancellation history, member self restrictions and assigned-trainer attendance |
| Attendance and class-booking frontend/API integration | Implemented; core runtime passing | Check-in console, one-time QR rendering, live presence, schedule, rosters, booking and waitlist actions; attendance columns now use the responsive shared table contract |
| Milestone 5A — attendance, classes and bookings | Feature-complete; core runtime passing | Static contracts, production build, type-check, browser QA and GitHub-hosted runtime pass |
| Trainer assignments and member workout plans | Implemented; core runtime passing | Explicit active-assignment boundary, ordered prescriptions, partial uniqueness and controlled plan lifecycle |
| Append-only workout sessions and progress measurements | Implemented; core runtime passing | Exact integer load/measurement storage, cursor history and member/trainer scope |
| Redis-queued email, SMS and push notification adapters | Implemented; Redis runtime passing; provider gate pending | Encrypted destinations, safe payload variables, preferences, idempotency, quiet hours and tenant-context jobs |
| Training/progress frontend/API integration | Implemented; core runtime passing | Browser-verified plans, exercise logging, progress history, delivery evidence and member-controlled preferences |
| Milestone 5B — training, progress and notifications | Feature-complete; provider gate pending | Core runtime, static contracts, production build, type-check and browser QA pass; live adapters remain gated |
| Tenant operational reporting API and workspace | Implemented; core runtime and synthetic load gate passing | Bounded 366-day aggregates, currency separation, tenant cache keys, comparison periods, management access and responsive browser UI |
| Security, load and deployment preparation | Implemented; synthetic load gate passing; production deployment gate pending | Named throttles, readiness, credential-free k6 regression baseline, secret scan, runbook and launch checklist |
| Milestone 6A — reporting and operational hardening | Feature-complete; synthetic load gate passing; deployment gate pending | Core runtime plus static/build/render contracts, type-check, lint, artifact validation, secret scan and browser QA pass |
| Milestone 6B — dedicated gym-client portal | Feature-complete; core runtime passing | Role-separated shell and selected-gym dashboard using already tenant-scoped responses; 41 contracts, build, type-check, lint, artifact/secret validation and browser interaction QA pass |
| Linked member self-service API and least-privilege resources | Implemented; core runtime passing | Authenticated user link resolved server-side for profile, membership, invoices, payments, bounded attendance and safe QR metadata/rotation |
| Milestone 7 — linked-member self-service portal | Feature-complete; core runtime passing | Dedicated responsive member shell and install manifest; 43 contracts, production build, type-check, lint, artifact validation, secret scan and browser interaction QA pass |
| Secure member account invitation and activation | Implemented; core runtime passing | Tenant-scoped invitation history, hash-only one-time tokens, atomic user/member linking and immediate member-session entry |
| Milestone 8 — secure member account activation | Feature-complete; core runtime passing | Controlled owner/manager/receptionist invitation workflow; 46 contracts, production build, type-check, lint, artifact validation, secret scan and browser interaction QA pass |
| Account recovery, password change and credential revocation | Implemented; core runtime passing; mail gate pending | Non-enumerating queued recovery, fragment-only reset secrets, strong replacement passwords, row-locked credential rotation and monotonic session invalidation |
| Milestone 9 — account security and recovery | Feature-complete; mail sandbox gate pending | Core runtime plus 50 contracts, production build, type-check, lint, artifact validation, secret scan and browser interaction QA pass |
| Optional TOTP MFA and one-time recovery codes | Implemented; core runtime passing | Platform-owned encrypted secrets, non-replayed TOTP steps, HMAC-only recovery-code storage and short-lived Redis login challenges |
| Milestone 10 — multi-factor authentication | Feature-complete; core runtime passing | Login, password-reset and existing-member activation entry paths require the second factor; 54 contracts, production build, type-check, lint, artifact validation, secret scan and browser interaction QA pass |
| Milestone 11 — production CI runtime gate | Complete on commit `79ed6ae` | Both hosted jobs pass; PostgreSQL 17/Redis 8, forced RLS and Laravel production caches are verified |
| Secure member data exports | Implemented; S3-compatible CI gate passing | Staff and linked-member requests, tenant-bound queued generation, private S3-compatible JSON, integrity digest, authenticated no-store download and seven-day byte expiry |
| Milestone 12 — member data export lifecycle | Implementation complete; S3 runtime gate passing | The production generation and purge jobs passed the hosted HTTP object-storage gate; erasure remains pending launch-country retention approval because immutable financial/audit evidence may require preservation |
| Milestone 13 — hosted runtime-gate repair | Complete on commit `79ed6ae` | Both GitHub jobs pass; nested tenant route dispatch, PostgreSQL reporting, stateful auth, MFA audit RLS and deterministic dependency/test boot contracts are verified |
| Milestone 14 — CodeQL application-security analysis | Complete on commit `2ddc641` | Both JavaScript/TypeScript and GitHub Actions analyses passed with pinned CodeQL v4, `security-extended` queries and least-privilege permissions |
| Milestone 15 — deployed-web release verification | Complete on commit `45cf343` | Quality, CodeQL and deployment smoke all pass; Vercel serves the exact commit and the platform, gym and member previews pass live interaction QA without application-origin errors |
| Milestone 16 — S3-compatible export runtime gate | Complete on commit `7033e2b` | Quality, CodeQL and deployed-web checks pass; the backend lane executes production export generation, private retrieval, integrity evidence and expiry deletion without production credentials |
| Milestone 17 — synthetic PostgreSQL backup/restore drill | Complete on commit `b5bb2d0` | Quality, CodeQL and deployed-web checks pass; the restored database retains least-privilege identity, FORCE RLS, fail-closed reads and cross-tenant denial |
| Milestone 18 — synthetic cached-report performance gate | Complete on commit `066a4d6` | Hosted quality passed both jobs; pinned k6 validated cached payload identity, p95/p99 latency and cross-tenant denial with 500 synthetic members and expiring CI-only tokens |
| Post-Milestone 18 deployed-release propagation hardening | Implemented; hosted re-verification pending | Push smokes timed out at former ten- and fifteen-minute ceilings before Vercel later served each exact release; the evidence-based bounded wait is now thirty minutes inside a 35-minute job |
| Milestone 19 — production configuration preflight | Implementation complete; web/security/deployed checks passing; backend rerun required | The backend job reached a third-party package-download HTTP 429 before tests; no IronCore assertion failed. Fail-closed secret-safe Laravel and web preflights gate resolved production settings before migrations, traffic or a live web build |
| Milestone 20 — notification transport runtime gate | Implementation complete; web/security/deployed checks passing; hosted backend stopped before tests | Disposable authenticated SMTP and HTTPS boundaries exercise password recovery plus tenant email/SMS/push jobs through Redis, deny cross-tenant payloads and sanitize provider failures without production credentials |
| Milestone 21 — Stripe transport runtime gate | Implementation complete; web/security/deployed checks passing; hosted backend stopped before tests | Disposable HTTPS Stripe boundary exercises Connect and platform Billing requests, distinct signed webhooks, idempotency and tenant denial without provider credentials or real payment data |
| Milestone 22 — hosted dependency-install resilience | Complete; hosted retry still stopped before tests | Lockfile-keyed Composer download caching, reduced parallel HTTP pressure and four bounded retries preserved the locked graph but did not clear the repeated GitHub download limit |
| Milestone 23 — credential-isolated Composer prefetch | Complete; hosted prefetch passed | The read-only workflow token was isolated to bounded no-plugin/no-script package prefetches and stripped before normal Composer/Laravel activation; hosted package download completed, then package discovery exposed a separate early-bootstrap defect |
| Milestone 24 — bootstrap-safe trusted proxy configuration | Complete; hosted package discovery passed | Trusted proxy values moved to Laravel's request-time `trustedproxy` configuration; hosted dependency activation and package discovery succeeded, then the suite exposed a separate raw-MIME notification assertion defect |
| Milestone 25 — MIME-aware SMTP runtime evidence | Implementation complete locally; hosted re-verification pending | The disposable provider decodes quoted-printable/Base64 text parts independently in authenticated runner memory; reset-link and tenant-email assertions remain semantic and secret-safe without changing production mail behavior |
| Milestone 26 — real role entry and frontend action audit | Committed on `84c0b21`; invite preview fix committed on `fcf9a6c` | Added the API-backed Super Admin portal, made real login the signed-out entry, removed or gated placeholder controls, and added role/action contracts. Local end-to-end business acceptance is complete; production acceptance still requires a reachable configured Laravel API |
| Post-Milestone 26 — local business acceptance | Complete on commit `3ddeda2` | Exercised realistic Super Admin, Gym Admin, trainer and Member journeys with two isolated fake gyms. Fixed branch creation response defaults, UTC/IANA timezone handling, invalid report ranges, member workout/progress form resets, trainer booking visibility, core audited edit/status flows and SQLite test migration portability. Production provider/deployment acceptance remains separate. |
| Post-Milestone 26 — Member Code and camera QR check-in | Implemented locally; approval pending | Adds tenant-unique six-digit Member Codes, digital-card display, manual reception lookup, polished rear-camera/webcam/USB scanning with permission/fallback handling, and backend tests for valid, expired, wrong-gym, wrong-branch and duplicate check-ins. |

## Change control

Every architecture-affecting change must update the MAD in the same commit as the implementation. A change is architecture-affecting when it adds or changes a table, relationship, endpoint, permission, tenant boundary, external provider, queue, cache key family or active branch/milestone status. When implementation and this document disagree, stop work and reconcile them before continuing.
