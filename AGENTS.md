# IronCore repository instructions

1. Read `MASTER_ARCHITECTURE_DOC.md` completely before proposing or changing source code.
2. Treat the MAD as the repository SSOT. If it is absent, stale or conflicts with implementation, stop and reconcile it first.
3. Every tenant-owned table must have a non-null `gym_id` foreign key, tenant-leading indexes and PostgreSQL RLS. Every tenant-owned model must use the shared fail-closed tenant concern.
4. Never trust a client-supplied tenant identifier by itself. Tenant API routes require authentication, tenant resolution, membership verification and a role/policy decision.
5. Super admins must select an explicit tenant for tenant-owned records. Ordinary domain code must never disable tenant scoping.
6. Add concise technical comments explaining tenant-isolation and scale-sensitive choices in generated migrations, middleware, repositories, services and tests.
7. Use integer minor units with ISO currency for money. Never overwrite historical transaction or membership price snapshots.
8. Add isolation, authorization, validation and index-contract tests for every tenant feature. Run the complete quality gate after each milestone.
9. Update the MAD's schema, relationships, endpoints, permissions and active feature status in the same change as the implementation.
10. Do not commit credentials, payment keys, production data, generated dependency folders or environment secrets.
