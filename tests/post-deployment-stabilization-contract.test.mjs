import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const platform = readFileSync("app/platform-portal.tsx", "utf8");
const billing = readFileSync("app/saas-billing-management.tsx", "utf8");
const dashboard = readFileSync("app/ironcore-dashboard.tsx", "utf8");
const css = readFileSync("app/globals.css", "utf8");
const api = readFileSync("app/lib/ironcore-api.ts", "utf8");

test("gym owner onboarding and one-time credentials have explicit accessible controls", () => {
  assert.match(platform, /Gym Owner Account/);
  assert.match(platform, /Send secure invite/);
  assert.match(platform, /Set temporary password/);
  assert.match(platform, /Shown once only/);
  assert.match(platform, /Dismiss temporary password/);
  assert.match(platform, /Password change required/);
  assert.match(css, /one-time-secret-panel/);
});

test("gym lifecycle and audit history are visible Super Admin workflows", () => {
  assert.match(platform, /Audit Log/);
  assert.match(platform, /Suspend access/);
  assert.match(platform, /Reactivate/);
  assert.match(platform, /Archive gym/);
  assert.match(platform, /Permanently delete test gym/);
  assert.match(api, /platformAuditLog/);
  assert.match(api, /deleteGym/);
});

test("SaaS corrections, branded receipts and exports retain original history", () => {
  assert.match(billing, /The original transaction remains unchanged/);
  assert.match(billing, /Record correction/);
  assert.match(billing, /IronCore receipt/);
  assert.match(billing, /\["csv", "xlsx", "pdf"\]/);
  assert.match(api, /correctSaasSubscriptionPayment/);
});

test("activation link component has distinct content, controls and dismiss regions", () => {
  assert.match(dashboard, /activation-link-content/);
  assert.match(dashboard, /activation-link-controls/);
  assert.match(dashboard, /activation-link-dismiss/);
  assert.match(css, /activation-link-controls/);
});
