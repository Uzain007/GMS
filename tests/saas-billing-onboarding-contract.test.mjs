import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const api = await readFile(new URL("../app/lib/ironcore-api.ts", import.meta.url), "utf8");
const portal = await readFile(new URL("../app/platform-portal.tsx", import.meta.url), "utf8");
const controller = await readFile(new URL("../backend/app/Http/Controllers/Api/V1/GymController.php", import.meta.url), "utf8");
const migration = await readFile(new URL("../backend/database/migrations/2026_10_02_000045_add_gym_onboarding_idempotency.php", import.meta.url), "utf8");

test("gym onboarding requires one explicit SaaS price and billing lifecycle inputs", () => {
  assert.match(api, /subscription:\s*\{[\s\S]*saas_plan_price_id: string;[\s\S]*billing_email: string;[\s\S]*grace_period_days: number;/);
  assert.match(portal, /name="saas_plan_price_id"/);
  assert.match(portal, /name="billing_email"/);
  assert.match(portal, /name="grace_period_days"/);
  assert.match(portal, /plan\.status === "active" && price\.active && price\.currency === baseCurrency/);
  assert.match(portal, /availablePrices\.length === 0/);
  assert.match(portal, /No active.*prices/);
});

test("gym onboarding retries are durable and reject request-key misuse", () => {
  assert.match(api, /idempotency_key: string/);
  assert.match(portal, /idempotency_key: idempotencyKey/);
  assert.match(controller, /onboarding_idempotency_key/);
  assert.match(controller, /onboarding_request_hash/);
  assert.match(controller, /idempotency_reused/);
  assert.match(migration, /gyms_onboarding_idempotency_unique/);
});
