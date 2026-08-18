# Stripe transport runtime gate

Milestone 21 extends the existing hosted backend lane with a disposable HTTPS process bound only to `127.0.0.1`. The workflow creates a one-run certificate and private key, configures Laravel to trust only that generated certificate for the synthetic Stripe endpoint, and destroys the files after the test step.

The runtime test exercises production Stripe code for connected-account onboarding and refresh, direct-charge member Checkout, refunds, platform product and price creation, platform customers, SaaS subscription Checkout, customer portal creation and subscription retrieval. Synthetic request evidence proves member money carries the selected `Stripe-Account` header while IronCore SaaS money never does; both paths retain their server-authored idempotency and tenant metadata.

Connect and Billing webhook requests use separate synthetic signing secrets. Valid events must resolve one gym from the signed opaque account or customer, require metadata to match that resolved tenant, update only tenant-scoped records and treat a replayed event ID as a duplicate. Cross-tenant metadata and a signature from the other endpoint must fail closed.

Provider HTTP failures become a stable `StripeProviderException` without the original exception chain. This prevents provider response bodies, endpoint details, credentials and payment references from entering ledger or failed-job evidence while retaining a retryable failure signal.

This gate contacts no Stripe service, uses no card/bank data and proves no real payment or delivery. Before launch, the selected Stripe test-mode environment must separately prove onboarding, Checkout, refunds, portal actions, asynchronous events, rate limits, account capabilities, webhook monitoring and production credential handling.
