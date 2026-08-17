# IronCore notification transport runtime gate

Milestone 20 adds a disposable provider boundary to the existing hosted backend quality job. It proves IronCore's queue, SMTP and HTTPS adapter behaviour without contacting a production or third-party account.

## What the gate executes

- A loopback-only Node process accepts authenticated SMTP and certificate-verified HTTPS requests.
- The certificate and private key are generated inside the disposable runner and are never committed.
- Synthetic credentials protect SMTP, SMS, push and the test-only evidence endpoint.
- Password recovery is requested through the public non-enumerating API, consumed from Redis and delivered through SMTP with its fragment-only reset link.
- Tenant email, SMS and push deliveries are queued through Redis, processed with the immutable `gym_id`, and checked for exact protocol payloads and provider IDs.
- A mismatched gym/delivery payload must fail closed before any provider request.
- A marker-bearing provider rejection must become a generic exception with no original exception chain, endpoint, destination, credential or response marker in retained delivery evidence.

The provider process keeps synthetic request evidence only in memory, binds only `127.0.0.1`, emits no message payloads to logs and is stopped on success or failure.

## Evidence boundary

This gate proves the production code crosses authenticated SMTP, TLS verification, HTTPS bearer authorization and Redis queue boundaries. It does not prove deliverability, sender/domain reputation, selected-provider suppression or rate-limit behaviour, regional routing, production alert delivery or live credential validity. Those remain required sandbox and deployment evidence.
