import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { join } from "node:path";

const root = process.cwd();
const read = (path) => readFile(join(root, path), "utf8");

test("SaaS publication persists before any optional Stripe synchronization", async () => {
  const service = await read("backend/app/Services/SaasBillingService.php");
  const createPlan = service.slice(service.indexOf("public function createPlan"), service.indexOf("public function updatePlan"));
  assert.doesNotMatch(createPlan, /createProductAndPrice|createPriceForPlan/);
  assert.match(createPlan, /SaasPlan::query\(\)->create/);
  assert.match(createPlan, /provider_product_id' => null/);
  assert.match(service, /assertCheckoutAvailable/);
  assert.match(service, /ensureStripePrice/);
});

test("cash and bank SaaS payments have a separate tenant ledger and review boundary", async () => {
  const [migration, model, routes, controller, platformBank] = await Promise.all([
    read("backend/database/migrations/2026_08_24_000031_add_optional_saas_payment_methods.php"),
    read("backend/app/Models/SaasSubscriptionPayment.php"),
    read("backend/routes/api.php"),
    read("backend/app/Http/Controllers/Api/V1/SaasSubscriptionController.php"),
    read("backend/config/platform_billing.php"),
  ]);
  assert.match(migration, /Schema::create\('saas_subscription_payments'/);
  assert.match(migration, /FORCE ROW LEVEL SECURITY/);
  assert.match(migration, /\['gym_id', 'status', 'created_at'\]/);
  assert.match(model, /use BelongsToGym, HasUuids/);
  assert.match(routes, /saas-subscription\/manual-payments/);
  assert.match(routes, /saas-subscription\/manual-invoice/);
  assert.match(routes, /middleware\('role:super_admin'\)/);
  assert.match(controller, /manualPaymentReceipt/);
  assert.match(controller, /platform_bank_details/);
  assert.match(platformBank, /SAAS_BANK_ACCOUNT_NUMBER_OR_IBAN/);
});

test("the live SaaS UI offers cash and bank while showing unavailable Stripe", async () => {
  const [portal, billing, api, app] = await Promise.all([
    read("app/platform-portal.tsx"),
    read("app/saas-billing-management.tsx"),
    read("app/lib/ironcore-api.ts"),
    read("app/ironcore-app.tsx"),
  ]);
  assert.match(portal, /name="bank_transfer"[\s\S]*defaultChecked/);
  assert.match(portal, /name="cash"[\s\S]*defaultChecked/);
  assert.match(billing, /Stripe · Not configured/);
  assert.match(billing, /Submit for review/);
  assert.match(billing, /This payment is for your IronCore SaaS subscription/);
  assert.match(billing, /onPrepareManualInvoice/);
  assert.match(billing, /Manual payment reviews/);
  assert.match(billing, /\["paid", "partially_refunded", "refunded"\]\.includes\(payment\.status\)/);
  assert.match(api, /saasSubscriptionPayments/);
  assert.match(api, /reviewSaasSubscriptionPayment/);
  assert.match(api, /prepareSaasSubscriptionInvoice/);
  assert.match(app, /Promise\.allSettled/);
});

test("automated SaaS lifecycle and platform intelligence remain tenant-safe", async () => {
  const [migration, scheduler, lifecycle, routes, insights, ui, api, dashboard] = await Promise.all([
    read("backend/database/migrations/2026_09_08_000038_add_automated_saas_billing_lifecycle.php"),
    read("backend/routes/console.php"),
    read("backend/app/Services/AutomatedSaasBillingService.php"),
    read("backend/routes/api.php"),
    read("backend/app/Services/PlatformInsightsService.php"),
    read("app/platform-insights.tsx"),
    read("app/lib/ironcore-api.ts"),
    read("app/ironcore-dashboard.tsx"),
  ]);

  assert.match(migration, /Schema::create\('saas_payment_refunds'/);
  assert.match(migration, /Schema::create\('saas_billing_notifications'/);
  assert.match(migration, /FORCE ROW LEVEL SECURITY/);
  assert.match(migration, /\['gym_id', 'status', 'created_at'\]/);
  assert.match(scheduler, /Schedule::command\('ironcore:saas-billing'\)->dailyAt\('\d{2}:\d{2}'\)->withoutOverlapping/);
  assert.match(lifecycle, /grace_period_days/);
  assert.match(lifecycle, /billing_restricted_at/);
  assert.match(lifecycle, /idempotency_key/);
  assert.match(lifecycle, /timezone/);
  assert.match(routes, /platform\/member-directory/);
  assert.match(routes, /platform\/billing/);
  assert.match(routes, /platform\/analytics/);
  assert.match(insights, /tenant->run/);
  assert.match(insights, /memberFacets/);
  assert.match(ui, /Select filtered results/);
  assert.match(ui, /SaaS billing dashboard/);
  assert.match(ui, /Payment review queue/);
  assert.match(ui, /Monthly SaaS revenue/);
  assert.match(api, /platformMembersExport/);
  assert.match(dashboard, /session-billing-reminder/);
  assert.match(dashboard, /Grace period remaining/);
});

test("SaaS financial corrections preserve original history and remain Super Admin controlled", async () => {
  const [migration, service, routes, tenantUi, platformUi, planUi] = await Promise.all([
    read("backend/database/migrations/2026_09_21_000041_add_saas_payment_approval_reversals.php"),
    read("backend/app/Services/SaasBillingService.php"),
    read("backend/routes/api.php"),
    read("app/saas-billing-management.tsx"),
    read("app/platform-insights.tsx"),
    read("app/platform-portal.tsx"),
  ]);
  assert.match(migration, /saas_payment_approval_reversals/);
  assert.match(migration, /FORCE ROW LEVEL SECURITY/);
  assert.match(migration, /saas_approval_reversal_payment_unique/);
  assert.match(service, /public function replaceInvoice/);
  assert.match(service, /public function reverseManualPaymentApproval/);
  assert.match(service, /lockForUpdate/);
  assert.match(service, /saas\.subscription_payment\.approval_reversed/);
  assert.match(routes, /approval-reversal/);
  assert.match(routes, /saas-billing-invoices\/\{invoice\}\/replace/);
  assert.match(routes, /middleware\('role:super_admin'\)/);
  assert.match(tenantUi, /Pay by bank transfer/);
  assert.match(tenantUi, /Pay by cash/);
  assert.match(tenantUi, /Reverse approval/);
  assert.match(tenantUi, /Correct \/ replace/);
  assert.match(platformUi, /Use only when no money was received/);
  assert.match(planUi, /Archived plans/);
  assert.match(planUi, /Delete unused draft/);
  assert.match(planUi, /Location limit/);
});
