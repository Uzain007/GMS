import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

const workflow = await read(".github/workflows/quality.yml");
const sandbox = await read("scripts/ci/stripe-provider-sandbox.mjs");
const runtimeTest = await read(
  "backend/tests/Feature/MilestoneTwentyOneStripeProviderRuntimeTest.php",
);
const gateway = await read("backend/app/Services/StripeGatewayService.php");
const billing = await read("backend/app/Services/StripePlatformBillingService.php");
const exception = await read("backend/app/Services/StripeProviderException.php");

test("hosted backend starts a credential-free HTTPS Stripe boundary", () => {
  assert.match(workflow, /IRONCORE_STRIPE_RUNTIME_GATE: "true"/);
  assert.match(workflow, /STRIPE_API_URL: https:\/\/127\.0\.0\.1:8444/);
  assert.match(workflow, /STRIPE_CA_BUNDLE: \/tmp\/ironcore-stripe-runtime-cert\.pem/);
  assert.match(workflow, /Start disposable Stripe provider boundary/);
  assert.match(workflow, /node scripts\/ci\/stripe-provider-sandbox\.mjs/);
  assert.match(workflow, /Stop disposable Stripe provider boundary/);
  assert.match(
    workflow,
    /rm -f --[\s\S]*IRONCORE_STRIPE_RUNTIME_CERTIFICATE[\s\S]*IRONCORE_STRIPE_RUNTIME_PRIVATE_KEY/,
  );
  assert.doesNotMatch(workflow, /secrets\.[A-Za-z0-9_]+/);
  const start = workflow.indexOf("Start disposable Stripe provider boundary");
  const suite = workflow.indexOf("php artisan test --fail-on-skipped --fail-on-risky");
  const stop = workflow.indexOf("Stop disposable Stripe provider boundary");
  assert.ok(start >= 0 && start < suite && suite < stop);
});

test("disposable Stripe boundary is loopback-only, authenticated and evidence-safe", () => {
  assert.match(sandbox, /const host = "127\.0\.0\.1"/);
  assert.match(sandbox, /https\.createServer/);
  assert.match(sandbox, /equalSecret\(bearer\(request\), providerToken\)/);
  assert.match(sandbox, /equalSecret\(bearer\(request\), evidenceToken\)/);
  assert.match(sandbox, /"cache-control": "no-store"/);
  assert.match(sandbox, /\/v1\/accounts/);
  assert.match(sandbox, /\/v1\/checkout\/sessions/);
  assert.match(sandbox, /\/v1\/refunds/);
  assert.match(sandbox, /\/v1\/billing_portal\/sessions/);
  assert.match(sandbox, /\/v1\/subscriptions\//);
  assert.doesNotMatch(sandbox, /0\.0\.0\.0/);
});

test("runtime coverage separates connected payments from platform subscriptions", () => {
  assert.match(runtimeTest, /StripeGatewayService::class\)->startOnboarding/);
  assert.match(runtimeTest, /PaymentService::class\)->create/);
  assert.match(runtimeTest, /PaymentService::class\)->refund/);
  assert.match(runtimeTest, /StripePlatformBillingService::class\)->createProductAndPrice/);
  assert.match(runtimeTest, /SaasBillingService::class\)->startCheckout/);
  assert.match(runtimeTest, /SaasBillingService::class\)->createPortal/);
  assert.match(runtimeTest, /assertSame\(\$gateway->provider_account_id, \$memberCheckout\['stripe_account'\]\)/);
  assert.match(runtimeTest, /assertNull\(\$saasCheckout\['stripe_account'\]\)/);
  assert.match(runtimeTest, /assertSame\('payment:'\.\$payment->id/);
  assert.match(runtimeTest, /assertSame\('saas-checkout:'\.\$gym->id/);
});

test("signed webhooks prove replay safety, separate secrets and tenant denial", () => {
  assert.match(runtimeTest, /duplicate' => false/);
  assert.match(runtimeTest, /duplicate' => true/);
  assert.match(runtimeTest, /StripeWebhookService::class\)->process/);
  assert.match(runtimeTest, /StripeBillingWebhookService::class\)->process/);
  assert.match(runtimeTest, /Stripe payment metadata does not match the resolved tenant/);
  assert.match(runtimeTest, /Stripe subscription checkout metadata does not match the resolved tenant/);
  assert.match(runtimeTest, /services\.stripe\.billing_webhook_secret/);
  assert.match(runtimeTest, /services\.stripe\.webhook_secret/);
  assert.match(runtimeTest, /assertSame\(PaymentStatus::Pending/);
  assert.match(runtimeTest, /assertSame\(0, GymSubscription::query\(\)->count\(\)\)/);
});

test("Stripe transport keeps TLS verification and strips provider exception chains", () => {
  for (const service of [gateway, billing]) {
    assert.match(service, /services\.stripe\.ca_bundle/);
    assert.match(service, /withOptions\(\['verify' => \$caBundle\]\)/);
    assert.match(service, /catch \(Throwable\)/);
    assert.match(service, /StripeProviderException::rejected\(\)/);
    assert.doesNotMatch(service, /'verify' => false/);
  }
  assert.match(exception, /must not reach ledger or failed-job evidence/);
  assert.match(exception, /return new self\('The Stripe provider rejected the request\.'\)/);
  assert.doesNotMatch(exception, /previous:/);
  assert.match(runtimeTest, /assertNull\(\$exception->getPrevious\(\)\)/);
  assert.match(runtimeTest, /runtime_gate\.rejection_marker/);
  assert.match(runtimeTest, /RefundStatus::Failed/);
});
