# MIME-aware SMTP runtime evidence

Milestone 25 addresses the only failure in the approved Milestone 24 backend run. Dependency activation and Laravel package discovery succeeded, and 60 tests passed. The remaining notification-runtime assertion treated the complete multipart SMTP message as one quoted-printable body, so its evidence lookup depended on an incorrect MIME assumption.

## Runtime contract

- The disposable SMTP provider keeps each synthetic message only in authenticated runner memory.
- Folded multipart boundaries are parsed recursively, and each `text/*` part is decoded from its own declared quoted-printable, Base64, 7-bit, 8-bit or binary transfer encoding.
- Password-recovery evidence must still contain the encoded account email and reset-token field in the URL fragment after MIME decoding.
- Tenant notification evidence must still contain the exact expected body after crossing Redis and authenticated SMTP.
- Stable boolean assertion messages prevent a failure from printing reset values or complete email content.

The decoder belongs only to the credential-free CI provider. It adds no production dependency and changes no password-reset URL, mail template, queue, tenant boundary or delivery adapter.

## Evidence boundary

Portable tests execute a folded multipart fixture containing both a soft-wrapped quoted-printable reset link and a Base64 HTML part. Only an approved commit/push can re-run the complete hosted PostgreSQL/Redis/S3/provider/restore/load authority and confirm the production-generated Laravel message over the Linux SMTP boundary.
