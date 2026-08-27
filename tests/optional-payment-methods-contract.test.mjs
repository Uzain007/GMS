import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("membership finance keeps Stripe optional and exposes reviewed bank transfers", async () => {
  const finance = await read("app/financial-management.tsx");
  const member = await read("app/member-portal.tsx");
  const client = await read("app/lib/ironcore-api.ts");
  const app = await read("app/ironcore-app.tsx");

  assert.match(finance, /Stripe · Not configured/);
  assert.match(finance, /Submit for review/);
  assert.match(finance, /onReviewBankTransfer/);
  assert.match(finance, /Payment receipt/);
  assert.match(member, /Submit receipt/);
  assert.match(member, /Pay securely with Stripe/);
  assert.match(member, /Cash can be recorded safely by reception/);
  assert.match(client, /member\/payment-options/);
  assert.match(client, /bank-transfer-review/);
  assert.match(client, /member\/payments\/\$\{encodeURIComponent\(paymentId\)\}\/receipt/);
  assert.match(app, /api\.memberPaymentOptions/);
  assert.match(app, /api\.reviewBankTransfer/);
});

test("web payment statuses use business language", async () => {
  const client = await read("app/lib/ironcore-api.ts");
  const finance = await read("app/financial-management.tsx");
  const repair = await read("backend/database/migrations/2026_08_24_000030_finish_tenant_payment_status_normalization.php");

  assert.match(client, /status: "pending" \| "paid" \| "rejected"/);
  assert.match(finance, /\["paid", "partially_refunded"\]/);
  assert.doesNotMatch(finance, /status: "pending" \| "succeeded"/);
  assert.match(repair, /foreach \(DB::table\('gyms'\).*pluck\('id'\) as \$gymId\)/s);
  assert.match(repair, /set_config\('ironcore\.current_gym_id'/);
  assert.match(repair, /where\('status', 'succeeded'\).*\['status' => 'paid'\]/s);
});
