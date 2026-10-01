import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("trial expiry reuses the existing billing scheduler and exposes clear frontend states", async () => {
  const [consoleRoutes, service, middleware, app, dashboard, billing, api] = await Promise.all([
    read("backend/routes/console.php"),
    read("backend/app/Services/AutomatedSaasBillingService.php"),
    read("backend/app/Http/Middleware/EnforceTenantBillingAccess.php"),
    read("app/ironcore-app.tsx"),
    read("app/ironcore-dashboard.tsx"),
    read("app/saas-billing-management.tsx"),
    read("app/lib/ironcore-api.ts"),
  ]);

  assert.match(consoleRoutes, /ironcore:saas-billing/);
  assert.equal((consoleRoutes.match(/ironcore:saas-billing/g) ?? []).length, 1);
  assert.match(consoleRoutes, /hourlyAt\(5\)/);
  assert.match(service, /saas_trial_ending/);
  assert.match(service, /saas_trial_expired/);
  assert.match(service, /trial_ends_at \?\? \$gym->trial_ends_at/);
  assert.match(middleware, /reason.*trial_expired/s);
  assert.match(api, /ironcore:billing-restricted/);
  assert.match(app, /tenantViews: View\[\] = billingRestricted/);
  assert.match(dashboard, /Your IronCore trial ends tomorrow/);
  assert.match(billing, /Payment required/);
  assert.match(billing, /gym_manager/);
});

test("trial notifications are subscription scoped without requiring an invoice", async () => {
  const [migration, model, job] = await Promise.all([
    read("backend/database/migrations/2026_10_01_000043_allow_subscription_scoped_saas_notifications.php"),
    read("backend/app/Models/SaasBillingNotification.php"),
    read("backend/app/Jobs/SendSaasBillingReminder.php"),
  ]);

  assert.match(migration, /gym_subscription_id.*nullable/);
  assert.match(migration, /saas_billing_invoice_id.*nullable\(\)->change/);
  assert.match(migration, /saas_notifications_subscription_idx/);
  assert.match(model, /function subscription/);
  assert.match(job, /subscription_active/);
  assert.match(job, /Your IronCore trial ends tomorrow/);
});
