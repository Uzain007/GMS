# Member data exports

IronCore produces member-access exports asynchronously so large histories do not block API workers. An owner, manager, super administrator, or the linked member can request an export only after normal authentication, tenant resolution, forced PostgreSQL RLS, and role authorization have succeeded.

The Redis job carries immutable gym/export identifiers, establishes its own tenant context, and writes one JSON document under `gyms/{gym_id}/exports/members/` on the configured private S3-compatible disk. The API never exposes the object key. An authenticated download response uses `private, no-store`, and the export metadata includes a SHA-256 digest for integrity evidence.

Exports expire after seven days. A tenant-bound delayed job deletes the private object and retains only request/audit metadata. Object-storage lifecycle policy must independently enforce the same or shorter retention as defence in depth.

The export includes the member profile and bounded domain records currently held by IronCore. Encrypted notification destinations are deliberately excluded from the generated document. The workflow does not implement erasure: deletion and pseudonymisation must first reconcile statutory financial/audit retention and launch-country requirements approved by counsel or the accountable privacy owner.

Before production, verify queue retries, S3 encryption/private ACLs, lifecycle rules, download authorization, audit visibility, a representative large export, and deletion of expired bytes in the target environment.
